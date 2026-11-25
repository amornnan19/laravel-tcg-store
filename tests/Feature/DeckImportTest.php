<?php

use App\Actions\AddDeckToCart;
use App\Actions\ParseDeckList;
use App\Actions\SetRestockAlerts;
use App\Enums\CardCondition;
use App\Enums\DeckBucket;
use App\Enums\DeckLineKind;
use App\Enums\DeckMatchedBy;
use App\Enums\Finish;
use App\Enums\Language;
use App\Exceptions\AlertRefused;
use App\Exceptions\DeckNotReserved;
use App\Exceptions\FuzzySearchUnavailable;
use App\Livewire\Storefront\DeckImport;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Game;
use App\Models\Printing;
use App\Models\RestockAlert;
use App\Models\SavedDeckList;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Variant;
use App\Search\FuzzyCardMatcher;
use App\Search\MeilisearchFuzzyCardMatcher;
use App\Search\QueryParser;
use App\Support\Deck\DeckBuckets;
use App\Support\Deck\DeckLine;
use App\Support\Deck\DeckMatch;
use App\Support\Deck\DeckParse;
use App\Support\Deck\NameKey;
use Database\Seeders\SavedDeckListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A fuzzy matcher that answers from a fixed table, `name => [card id, score]`, and records what it was asked.
 */
function fakeFuzzyMatcher(array $answers = [], bool $throws = false): object
{
    $matcher = new class($answers, $throws) implements FuzzyCardMatcher
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private array $answers, private bool $throws) {}

        public function match(Game $game, string $name): ?array
        {
            $this->asked[] = $name;

            if ($this->throws) {
                throw new FuzzySearchUnavailable('down');
            }

            $answer = $this->answers[$name] ?? null;

            return $answer === null ? null : ['card_id' => $answer[0], 'score' => $answer[1]];
        }
    };

    app()->instance(FuzzyCardMatcher::class, $matcher);

    return $matcher;
}

/**
 * One Piece with set OP-05 (Luffy 119 in EN and JA, Zoro 120), and Pokemon with set OBF (Charizard ex 125).
 *
 * @return array<string, mixed>
 */
function deckShop(): array
{
    fakeFuzzyMatcher();

    $onePiece = Game::factory()->create(['slug' => 'one-piece', 'name_en' => 'One Piece', 'sort' => 1]);
    $pokemon = Game::factory()->create(['slug' => 'pokemon', 'name_en' => 'Pokemon', 'sort' => 2]);
    $op05 = CardSet::factory()->for($onePiece, 'game')->create(['code' => 'OP-05']);
    $obf = CardSet::factory()->for($pokemon, 'game')->create(['code' => 'OBF']);

    $luffy = Card::factory()->for($onePiece, 'game')->create(['name_en' => 'Monkey D. Luffy', 'name_ja' => 'モンキー・D・ルフィ', 'name_th' => 'ลูฟี่']);
    $zoro = Card::factory()->for($onePiece, 'game')->create(['name_en' => 'Roronoa Zoro']);
    $charizard = Card::factory()->for($pokemon, 'game')->create(['name_en' => 'Charizard ex']);

    $luffyEn = Printing::factory()->for($luffy)->for($op05, 'set')->create(['number' => '119', 'language' => Language::En]);
    $luffyJa = Printing::factory()->for($luffy)->for($op05, 'set')->create(['number' => '119', 'language' => Language::Ja]);
    $zoroEn = Printing::factory()->for($zoro)->for($op05, 'set')->create(['number' => '120', 'language' => Language::En]);
    $charizardEn = Printing::factory()->for($charizard)->for($obf, 'set')->create(['number' => '125', 'language' => Language::En]);

    return compact('onePiece', 'pokemon', 'op05', 'obf', 'luffy', 'zoro', 'charizard', 'luffyEn', 'luffyJa', 'zoroEn', 'charizardEn');
}

function deckVariant(Printing $printing, int $qty, ?int $priceSatang = 10000, CardCondition $condition = CardCondition::Nm, Finish $finish = Finish::Normal): Variant
{
    return Variant::factory()->for($printing)->create(['qty' => $qty, 'price_satang' => $priceSatang, 'condition' => $condition, 'finish' => $finish]);
}

function parseDeck(Game $game, string $raw, ?Language $language = null): DeckParse
{
    return app(ParseDeckList::class)->handle($game, $raw, $language);
}

function deckLine(DeckParse $parse, int $number): DeckLine
{
    return collect($parse->lines)->firstWhere('number', $number);
}

test('every documented line format reads the same card and quantity', function (string $line, int $qty) {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 9);

    $parsed = deckLine(parseDeck($shop['onePiece'], $line), 1);

    expect($parsed->kind)->toBe(DeckLineKind::Matched)
        ->and($parsed->qty)->toBe($qty)
        ->and($parsed->printingIds)->toContain($shop['luffyEn']->id);
})->with([
    'qty x code name' => ['4x OP05-119 Luffy', 4],
    'qty code' => ['4 OP05-119', 4],
    'qty x name' => ['4x Monkey D. Luffy', 4],
    'code x qty' => ['OP05-119 x4', 4],
    'spaced multiplier' => ['4 x OP05-119', 4],
    'no quantity' => ['OP05-119', 1],
]);

test('a PTCG Live line reads its set code and number from the end of the line', function () {
    $shop = deckShop();
    deckVariant($shop['charizardEn'], 4);

    $line = deckLine(parseDeck($shop['pokemon'], '3 Charizard ex OBF 125'), 1);

    expect($line->matchedBy)->toBe(DeckMatchedBy::Code)
        ->and($line->qty)->toBe(3)
        ->and($line->printingIds)->toBe([$shop['charizardEn']->id]);
});

test('section headers come back ignored with their text, never dropped', function () {
    $shop = deckShop();

    $parse = parseDeck($shop['pokemon'], "Pokémon: 12\nTrainer: 30\n// my deck\n# note\nLeader\nMain Deck\nDon!! Deck\n3 Charizard ex OBF 125");

    expect(collect($parse->lines)->pluck('kind')->map->value->all())->toBe([...array_fill(0, 7, 'ignored'), 'matched'])
        ->and(collect($parse->unread())->pluck('text')->all())->toBe(['Pokémon: 12', 'Trainer: 30', '// my deck', '# note', 'Leader', 'Main Deck', 'Don!! Deck']);
});

test('a Set line declares the set for the bare card numbers after it', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4);

    $parse = parseDeck($shop['onePiece'], "Set: OP05\n4 119\n2 120");

    expect(deckLine($parse, 1)->kind)->toBe(DeckLineKind::Directive)
        ->and(deckLine($parse, 2)->matchedBy)->toBe(DeckMatchedBy::DeclaredSet)
        ->and(deckLine($parse, 2)->printingIds)->toContain($shop['luffyEn']->id)
        ->and(deckLine($parse, 2)->qty)->toBe(4)
        ->and(deckLine($parse, 3)->printingIds)->toBe([$shop['zoroEn']->id]);
});

test('a bare number without a declared set is unmatched', function () {
    $shop = deckShop();

    expect(deckLine(parseDeck($shop['onePiece'], '4 119'), 1)->kind)->toBe(DeckLineKind::Unmatched);
});

test('blank lines are skipped but still count in line numbers', function () {
    $shop = deckShop();

    $parse = parseDeck($shop['onePiece'], "\n\n   \nnonsense\n");

    expect($parse->lines)->toHaveCount(1)
        ->and($parse->lines[0]->number)->toBe(4);
});

test('a quantity over the cap is read as the cap and the line says so', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 500);

    $line = deckLine(parseDeck($shop['onePiece'], '150x OP05-119'), 1);

    expect($line->qty)->toBe(99)
        ->and($line->qtyCapped)->toBeTrue()
        ->and(deckLine(parseDeck($shop['onePiece'], '99x OP05-119'), 1)->qtyCapped)->toBeFalse();
});

test('an unmatched line keeps its original text and line number', function () {
    $shop = deckShop();

    deckVariant($shop['luffyEn'], 4);

    $parse = parseDeck($shop['onePiece'], "OP05-119\n  4x Nobody Here  ");

    $line = deckLine($parse, 2);

    expect($line->kind)->toBe(DeckLineKind::Unmatched)
        ->and($line->text)->toBe('4x Nobody Here')
        ->and($line->bucket)->toBe(DeckBucket::Missing)
        ->and($parse->counts()['missing'])->toBe(1);
});

test('a code wins over a name', function () {
    $shop = deckShop();
    deckVariant($shop['zoroEn'], 4);

    // A card whose name is itself a code: the code step reads it as Zoro's card, the name step would find this one.
    $decoy = Card::factory()->for($shop['onePiece'], 'game')->create(['name_en' => 'OP05-120']);
    $decoyPrinting = Printing::factory()->for($decoy)->for($shop['op05'], 'set')->create(['number' => '300']);
    deckVariant($decoyPrinting, 4);

    $line = deckLine(parseDeck($shop['onePiece'], '2 OP05-120'), 1);

    expect($line->matchedBy)->toBe(DeckMatchedBy::Code)
        ->and($line->printingIds)->toBe([$shop['zoroEn']->id]);
});

test('an exact name matches in each of the three languages, ignoring case and spacing', function (string $name) {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4);

    $line = deckLine(parseDeck($shop['onePiece'], "2x {$name}"), 1);

    expect($line->matchedBy)->toBe(DeckMatchedBy::Name)
        ->and($line->printingIds)->toContain($shop['luffyEn']->id, $shop['luffyJa']->id);
})->with(['english' => ['monkey  d.  LUFFY'], 'japanese' => ['モンキー・D・ルフィ'], 'thai' => ['ลูฟี่']]);

test('another game\'s card never matches, by code or by name', function () {
    $shop = deckShop();
    deckVariant($shop['charizardEn'], 4);

    $parse = parseDeck($shop['onePiece'], "OBF 125\nCharizard ex");

    expect(deckLine($parse, 1)->kind)->toBe(DeckLineKind::Unmatched)
        ->and(deckLine($parse, 2)->kind)->toBe(DeckLineKind::Unmatched);
});

test('a hidden printing never matches: a trashed card or an inactive game', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4);
    deckVariant($shop['zoroEn'], 4);
    $shop['zoro']->delete();

    $trashed = parseDeck($shop['onePiece'], "OP05-120\nRoronoa Zoro");

    expect(deckLine($trashed, 1)->kind)->toBe(DeckLineKind::Unmatched)
        ->and(deckLine($trashed, 2)->kind)->toBe(DeckLineKind::Unmatched);

    $shop['onePiece']->update(['is_active' => false]);

    $inactive = parseDeck($shop['onePiece'], "OP05-119\nMonkey D. Luffy");

    expect(deckLine($inactive, 1)->kind)->toBe(DeckLineKind::Unmatched)
        ->and(deckLine($inactive, 2)->kind)->toBe(DeckLineKind::Unmatched);
});

test('a fuzzy match is accepted at the threshold and refused below it', function () {
    $shop = deckShop();
    deckVariant($shop['zoroEn'], 4);
    config()->set('deck.fuzzy_min_score', 0.9);
    fakeFuzzyMatcher(['Roronoa Zorro' => [$shop['zoro']->id, 0.9], 'Rorona Zoru' => [$shop['zoro']->id, 0.89]]);

    $parse = parseDeck($shop['onePiece'], "Roronoa Zorro\nRorona Zoru");

    expect(deckLine($parse, 1)->matchedBy)->toBe(DeckMatchedBy::Fuzzy)
        ->and(deckLine($parse, 1)->isFuzzy())->toBeTrue()
        ->and(deckLine($parse, 2)->kind)->toBe(DeckLineKind::Unmatched);
});

test('a fuzzy hit on a card without a visible printing stays unmatched', function () {
    $shop = deckShop();
    deckVariant($shop['zoroEn'], 4);
    $shop['zoro']->delete();
    fakeFuzzyMatcher(['Roronoa Zorro' => [$shop['zoro']->id, 0.99]]);

    expect(deckLine(parseDeck($shop['onePiece'], 'Roronoa Zorro'), 1)->kind)->toBe(DeckLineKind::Unmatched);
});

test('fuzzy matching runs only for lines still unmatched, and for at most the configured number', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4);
    config()->set('deck.fuzzy_max_lines', 2);
    $matcher = fakeFuzzyMatcher();

    parseDeck($shop['onePiece'], "OP05-119\nMonkey D. Luffy\nnope one\nnope two\nnope three");

    expect($matcher->asked)->toBe(['nope one', 'nope two']);
});

test('an engine error leaves the line unmatched, and the rest of the list is not looked up', function () {
    $shop = deckShop();
    $matcher = fakeFuzzyMatcher(throws: true);

    $parse = parseDeck($shop['onePiece'], "nope one\nnope two\nnope three");

    expect(collect($parse->lines)->pluck('kind')->unique()->all())->toBe([DeckLineKind::Unmatched])
        ->and($matcher->asked)->toBe(['nope one']);
});

/**
 * Binds the real matcher to a fake engine that returns `$hits` for every search and counts the searches.
 */
function engineReturning(array $hits): ArrayObject
{
    $seen = new ArrayObject(['searches' => 0, 'options' => null, 'wheres' => null, 'limit' => null]);

    app()->bind(FuzzyCardMatcher::class, MeilisearchFuzzyCardMatcher::class);
    app(EngineManager::class)->extend('hits', fn () => new class($seen, $hits) extends NullEngine
    {
        public function __construct(private ArrayObject $seen, private array $hits) {}

        public function search(Builder $builder): array
        {
            $this->seen['searches']++;
            $this->seen['options'] = $builder->options;
            $this->seen['wheres'] = $builder->wheres;
            $this->seen['limit'] = $builder->limit;

            return ['hits' => $this->hits];
        }
    });
    config()->set('scout.driver', 'hits');

    return $seen;
}

function hit(int $cardId, float $score, string $nameEn, ?string $nameJa = null, ?string $nameTh = null): array
{
    return ['card_id' => $cardId, '_rankingScore' => $score, 'name_en' => $nameEn, 'name_ja' => $nameJa, 'name_th' => $nameTh];
}

test('the engine matcher asks for several hits with the ranking score within the game', function () {
    $shop = deckShop();
    $seen = engineReturning([hit(77, 0.95, 'Roronoa Zorro')]);

    $match = app(FuzzyCardMatcher::class)->match($shop['onePiece'], 'Roronoa Zoro');

    expect($match)->toBe(['card_id' => 77, 'score' => 0.95])
        ->and($seen['options'])->toBe(['showRankingScore' => true])
        ->and($seen['limit'])->toBe(5)
        ->and($seen['wheres'][0]['value'])->toBe('one-piece');
});

test('a high-scoring hit whose name is not close to the query is refused', function (string $query, array $hit) {
    $shop = deckShop();
    engineReturning([$hit]);

    expect(app(FuzzyCardMatcher::class)->match($shop['onePiece'], $query))->toBeNull();
})->with([
    'one-word prefix' => ['Zoro', fn () => hit(5, 0.98, 'Zoro-Juurou')],
    'short prefix' => ['ex', fn () => hit(5, 0.98, 'Exeggcute')],
    'placeholder score' => ['Ball', fn () => hit(5, 1.0, 'Big Air Balloon')],
]);

test('a typo of a real name is accepted, in any of the three names', function (string $query, array $hit) {
    $shop = deckShop();
    engineReturning([$hit]);

    expect(app(FuzzyCardMatcher::class)->match($shop['onePiece'], $query))->toBe(['card_id' => 5, 'score' => $hit['_rankingScore']]);
})->with([
    'english' => ['Charizrd ex', fn () => hit(5, 0.83, 'Charizard ex')],
    'thai' => ['มังกรดา', fn () => hit(5, 0.7, 'Dragon', null, 'มังกรดำ')],
    'japanese' => ['モンキー・D・ルフイ', fn () => hit(5, 0.7, 'Luffy', 'モンキー・D・ルフィ')],
]);

test('the best of the hits wins, and a hit below the ranking floor is skipped', function () {
    $shop = deckShop();
    engineReturning([hit(1, 0.4, 'Charizard ex'), hit(2, 0.8, 'Charizard x'), hit(3, 0.6, 'Charizard ex')]);

    expect(app(FuzzyCardMatcher::class)->match($shop['onePiece'], 'Charizard ex'))->toBe(['card_id' => 3, 'score' => 0.6]);
});

test('a query without a letter or digit never reaches the engine', function (string $query) {
    $shop = deckShop();
    $seen = engineReturning([hit(5, 1.0, 'Anything')]);

    expect(app(FuzzyCardMatcher::class)->match($shop['onePiece'], $query))->toBeNull()
        ->and($seen['searches'])->toBe(0);
})->with(['dashes' => '-----', 'stars' => '***', 'dots' => '..', 'katakana dot' => '・', 'space' => ' ']);

test('a punctuation-only line is parsed without a search', function () {
    $shop = deckShop();
    $seen = engineReturning([hit(5, 1.0, 'Anything')]);

    $line = deckLine(parseDeck($shop['onePiece'], '4x -----'), 1);

    expect($line->kind)->toBe(DeckLineKind::Unmatched)
        ->and($seen['searches'])->toBe(0);
});

test('a line of only no-break or ideographic spaces is blank', function () {
    $shop = deckShop();

    $parse = parseDeck($shop['onePiece'], "\u{00A0}\n\u{3000}\u{3000}\n \u{00A0} \nnonsense");

    expect($parse->lines)->toHaveCount(1)
        ->and($parse->lines[0]->number)->toBe(4);
});

test('a Thai digit is not a quantity and zero is unreadable, with the reason', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4);

    $thai = deckLine(parseDeck($shop['onePiece'], '๔ OP05-119'), 1);
    $zero = deckLine(parseDeck($shop['onePiece'], '0 OP05-119'), 1);

    expect($thai->qty)->toBe(1)
        ->and($zero->kind)->toBe(DeckLineKind::Unmatched)
        ->and($zero->qtyZero)->toBeTrue()
        ->and($zero->qty)->toBe(0)
        ->and($zero->text)->toBe('0 OP05-119');

    Livewire::test(DeckImport::class)->set('raw', '0x OP05-119')->call('check')->assertSee(__('storefront.deck.zero_qty'));
});

test('a Thai name with sara am matches, because names are folded with NFC and not NFKC', function () {
    $shop = deckShop();
    $dragon = Card::factory()->for($shop['onePiece'], 'game')->create(['name_en' => 'Black Dragon', 'name_th' => "\u{0E21}\u{0E31}\u{0E07}\u{0E01}\u{0E23}\u{0E14}\u{0E33}"]);
    deckVariant(Printing::factory()->for($dragon)->for($shop['op05'], 'set')->create(['number' => '301']), 4);

    $line = deckLine(parseDeck($shop['onePiece'], "2x \u{0E21}\u{0E31}\u{0E07}\u{0E01}\u{0E23}\u{0E14}\u{0E33}"), 1);

    expect($line->matchedBy)->toBe(DeckMatchedBy::Name)
        ->and($line->qty)->toBe(2)
        ->and($line->matchedName)->toBe('Black Dragon');
});

test('full-width letters and digits in a line are folded', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4);

    $line = deckLine(parseDeck($shop['onePiece'], '４ｘ ＯＰ０５－１１９'), 1);

    expect($line->qty)->toBe(4)
        ->and($line->printingIds)->toContain($shop['luffyEn']->id);
});

test('the cheapest variant that covers the quantity is the default for a full line', function () {
    $shop = deckShop();
    $dear = deckVariant($shop['luffyEn'], 8, 30000);
    $cheap = deckVariant($shop['luffyEn'], 4, 10000, CardCondition::Lp);
    deckVariant($shop['luffyEn'], 1, 500, CardCondition::Mp);

    $line = deckLine(parseDeck($shop['onePiece'], '4x OP05-119'), 1);

    expect($line->bucket)->toBe(DeckBucket::Full)
        ->and($line->defaultVariantId)->toBe($cheap->id)
        ->and($dear->id)->not->toBe($line->defaultVariantId);
});

test('a line with more quantity than stock lands in the partial bucket', function () {
    $shop = deckShop();
    $few = deckVariant($shop['luffyEn'], 2, 20000);
    deckVariant($shop['luffyEn'], 1, 10000, CardCondition::Lp);

    $parse = parseDeck($shop['onePiece'], '4x OP05-119');
    $line = deckLine($parse, 1);

    expect($line->bucket)->toBe(DeckBucket::Partial)
        ->and($line->defaultVariantId)->toBe($few->id)
        ->and($line->takePartial)->toBeTrue()
        ->and($line->addQty())->toBe(2)
        ->and($parse->counts())->toBe(['full' => 0, 'partial' => 1, 'missing' => 0])
        ->and($parse->totalCards())->toBe(2)
        ->and($parse->totalSatang())->toBe(40000);
});

test('the partial default has the most available, cheapest on a tie', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 2, 30000, CardCondition::Lp);
    $cheapTie = deckVariant($shop['luffyEn'], 2, 20000, CardCondition::Mp);
    deckVariant($shop['luffyEn'], 1, 100, CardCondition::Hp);

    expect(deckLine(parseDeck($shop['onePiece'], '4x OP05-119'), 1)->defaultVariantId)->toBe($cheapTie->id);
});

test('a line whose every variant is reserved by other carts is missing', function () {
    $shop = deckShop();
    $variant = deckVariant($shop['luffyEn'], 3);
    StockReservation::factory()->create(['purchasable_id' => $variant->id, 'purchasable_type' => 'variant', 'qty' => 3, 'expires_at' => now()->addMinutes(10)]);

    $line = deckLine(parseDeck($shop['onePiece'], '1x OP05-119'), 1);

    expect($line->bucket)->toBe(DeckBucket::Missing)
        ->and($line->kind)->toBe(DeckLineKind::Matched)
        ->and($line->variants)->toHaveCount(1)
        ->and($line->defaultVariantId)->toBeNull();
});

test('a matched line with no priced variant has no candidates at all', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 5, null);

    $line = deckLine(parseDeck($shop['onePiece'], 'OP05-119'), 1);

    expect($line->bucket)->toBe(DeckBucket::Missing)
        ->and($line->variants)->toBe([])
        ->and($line->matchedName)->toBe('Monkey D. Luffy');
});

test('the language preference narrows the candidates', function () {
    $shop = deckShop();
    $en = deckVariant($shop['luffyEn'], 4, 30000);
    $ja = deckVariant($shop['luffyJa'], 4, 10000);

    $any = deckLine(parseDeck($shop['onePiece'], 'OP05-119'), 1);
    $onlyEn = deckLine(parseDeck($shop['onePiece'], 'OP05-119', Language::En), 1);

    expect($any->defaultVariantId)->toBe($ja->id)
        ->and(array_keys($onlyEn->variants))->toBe([$en->id])
        ->and($onlyEn->defaultVariantId)->toBe($en->id);
});

test('assigning another variant recalculates the bucket', function () {
    $shop = deckShop();
    $cheap = deckVariant($shop['luffyEn'], 4, 10000);
    $few = deckVariant($shop['luffyEn'], 1, 20000, CardCondition::Lp);

    $line = deckLine(parseDeck($shop['onePiece'], '4x OP05-119'), 1);

    expect(DeckBuckets::assign($line, $few->id)->bucket)->toBe(DeckBucket::Partial)
        ->and(DeckBuckets::assign($line, $few->id)->defaultVariantId)->toBe($few->id)
        ->and(DeckBuckets::assign($line, 999999)->defaultVariantId)->toBe($cheap->id);
});

test('parsing a long list stays within a fixed number of queries', function () {
    $shop = deckShop();
    $lines = [];

    foreach (range(1, 12) as $i) {
        $card = Card::factory()->for($shop['onePiece'], 'game')->create(['name_en' => "Filler {$i}"]);
        $printing = Printing::factory()->for($card)->for($shop['op05'], 'set')->create(['number' => (string) (200 + $i)]);
        deckVariant($printing, 6);
        $lines[] = '2x OP05-'.(200 + $i);
        $lines[] = "2x Filler {$i}";
        $lines[] = 'OP05-'.(200 + $i).' x2';
        $lines[] = "Nobody {$i}";
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $parse = parseDeck($shop['onePiece'], implode("\n", $lines));

    expect($parse->lines)->toHaveCount(48)
        ->and($parse->counts()['full'])->toBe(36)
        ->and($queries)->toBeLessThan(40);
});

test('the deck page renders for a guest', function () {
    deckShop();

    $this->get(route('deck.import'))
        ->assertOk()
        ->assertSee(__('storefront.deck.title'))
        ->assertSee(__('storefront.deck.hint'));
});

test('checking a list shows the summary and the three buckets', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);
    deckVariant($shop['zoroEn'], 1, 20000);

    Livewire::test(DeckImport::class)
        ->set('raw', "Leader\n4x OP05-119\n3x OP05-120\nmystery card")
        ->call('check')
        ->assertSet('checked', true)
        ->assertDispatched('deck-checked')
        ->assertSee(__('storefront.deck.cards_line', ['have' => 5, 'want' => 8, 'short' => 3]))
        ->assertSee(__('storefront.deck.unreadable'))
        ->assertSee(__('storefront.deck.unread_title'))
        ->assertSee('mystery card');
});

test('the first active game is the default and a game that is not active is refused', function () {
    $shop = deckShop();
    $shop['onePiece']->update(['is_active' => false]);

    Livewire::test(DeckImport::class)
        ->assertSet('gameId', $shop['pokemon']->id)
        ->call('selectGame', $shop['onePiece']->id)
        ->assertHasErrors(['gameId'])
        ->assertSet('gameId', $shop['pokemon']->id);
});

test('an empty list and a list that is too long are refused with their messages', function (string $raw, string $message) {
    deckShop();

    Livewire::test(DeckImport::class)
        ->set('raw', $raw)
        ->call('check')
        ->assertHasErrors(['raw'])
        ->assertSee($message)
        ->assertSet('checked', false);
})->with([
    'empty' => ['   ', fn () => __('storefront.deck.errors.empty')],
    'too many lines' => [fn () => implode("\n", array_fill(0, 201, 'OP05-119')), fn () => __('storefront.deck.errors.too_long', ['lines' => 200])],
    'too many characters' => [fn () => str_repeat('a', 10001), fn () => __('storefront.deck.errors.too_long', ['lines' => 200])],
]);

test('exactly the maximum number of lines is accepted', function () {
    deckShop();

    Livewire::test(DeckImport::class)
        ->set('raw', implode("\n", array_fill(0, 200, 'OP05-119')))
        ->call('check')
        ->assertHasNoErrors();
});

test('choosing a variant recalculates the line and a variant that is not a candidate is refused', function () {
    $shop = deckShop();
    $cheap = deckVariant($shop['luffyEn'], 4, 10000);
    $few = deckVariant($shop['luffyEn'], 1, 20000, CardCondition::Lp);
    $other = deckVariant($shop['zoroEn'], 4, 5000);

    $component = Livewire::test(DeckImport::class)->set('raw', '4x OP05-119')->call('check');

    expect($component->instance()->result->lines[0]->bucket)->toBe(DeckBucket::Full);

    $component->call('chooseVariant', 1, $few->id);
    expect($component->instance()->result->lines[0]->bucket)->toBe(DeckBucket::Partial)
        ->and($component->instance()->result->lines[0]->defaultVariantId)->toBe($few->id);

    $component->call('chooseVariant', 1, $other->id)->call('chooseVariant', 99, $cheap->id);
    expect($component->instance()->result->lines[0]->defaultVariantId)->toBe($few->id)
        ->and($component->get('choices'))->toBe([1 => ['variant' => $few->id]]);
});

test('toggling take-what-is-there changes what the list would add', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 2, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '4x OP05-119')->call('check');

    expect($component->instance()->result->totalCards())->toBe(2);

    $component->call('togglePartial', 1);
    expect($component->instance()->result->totalCards())->toBe(0);

    $component->call('togglePartial', 1);
    expect($component->instance()->result->totalCards())->toBe(2);

    $component->call('togglePartial', 42);
    expect($component->get('choices'))->toBe([1 => ['partial' => true]]);
});

test('text typed after a check does not change the result until the next check', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4);
    deckVariant($shop['zoroEn'], 4);

    $component = Livewire::test(DeckImport::class)->set('raw', 'OP05-119')->call('check')->set('raw', 'OP05-120');

    expect($component->instance()->parse->lines[0]->printingIds)->toContain($shop['luffyEn']->id);

    $component->call('check');
    expect($component->instance()->parse->lines[0]->printingIds)->toBe([$shop['zoroEn']->id]);
});

test('changing the language or the game drops the picks, and clear starts over', function () {
    $shop = deckShop();
    $few = deckVariant($shop['luffyEn'], 1, 20000, CardCondition::Lp);
    deckVariant($shop['luffyEn'], 4, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '4x OP05-119')->call('check')->call('chooseVariant', 1, $few->id);

    $component->call('selectLanguage', 'en')->assertSet('choices', []);

    $component->call('chooseVariant', 1, $few->id)->call('selectGame', $shop['pokemon']->id)->assertSet('choices', []);

    $component->call('clear')->assertSet('raw', '')->assertSet('checked', false);

    expect($component->instance()->parse)->toBeNull();
});

test('fifty row actions in one request read the catalogue once', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 2, 10000);

    $counter = new class(app(QueryParser::class), app(FuzzyCardMatcher::class)) extends ParseDeckList
    {
        public int $parses = 0;

        public function price(DeckMatch $match, ?Language $language = null): DeckParse
        {
            $this->parses++;

            return parent::price($match, $language);
        }
    };
    app()->instance(ParseDeckList::class, $counter);

    $component = Livewire::test(DeckImport::class)->set('raw', '4x OP05-119')->call('check');
    $deck = $component->instance();
    $counter->parses = 0;

    foreach (range(1, 50) as $i) {
        $deck->togglePartial(1);
        $deck->chooseVariant(1, deckVariantIdOf($shop['luffyEn']));
        expect($deck->result->totalCards())->toBe($i % 2 === 0 ? 2 : 0);
    }

    expect($counter->parses)->toBeLessThanOrEqual(1);
});

test('switching the language back and forth in one request prices each language once', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 2, 10000);

    $counter = new class(app(QueryParser::class), app(FuzzyCardMatcher::class)) extends ParseDeckList
    {
        public int $parses = 0;

        public function price(DeckMatch $match, ?Language $language = null): DeckParse
        {
            $this->parses++;

            return parent::price($match, $language);
        }
    };
    app()->instance(ParseDeckList::class, $counter);

    $deck = Livewire::test(DeckImport::class)->set('raw', '4x OP05-119')->call('check')->instance();
    $counter->parses = 0;

    foreach (range(1, 25) as $i) {
        $deck->selectLanguage('en');
        $deck->togglePartial(1);
        $deck->selectLanguage(null);
        $deck->togglePartial(1);
        expect($deck->result)->not->toBeNull();
    }

    expect($counter->parses)->toBeLessThanOrEqual(2);
});

function deckVariantIdOf(Printing $printing): int
{
    return Variant::query()->where('printing_id', $printing->id)->value('id');
}

test('a list spread over many sets still parses in a bounded number of queries', function () {
    $shop = deckShop();
    $lines = [];

    foreach (range(1, 40) as $i) {
        $set = CardSet::factory()->for($shop['onePiece'], 'game')->create(['code' => 'ST'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        $card = Card::factory()->for($shop['onePiece'], 'game')->create(['name_en' => "Spread {$i}"]);
        deckVariant(Printing::factory()->for($card)->for($set, 'set')->create(['number' => '001']), 4);
        $lines[] = '2x ST'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'-001';
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $parse = parseDeck($shop['onePiece'], implode("\n", $lines));

    expect($parse->counts()['full'])->toBe(40)
        ->and($queries)->toBeLessThan(20);
});

test('checking is limited to twenty a minute and says how long to wait', function () {
    $this->freezeTime();
    deckShop();
    $component = Livewire::test(DeckImport::class)->set('raw', 'OP05-119');

    foreach (range(1, 20) as $i) {
        $component->call('check')->assertHasNoErrors();
    }

    $component->call('check')
        ->assertHasErrors(['raw'])
        ->assertSee(__('storefront.deck.errors.throttled', ['seconds' => 60]));

    // The text did not change, but the refused check still marks the shown result as old.
    expect($component->instance()->isStale())->toBeTrue();
});

test('a pick that sold out falls back to the default and cannot be chosen again', function () {
    $shop = deckShop();
    $cheap = deckVariant($shop['luffyEn'], 4, 10000);
    $pick = deckVariant($shop['luffyEn'], 4, 20000, CardCondition::Lp);

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')->call('chooseVariant', 1, $pick->id);

    expect($component->instance()->result->lines[0]->defaultVariantId)->toBe($pick->id);

    $pick->update(['qty' => 0]);
    // A real next request: a fresh component instance prices the list against the stock as it is now.
    $component->call('$refresh');

    $line = $component->instance()->result->lines[0];

    expect($line->defaultVariantId)->toBe($cheap->id)
        ->and($line->bucket)->toBe(DeckBucket::Full);

    $soldOut = deckVariant($shop['luffyEn'], 0, 5000, CardCondition::Hp);
    $fresh = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')->call('chooseVariant', 1, $soldOut->id);

    expect($fresh->get('choices'))->toBe([])
        ->and($component->instance()->result->lines[0]->defaultVariantId)->toBe($cheap->id);
});

test('a name that matches several cards is flagged and the options carry their codes', function () {
    $shop = deckShop();
    $twin = Card::factory()->for($shop['onePiece'], 'game')->create(['name_en' => 'Monkey D. Luffy']);
    $twinPrinting = Printing::factory()->for($twin)->for($shop['op05'], 'set')->create(['number' => '500']);
    deckVariant($shop['luffyEn'], 4, 10000);
    deckVariant($twinPrinting, 4, 12000);

    $line = deckLine(parseDeck($shop['onePiece'], '1x Monkey D. Luffy'), 1);
    $exact = deckLine(parseDeck($shop['onePiece'], '1x OP05-119'), 1);

    expect($line->ambiguous)->toBeTrue()
        ->and($exact->ambiguous)->toBeFalse();

    Livewire::test(DeckImport::class)->set('raw', '1x Monkey D. Luffy')->call('check')
        ->assertSee(__('storefront.deck.ambiguous'))
        ->assertSee('OP05-500 · EN')
        ->assertSee('OP05-119 · EN');
});

test('a language that empties a line says what other languages have and offers all languages', function () {
    $shop = deckShop();
    deckVariant($shop['luffyJa'], 4);
    deckVariant($shop['luffyJa'], 2, 20000, CardCondition::Lp);

    $line = deckLine(parseDeck($shop['onePiece'], 'OP05-119', Language::En), 1);

    expect($line->bucket)->toBe(DeckBucket::Missing)
        ->and($line->variants)->toBe([])
        ->and($line->otherLanguagesAvailable)->toBe(2);

    Livewire::test(DeckImport::class)->call('selectLanguage', 'en')->set('raw', 'OP05-119')->call('check')
        ->assertSee(__('storefront.deck.other_language', ['lang' => 'EN', 'n' => 2]))
        ->assertSee(__('storefront.deck.all_languages'))
        ->assertDontSee(__('storefront.deck.not_stocked'))
        ->call('selectLanguage', null)
        ->assertDontSee(__('storefront.deck.all_languages'));
});

test('a result the text has moved on from is stale and its actions do nothing', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 2, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '4x OP05-119')->call('check');

    $staleLine = '<p role="status" class="text-small font-medium text-low">';

    $component->assertDontSeeHtml($staleLine)->assertDontSee('dirty || true', false);

    $component->set('raw', '4x OP05-120')
        ->assertSeeHtml($staleLine)
        ->assertSee('dirty || true', false)
        ->call('togglePartial', 1)
        ->assertSet('choices', []);

    expect($component->instance()->isStale())->toBeTrue();

    $component->call('check');
    expect($component->instance()->isStale())->toBeFalse();
});

test('a refused check leaves the old result shown as stale', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 2, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '4x OP05-119')->call('check')
        ->set('raw', '')
        ->call('check')
        ->assertHasErrors(['raw'])
        ->assertSeeHtml('<p role="status" class="text-small font-medium text-low">')
        ->assertSet('checked', true);

    expect($component->instance()->isStale())->toBeTrue();
});

test('the problem buckets come first and a long full bucket is collapsed', function () {
    $shop = deckShop();
    $lines = ['3x OP05-120', '1x OP05-119'];
    deckVariant($shop['zoroEn'], 1);
    deckVariant($shop['luffyEn'], 5);

    foreach (range(1, 9) as $i) {
        $card = Card::factory()->for($shop['onePiece'], 'game')->create(['name_en' => "Bulk {$i}"]);
        deckVariant(Printing::factory()->for($card)->for($shop['op05'], 'set')->create(['number' => (string) (600 + $i)]), 4);
        $lines[] = "1x Bulk {$i}";
    }

    $html = Livewire::test(DeckImport::class)->set('raw', implode("\n", $lines))->call('check')
        ->assertSee(__('storefront.deck.show_all', ['n' => 10]))
        ->html();

    expect(strpos($html, 'id="deck-partial-heading"'))->toBeLessThan(strpos($html, 'id="deck-full-heading"'));
});

test('after one check, later requests never match again or ask the engine', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 1, 10000);
    deckVariant($shop['luffyEn'], 5, 20000, CardCondition::Lp);

    $asked = fakeFuzzyMatcher();
    $counter = new class(app(QueryParser::class), $asked) extends ParseDeckList
    {
        public int $matches = 0;

        public function match(Game $game, string $raw): DeckMatch
        {
            $this->matches++;

            return parent::match($game, $raw);
        }
    };
    app()->instance(ParseDeckList::class, $counter);

    $component = Livewire::test(DeckImport::class)->set('raw', "4x OP05-119\nnobody one\nnobody two")->call('check');

    expect($counter->matches)->toBe(1)
        ->and($asked->asked)->toHaveCount(2);

    $pick = deckVariantIdOf($shop['luffyEn']);

    foreach (range(1, 25) as $i) {
        match ($i % 5) {
            0 => $component->call('$refresh'),
            1 => $component->call('togglePartial', 1),
            2 => $component->call('chooseVariant', 1, $pick),
            3 => $component->call('selectLanguage', $i % 2 === 0 ? 'en' : null),
            4 => $component->call('chooseVariant', 1, $pick + 1),
        };
    }

    expect($counter->matches)->toBe(1)
        ->and($asked->asked)->toHaveCount(2);

    // Another game does need matching again, once, and counts against the limit.
    $component->call('selectGame', $shop['pokemon']->id);

    expect($counter->matches)->toBe(2)
        ->and($asked->asked)->toHaveCount(5);
});

test('picking a game and checking share one rate limit, but picking a language does not use it', function () {
    $this->freezeTime();
    $shop = deckShop();
    $component = Livewire::test(DeckImport::class)->set('raw', 'OP05-119')->call('check');

    foreach (range(1, 19) as $i) {
        $component->call('selectLanguage', 'en')->call('selectLanguage', null);
    }

    foreach (range(1, 19) as $i) {
        $component->call('selectGame', $i % 2 === 0 ? $shop['onePiece']->id : $shop['pokemon']->id)->assertHasNoErrors();
    }

    $component->call('selectGame', $shop['onePiece']->id)
        ->assertHasErrors(['raw'])
        ->assertSee(__('storefront.deck.errors.throttled', ['seconds' => 60]));
});

test('a client cannot write the game or the language', function (string $property, mixed $value) {
    $shop = deckShop();

    Livewire::test(DeckImport::class)->set($property, $value);
})->with([
    'game' => ['gameId', 2],
    'language' => ['language', 'ja'],
])->throws(CannotUpdateLockedPropertyException::class);

test('the rate-limit key is the IPv4 address, or the /64 prefix of an IPv6 address', function () {
    expect(DeckImport::throttleKey('2001:db8:1:2::1'))->toBe(DeckImport::throttleKey('2001:db8:1:2:ffff::9'))
        ->and(DeckImport::throttleKey('2001:db8:1:2::1'))->not->toBe(DeckImport::throttleKey('2001:db8:1:3::1'))
        ->and(DeckImport::throttleKey('203.0.113.7'))->toBe('deck-check:203.0.113.7')
        ->and(DeckImport::throttleKey('203.0.113.7'))->not->toBe(DeckImport::throttleKey('203.0.113.8'))
        ->and(DeckImport::throttleKey('::ffff:203.0.113.7'))->toBe('deck-check:203.0.113.7')
        ->and(DeckImport::throttleKey('::ffff:198.51.100.9'))->not->toBe(DeckImport::throttleKey('::ffff:203.0.113.7'));
});

test('a very long line never reaches the engine', function () {
    $shop = deckShop();
    $seen = engineReturning([hit(5, 1.0, 'Anything')]);

    $parse = parseDeck($shop['onePiece'], str_repeat('a', 9000));

    expect($parse->lines[0]->kind)->toBe(DeckLineKind::Unmatched)
        ->and($seen['searches'])->toBe(0);
});

test('names too different in length are not compared character by character', function () {
    expect(NameKey::similarity('ab', str_repeat('a', 50), 0.8))->toBe(0.0)
        ->and(NameKey::similarity('charizard ex', 'charizrd ex', 0.8))->toBeGreaterThan(0.9)
        ->and(NameKey::similarity('ab', str_repeat('a', 50)))->toBeGreaterThan(0.0);
});

test('a line of only no-break spaces does not count against the line limit', function () {
    deckShop();

    Livewire::test(DeckImport::class)
        ->set('raw', implode("\n", array_fill(0, 200, 'OP05-119'))."\n\u{00A0}\n\u{3000}")
        ->call('check')
        ->assertHasNoErrors();
});

test('choosing a variant does nothing while the result is stale', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);
    $pick = deckVariant($shop['luffyEn'], 4, 20000, CardCondition::Lp);

    Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')
        ->set('raw', '2x OP05-120')
        ->call('chooseVariant', 1, $pick->id)
        ->assertSet('choices', []);
});

test('a card that left the shop after the check is priced as gone on the next request', function () {
    $shop = deckShop();
    deckVariant($shop['zoroEn'], 4, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-120')->call('check');

    expect($component->instance()->parse->lines[0]->bucket)->toBe(DeckBucket::Full);

    $shop['zoro']->delete();
    $component->call('$refresh');

    $line = $component->instance()->parse->lines[0];

    expect($line->bucket)->toBe(DeckBucket::Missing)
        ->and($line->variants)->toBe([]);
});

/**
 * Takes `$qty` of a variant out of what others can buy, as a live hold from another cart would.
 */
function deckHold(Variant $variant, int $qty): StockReservation
{
    return StockReservation::factory()->create([
        'cart_id' => Cart::factory()->create(),
        'purchasable_id' => $variant->id,
        'qty' => $qty,
    ]);
}

/**
 * The signature the page sends back with "add all" for the list it shows.
 */
function shownSignature(Testable $component): string
{
    return $component->instance()->result->signature($component->instance()->gameId);
}

function shownTotal(Testable $component): int
{
    return $component->instance()->result->totalSatang();
}

test('add all holds every line, sums lines on one variant, and lands on the cart with a status', function () {
    $shop = deckShop();
    $luffy = deckVariant($shop['luffyEn'], 9, 10000);
    $zoro = deckVariant($shop['zoroEn'], 5, 20000);

    $component = Livewire::test(DeckImport::class)->set('raw', "2x OP05-119\n1x OP05-119\n3x OP05-120\n5x Unknown Card")->call('check');
    $component->call('addAll', shownSignature($component))
        ->assertRedirect(route('cart'));

    $cart = Cart::query()->sole();

    expect(CartItem::query()->where('cart_id', $cart->id)->orderBy('purchasable_id')->get()->map(fn (CartItem $item): array => [$item->purchasable_id, $item->qty])->all())
        ->toBe([[$luffy->id, 3], [$zoro->id, 3]])
        ->and(StockReservation::query()->where('cart_id', $cart->id)->sum('qty'))->toBe(6)
        ->and(session('status'))->toBe(__('storefront.deck.added', ['n' => 6, 'minutes' => config('shop.reservation_minutes')]));
});

test('add all takes the available quantity of a partial line, only while the toggle is on, and never a missing line', function () {
    $shop = deckShop();
    $luffy = deckVariant($shop['luffyEn'], 2, 10000);
    $zoro = deckVariant($shop['zoroEn'], 4, 20000);
    $soldOut = deckVariant(Printing::factory()->for($shop['zoro'])->for($shop['op05'], 'set')->create(['number' => '122', 'language' => Language::En]), 0, 5000);
    $raw = "5x OP05-119\n1x OP05-120\n2x OP05-122";

    $on = Livewire::test(DeckImport::class)->set('raw', $raw)->call('check');
    $on->call('addAll', shownSignature($on))->assertRedirect(route('cart'));

    expect(CartItem::query()->where('purchasable_id', $luffy->id)->sole()->qty)->toBe(2)
        ->and(CartItem::query()->count())->toBe(2)
        ->and(CartItem::query()->where('purchasable_id', $soldOut->id)->exists())->toBeFalse();

    CartItem::query()->delete();
    StockReservation::query()->delete();

    $off = Livewire::test(DeckImport::class)->set('raw', $raw)->call('check')->call('togglePartial', 1);
    $off->call('addAll', shownSignature($off))->assertRedirect(route('cart'));

    expect(CartItem::query()->count())->toBe(1)
        ->and(CartItem::query()->sole()->purchasable_id)->toBe($zoro->id);
});

test('add all adds nothing for a list with nothing to add', function () {
    $shop = deckShop();

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');
    $component->call('addAll', '')->assertNoRedirect();

    expect(Cart::query()->count())->toBe(0);
});

test('add all refuses a stale result on the server', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');
    $signature = shownSignature($component);

    $component->set('raw', "2x OP05-119\n1x OP05-120")->call('addAll', $signature)->assertNoRedirect();

    expect(Cart::query()->count())->toBe(0)->and(CartItem::query()->count())->toBe(0);

    $failed = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')->set('raw', '')->call('check');
    $failed->call('addAll', $signature)->assertNoRedirect();

    expect(CartItem::query()->count())->toBe(0);
});

test('add all adds nothing when the total is not the one the customer saw, and shows the list again', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');

    $component->call('addAll', 'not-what-was-shown')
        ->assertNoRedirect()
        ->assertSet('addNotice', __('storefront.deck.total_changed'))
        ->assertSee(__('storefront.deck.total_changed'));

    expect(CartItem::query()->count())->toBe(0)->and(StockReservation::query()->count())->toBe(0);
});

test('a pick that sold out falls back to a dearer default, and add all then adds nothing', function () {
    $shop = deckShop();
    $cheap = deckVariant($shop['luffyEn'], 4, 10000, CardCondition::Lp);
    deckVariant($shop['luffyEn'], 4, 20000, CardCondition::Nm);

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');
    $component->call('chooseVariant', 1, $cheap->id);
    $seen = shownSignature($component);
    $seenTotal = shownTotal($component);

    deckHold($cheap, 4);

    $component->call('addAll', $seen)
        ->assertNoRedirect()
        ->assertSet('addNotice', __('storefront.deck.total_changed'));

    expect($seenTotal)->toBe(20000)
        ->and(CartItem::query()->count())->toBe(0)
        ->and(shownTotal($component))->toBe(40000);
});

test('when the action reports failed variants the page marks them, shows the error and keeps the cart empty', function () {
    $shop = deckShop();
    $luffy = deckVariant($shop['luffyEn'], 4, 10000);

    app()->instance(AddDeckToCart::class, new class extends AddDeckToCart
    {
        public function __construct() {}

        public function handle(Cart $cart, Game $game, array $variantQty): void
        {
            throw new DeckNotReserved(array_keys($variantQty));
        }
    });

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');
    $component->call('addAll', shownSignature($component))
        ->assertNoRedirect()
        ->assertSet('failedVariants', [$luffy->id])
        ->assertSee(__('storefront.deck.add_failed'))
        ->assertSee(__('storefront.deck.add_failed_row'));
});

test('a variant that cannot be held fails the whole add and holds nothing, not even the lines that fit', function () {
    $shop = deckShop();
    $fits = deckVariant($shop['luffyEn'], 4, 10000);
    $gone = deckVariant($shop['zoroEn'], 4, 20000);
    $cart = Cart::factory()->create();

    deckHold($gone, 4);

    try {
        app(AddDeckToCart::class)->handle($cart, $shop['onePiece'], [$fits->id => 2, $gone->id => 1]);
        $this->fail('Expected DeckNotReserved.');
    } catch (DeckNotReserved $failed) {
        expect($failed->variantIds)->toBe([$gone->id]);
    }

    expect(CartItem::query()->where('cart_id', $cart->id)->count())->toBe(0)
        ->and(StockReservation::query()->where('cart_id', $cart->id)->count())->toBe(0);
});

test('every variant that fails is reported, not only the first', function () {
    $shop = deckShop();
    $one = deckVariant($shop['luffyEn'], 1, 10000);
    $two = deckVariant($shop['zoroEn'], 1, 20000);

    expect(fn () => app(AddDeckToCart::class)->handle(Cart::factory()->create(), $shop['onePiece'], [$one->id => 2, $two->id => 2]))
        ->toThrow(fn (DeckNotReserved $failed) => expect($failed->variantIds)->toBe([$one->id, $two->id]));
});

test('a variant that became unpurchasable fails the whole add', function () {
    $shop = deckShop();
    $fits = deckVariant($shop['luffyEn'], 4, 10000);
    $unpriced = deckVariant($shop['zoroEn'], 4, 20000);
    $cart = Cart::factory()->create();

    $unpriced->update(['price_satang' => null]);

    expect(fn () => app(AddDeckToCart::class)->handle($cart, $shop['onePiece'], [$fits->id => 1, $unpriced->id => 1]))
        ->toThrow(fn (DeckNotReserved $failed) => expect($failed->variantIds)->toBe([$unpriced->id]));

    expect(CartItem::query()->count())->toBe(0)->and(StockReservation::query()->count())->toBe(0);
});

test('add to cart re-validates the game and the quantity it is given', function (string $case) {
    $shop = deckShop();
    $mine = deckVariant($shop['luffyEn'], 150, 10000);
    $other = deckVariant($shop['charizardEn'], 5, 10000);
    $cart = Cart::factory()->create();

    $map = match ($case) {
        'another game' => [$mine->id => 1, $other->id => 1],
        'zero' => [$mine->id => 0],
        'over the cap' => [$mine->id => config('deck.max_qty') + 1],
        'unknown id' => [$mine->id => 1, 999999 => 1],
    };
    $expected = match ($case) {
        'another game' => [$other->id],
        'zero', 'over the cap' => [$mine->id],
        'unknown id' => [999999],
    };

    expect(fn () => app(AddDeckToCart::class)->handle($cart, $shop['onePiece'], $map))
        ->toThrow(fn (DeckNotReserved $failed) => expect($failed->variantIds)->toBe($expected));

    expect(CartItem::query()->count())->toBe(0)->and(StockReservation::query()->count())->toBe(0);
})->with(['another game', 'zero', 'over the cap', 'unknown id']);

test('add all locks the variants first, in id order, before any other read', function () {
    $shop = deckShop();
    $first = deckVariant($shop['luffyEn'], 4, 10000);
    $second = deckVariant($shop['zoroEn'], 4, 20000);
    $cart = Cart::factory()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    // Handed in reverse order, so only the action itself can put them in id order.
    app(AddDeckToCart::class)->handle($cart, $shop['onePiece'], [$second->id => 1, $first->id => 1]);

    // SQLite writes no FOR UPDATE, so this proves the order (all variants select first, sorted by id), not the lock itself.
    expect($queries[0])->toStartWith('select * from "variants" where "id" in (')->toContain('order by "id" asc');
});

test('the bulk alert makes one call with the candidate variants of every missing line', function () {
    $shop = deckShop();
    $first = deckVariant($shop['luffyEn'], 0, 10000);
    $second = deckVariant($shop['luffyEn'], 0, 11000, CardCondition::Lp);
    $third = deckVariant($shop['zoroEn'], 2, 20000);
    $zoroSoldOut = deckVariant(Printing::factory()->for($shop['zoro'])->for($shop['op05'], 'set')->create(['number' => '121', 'language' => Language::En]), 0, 5000);
    $spy = new class(app(SetRestockAlerts::class)) extends SetRestockAlerts
    {
        /** @var list<array<int, int>> */
        public array $calls = [];

        public function __construct(private SetRestockAlerts $real) {}

        public function handle(User $user, array $variantIds): int
        {
            $this->calls[] = $variantIds;

            return $this->real->handle($user, $variantIds);
        }
    };
    app()->instance(SetRestockAlerts::class, $spy);

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(DeckImport::class)->set('raw', "2x OP05-119\n1x OP05-120\n1x OP05-121\nmystery")->call('check')
        ->assertSee(__('storefront.deck.alert_missing', ['n' => 2]))
        ->call('alertMissing')
        ->assertSet('alertSet', true)
        ->assertSee(__('storefront.deck.alerts_set', ['n' => 2]));

    sort($spy->calls[0]);

    expect($spy->calls)->toHaveCount(1)
        ->and($spy->calls[0])->toBe([$first->id, $second->id, $zoroSoldOut->id])
        ->and(RestockAlert::query()->where('user_id', $user->id)->count())->toBe(3);
});

test('a guest who sets the bulk alert is sent to log in and gets the list back afterwards', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);

    Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')
        ->call('alertMissing')
        ->assertRedirect(route('login'));

    expect(session('deck.pending'))->toMatchArray(['raw' => '2x OP05-119', 'game' => $shop['onePiece']->id, 'language' => null, 'alert' => true])
        ->and(session('url.intended'))->toBe(route('deck.import'))
        ->and(RestockAlert::query()->count())->toBe(0);

    $user = User::factory()->create();
    session()->put('deck.restore', session('deck.pending'));

    Livewire::actingAs($user)->test(DeckImport::class)
        ->assertSet('raw', '2x OP05-119')
        ->assertSet('checked', true)
        ->assertSet('gameId', $shop['onePiece']->id);
});

test('a customer with no way to be told gets the refusal message inline', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);
    $noChannel = User::factory()->withLine()->create(['email' => null, 'line_notify_opt_in' => false]);

    Livewire::actingAs($noChannel)->test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')
        ->call('alertMissing')
        ->assertSet('alertSet', false)
        ->assertSee((new AlertRefused('no_channel'))->message());

    expect(RestockAlert::query()->count())->toBe(0);
});

test('the bulk alert refuses a stale result and has no button when no missing line has a variant', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');
    $component->set('raw', '2x OP05-120')->call('alertMissing');

    expect(RestockAlert::query()->count())->toBe(0);

    Livewire::actingAs($user)->test(DeckImport::class)->set('raw', '2x OP05-120')->call('check')
        ->assertDontSee(__('storefront.deck.alert_missing', ['n' => 1]));
});

test('saving keeps the pasted text byte for byte, with the parsed match and a default name', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);
    $user = User::factory()->create();
    $raw = "4x\tOP05-119  \r\n\r\n  mystery card \t\r\n";

    Livewire::actingAs($user)->test(DeckImport::class)->set('raw', $raw)->call('check')
        ->call('save')
        ->assertSee(__('storefront.deck.saved'));

    $saved = SavedDeckList::query()->sole();

    expect($saved->raw_text)->toBe($raw)
        ->and($saved->user_id)->toBe($user->id)
        ->and($saved->game_id)->toBe($shop['onePiece']->id)
        ->and($saved->name)->toStartWith('เด็ค One Piece · ')
        ->and($saved->parsed[0]['kind'])->toBe('matched')
        ->and($saved->parsed[0]['printingIds'])->toContain($shop['luffyEn']->id);
});

test('saving takes the name given, refuses one over 100 characters, and needs a signed-in customer and a fresh result', function () {
    $shop = deckShop();
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')
        ->set('saveName', str_repeat('a', 101))->call('save')
        ->assertHasErrors(['saveName']);

    expect(SavedDeckList::query()->count())->toBe(0);

    $component->set('saveName', 'Red aggro')->call('save')->assertHasNoErrors();

    expect(SavedDeckList::query()->sole()->name)->toBe('Red aggro');

    $component->set('raw', '2x OP05-120')->call('save');

    expect(SavedDeckList::query()->count())->toBe(1);

    auth()->logout();

    Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')->call('save');

    expect(SavedDeckList::query()->count())->toBe(1);
});

test('a saved list is checked again against current stock, and only its owner can open it', function () {
    $shop = deckShop();
    $variant = deckVariant($shop['luffyEn'], 4, 10000);
    $owner = User::factory()->create();
    $saved = SavedDeckList::factory()->create(['user_id' => $owner->id, 'game_id' => $shop['onePiece']->id, 'raw_text' => '4x OP05-119']);

    $this->actingAs($owner)->get(route('deck.import', ['list' => $saved->id]))->assertOk()->assertSee(__('storefront.deck.summary_title'));

    deckHold($variant, 3);

    $this->actingAs($owner)->get(route('deck.import', ['list' => $saved->id]))
        ->assertSee(__('storefront.deck.want_have', ['want' => 4, 'have' => 1]));

    $this->actingAs(User::factory()->create())->get(route('deck.import', ['list' => $saved->id]))->assertNotFound();
    auth()->logout();
    $this->get(route('deck.import', ['list' => $saved->id]))->assertNotFound();
    $this->actingAs($owner)->get(route('deck.import', ['list' => 999999]))->assertNotFound();
    $this->actingAs($owner)->get(route('deck.import', ['list' => 'abc']))->assertNotFound();
});

test('the account page lists saved decks, and deleting is for the owner only', function () {
    $shop = deckShop();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $mine = SavedDeckList::factory()->create(['user_id' => $owner->id, 'game_id' => $shop['onePiece']->id, 'name' => 'Red aggro']);
    $theirs = SavedDeckList::factory()->create(['user_id' => $other->id, 'game_id' => $shop['onePiece']->id, 'name' => 'Blue control']);

    $this->actingAs($owner)->get(route('account'))
        ->assertSee(__('storefront.account.decks.title'))
        ->assertSee('Red aggro')
        ->assertDontSee('Blue control')
        ->assertSee(route('deck.import', ['list' => $mine->id]));

    $this->actingAs($owner)->delete(route('account.decks.destroy', $theirs))->assertNotFound();
    expect(SavedDeckList::query()->count())->toBe(2);

    $this->actingAs($owner)->delete(route('account.decks.destroy', $mine))->assertRedirect(route('account'));
    expect(SavedDeckList::query()->pluck('id')->all())->toBe([$theirs->id]);

    auth()->logout();
    $this->delete(route('account.decks.destroy', $theirs))->assertRedirect();
    expect(SavedDeckList::query()->count())->toBe(1);
});

test('the header links to the importer and an empty cart offers it', function () {
    deckShop();

    $this->get(route('home'))->assertSee('href="'.route('deck.import').'"', false)->assertSee(__('storefront.nav.deck'));
    $this->get(route('cart'))
        ->assertSee(__('storefront.cart.deck'))
        ->assertSee(__('storefront.cart.deck_hint'))
        ->assertSee(__('storefront.cart.search'));
});

test('the cart page shows the status add all leaves behind', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);

    $this->withSession(['status' => 'เพิ่ม 2 ใบลงตะกร้าแล้ว'])->get(route('cart'))->assertSee('เพิ่ม 2 ใบลงตะกร้าแล้ว');
});

test('two lines on one variant share its stock: the later one is partial, and add all holds exactly what there is', function () {
    $shop = deckShop();
    $luffy = deckVariant($shop['luffyEn'], 5, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', "4x OP05-119\n4x OP05-119")->call('check');
    $lines = $component->instance()->result->lines;

    expect($lines[0]->bucket)->toBe(DeckBucket::Full)
        ->and($lines[0]->allocatedQty)->toBe(4)
        ->and($lines[1]->bucket)->toBe(DeckBucket::Partial)
        ->and($lines[1]->allocatedQty)->toBe(1)
        ->and($component->instance()->result->variantQty())->toBe([$luffy->id => 5]);

    $component->call('addAll', shownSignature($component))->assertRedirect(route('cart'));

    expect(CartItem::query()->sole()->qty)->toBe(5)
        ->and(StockReservation::query()->sum('qty'))->toBe(5);
});

test('a later line moves to another variant that covers it and stays full', function () {
    $shop = deckShop();
    $cheap = deckVariant($shop['luffyEn'], 5, 10000, CardCondition::Nm);
    $dearer = deckVariant($shop['luffyEn'], 5, 15000, CardCondition::Lp);

    $component = Livewire::test(DeckImport::class)->set('raw', "4x OP05-119\n4x OP05-119")->call('check');
    $lines = $component->instance()->result->lines;

    expect($lines[0]->defaultVariantId)->toBe($cheap->id)
        ->and($lines[0]->bucket)->toBe(DeckBucket::Full)
        ->and($lines[1]->defaultVariantId)->toBe($dearer->id)
        ->and($lines[1]->bucket)->toBe(DeckBucket::Full)
        ->and($component->instance()->result->variantQty())->toBe([$cheap->id => 4, $dearer->id => 4]);

    $component->call('addAll', shownSignature($component))->assertRedirect(route('cart'));

    expect(CartItem::query()->count())->toBe(2);
});

test('the picks are laid over the shared stock in paste order, and a switched-off partial line frees its copies', function () {
    $shop = deckShop();
    $nm = deckVariant($shop['luffyEn'], 5, 10000, CardCondition::Nm);
    $lp = deckVariant($shop['luffyEn'], 5, 15000, CardCondition::Lp);

    $component = Livewire::test(DeckImport::class)->set('raw', "4x OP05-119\n4x OP05-119")->call('check');
    $component->call('chooseVariant', 2, $nm->id);

    $lines = $component->instance()->result->lines;

    // Line 2 asked for the NM copies line 1 already took, so it is left with the 1 that remains.
    expect($lines[1]->defaultVariantId)->toBe($nm->id)
        ->and($lines[1]->bucket)->toBe(DeckBucket::Partial)
        ->and($lines[1]->allocatedQty)->toBe(1);

    $component->call('chooseVariant', 1, $lp->id);

    expect($component->instance()->result->lines[1]->bucket)->toBe(DeckBucket::Full)
        ->and($component->instance()->result->variantQty())->toBe([$nm->id => 4, $lp->id => 4]);

    deckVariant($shop['zoroEn'], 5, 10000);

    $skip = Livewire::test(DeckImport::class)->set('raw', "4x OP05-120\n4x OP05-120\n1x OP05-120")->call('check');
    $before = $skip->instance()->result->lines;
    $skip->call('togglePartial', 2);

    expect($before[2]->bucket)->toBe(DeckBucket::Missing)
        ->and($skip->instance()->result->lines[2]->bucket)->toBe(DeckBucket::Full);
});

test('lines on one variant together add at most the per-line cap, and the later line says so', function () {
    $shop = deckShop();
    $luffy = deckVariant($shop['luffyEn'], 150, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', "60x OP05-119\n60x OP05-119")->call('check')
        ->assertSee(__('storefront.deck.sum_capped', ['max' => config('deck.max_qty')]));
    $lines = $component->instance()->result->lines;

    expect($lines[0]->sumCapped)->toBeFalse()
        ->and($lines[1]->bucket)->toBe(DeckBucket::Partial)
        ->and($lines[1]->allocatedQty)->toBe(39)
        ->and($lines[1]->sumCapped)->toBeTrue()
        ->and($component->instance()->result->variantQty())->toBe([$luffy->id => 99]);

    $component->call('addAll', shownSignature($component))->assertRedirect(route('cart'));

    expect(CartItem::query()->sole()->qty)->toBe(99);
});

test('add all refuses when the shown variant sold out and another at the same price would take its place', function () {
    $shop = deckShop();
    $en = deckVariant($shop['luffyEn'], 4, 10000);
    $ja = deckVariant($shop['luffyJa'], 4, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');
    $seen = shownSignature($component);
    $seenTotal = shownTotal($component);

    expect($component->instance()->result->lines[0]->defaultVariantId)->toBe($en->id);

    deckHold($en, 4);

    expect(shownTotal($component))->toBe($seenTotal);

    $component->call('addAll', $seen)
        ->assertNoRedirect()
        ->assertSet('addNotice', __('storefront.deck.total_changed'));

    expect(CartItem::query()->count())->toBe(0)
        ->and(StockReservation::query()->where('purchasable_id', $ja->id)->count())->toBe(0)
        ->and($component->instance()->result->lines[0]->defaultVariantId)->toBe($ja->id);

    $component->call('addAll', shownSignature($component))->assertRedirect(route('cart'));
});

test('the failed marker covers the line even when the failed variant is not the one shown', function () {
    $shop = deckShop();
    $shown = deckVariant($shop['luffyEn'], 4, 10000, CardCondition::Nm);
    $other = deckVariant($shop['luffyEn'], 4, 12000, CardCondition::Lp);
    $variant = $other->id;

    app()->instance(AddDeckToCart::class, new class($variant) extends AddDeckToCart
    {
        public function __construct(private int $failed) {}

        public function handle(Cart $cart, Game $game, array $variantQty): void
        {
            throw new DeckNotReserved([$this->failed]);
        }
    });

    $component = Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');
    $component->call('addAll', shownSignature($component))
        ->assertSet('failedVariants', [$other->id])
        ->assertSee(__('storefront.deck.add_failed_row'));

    expect($component->instance()->result->lines[0]->defaultVariantId)->toBe($shown->id);
});

test('a customer keeps at most deck.max_saved lists and the same list is not saved twice', function () {
    $shop = deckShop();
    config(['deck.max_saved' => 2]);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')->call('save')
        ->set('saveName', 'Again')->call('save')
        ->assertSee(__('storefront.deck.already_saved', ['name' => SavedDeckList::query()->first()->name]));

    expect(SavedDeckList::query()->count())->toBe(1);

    $component->set('raw', '3x OP05-119')->call('check')->call('save');
    expect(SavedDeckList::query()->count())->toBe(2);

    $component->set('raw', '4x OP05-119')->call('check')->call('save')
        ->assertHasErrors(['saveName'])
        ->assertSee(__('storefront.deck.errors.saved_cap', ['max' => 2]));

    expect(SavedDeckList::query()->count())->toBe(2);

    // Another customer is not counted against the cap, and the same text and game saved by them is theirs.
    Livewire::actingAs(User::factory()->create())->test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')->call('save');
    expect(SavedDeckList::query()->count())->toBe(3);
});

test('a saved list stores only the number, kind, quantity and printings of each line', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);

    Livewire::actingAs(User::factory()->create())->test(DeckImport::class)->set('raw', "4x OP05-119\nmystery")->call('check')->call('save');

    $parsed = SavedDeckList::query()->sole()->parsed;

    expect($parsed)->toHaveCount(2)
        ->and(array_keys($parsed[0]))->toBe(['number', 'kind', 'qty', 'printingIds'])
        ->and($parsed[0])->toBe(['number' => 1, 'kind' => 'matched', 'qty' => 4, 'printingIds' => $parsed[0]['printingIds']])
        ->and($parsed[0]['printingIds'])->toContain($shop['luffyEn']->id);
});

test('a list held across a login comes back once, from a real login, and a guest page forgets a stale one', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);
    $user = User::factory()->create();
    $pending = ['raw' => '2x OP05-119', 'game' => $shop['onePiece']->id, 'language' => null, 'at' => now()->timestamp];

    $this->withSession(['deck.pending' => $pending, 'url.intended' => route('deck.import')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('deck.import'));

    $this->get(route('deck.import'))->assertSee(__('storefront.deck.summary_title'))->assertSee('2x OP05-119');

    $this->get(route('deck.import'))->assertDontSee(__('storefront.deck.summary_title'));

    auth()->logout();
    $this->withSession(['deck.pending' => $pending])->get(route('deck.import'))->assertDontSee(__('storefront.deck.summary_title'));

    expect(session()->has('deck.pending'))->toBeFalse();
});

test('a saved list whose game is closed says so and is not read against another game', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);
    $owner = User::factory()->create();
    $saved = SavedDeckList::factory()->create(['user_id' => $owner->id, 'game_id' => $shop['onePiece']->id, 'raw_text' => '4x OP05-119']);
    $shop['onePiece']->update(['is_active' => false]);

    $this->actingAs($owner)->get(route('deck.import', ['list' => $saved->id]))
        ->assertOk()
        ->assertSee(__('storefront.deck.list_game_closed'))
        ->assertDontSee(__('storefront.deck.summary_title'));
});

test('the seeder leaves the demo customer to a local machine and can run twice', function () {
    $shop = deckShop();
    foreach (range(1, 3) as $number) {
        deckVariant(Printing::factory()->for($shop['luffy'])->for($shop['op05'], 'set')->create(['number' => (string) (200 + $number), 'language' => Language::En]), 4, 10000);
    }

    $this->seed(SavedDeckListSeeder::class);
    expect(SavedDeckList::query()->count())->toBe(0)->and(User::query()->where('email', 'customer@example.com')->exists())->toBeFalse();

    app()->detectEnvironment(fn () => 'local');
    $this->seed(SavedDeckListSeeder::class);
    $this->seed(SavedDeckListSeeder::class);

    $saved = SavedDeckList::query()->sole();

    expect(User::query()->where('email', 'customer@example.com')->count())->toBe(1)
        ->and($saved->game_id)->toBe($shop['onePiece']->id)
        ->and($saved->requested_cards)->toBe(6);
});

test('the account page reads a saved list without its pasted text', function () {
    $shop = deckShop();
    $owner = User::factory()->create();
    SavedDeckList::factory()->create(['user_id' => $owner->id, 'game_id' => $shop['onePiece']->id, 'name' => 'Red aggro']);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($owner)->get(route('account'))->assertSee('Red aggro');

    $read = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "saved_deck_lists"'));

    expect($read)->not->toBeNull()->not->toContain('raw_text')->not->toContain('*');
});

test('a line missing only because earlier lines took the stock says so, has no select and takes no part in the alert', function (string $raw, int $stock, string $copy) {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], $stock, 10000);
    deckVariant(Printing::factory()->for($shop['zoro'])->for($shop['op05'], 'set')->create(['number' => '122', 'language' => Language::En]), 0, 5000);

    $component = Livewire::actingAs(User::factory()->create())->test(DeckImport::class)->set('raw', $raw."\n1x OP05-122")->call('check');
    $lines = $component->instance()->result->lines;

    $component->assertSee($copy)
        ->assertDontSee('deck-variant-2')
        ->assertSee(__('storefront.deck.alert_missing', ['n' => 1]));

    expect($lines[1]->bucket)->toBe(DeckBucket::Missing)
        ->and($lines[1]->takenByEarlierLines)->toBeTrue()
        ->and(collect($component->instance()->alertableLines())->pluck('number')->all())->toBe([3]);

    $component->call('alertMissing');

    expect(RestockAlert::query()->count())->toBe(1);
})->with([
    'taken by the line before' => ["4x OP05-119\n4x OP05-119", 4, 'ใช้ไปกับบรรทัดก่อนหน้าแล้ว'],
    'closed by the per-variant cap' => ["99x OP05-119\n4x OP05-119", 150, 'รวมกับบรรทัดก่อนหน้าเกิน 99 ใบ'],
]);

test('the variant select offers, and chooseVariant accepts, only what earlier lines left', function () {
    $shop = deckShop();
    $nm = deckVariant($shop['luffyEn'], 5, 10000, CardCondition::Nm);
    $lp = deckVariant($shop['luffyEn'], 8, 15000, CardCondition::Lp);
    $mp = deckVariant($shop['luffyEn'], 8, 16000, CardCondition::Mp);

    $component = Livewire::test(DeckImport::class)->set('raw', "5x OP05-119\n2x OP05-119")->call('check');

    expect($component->instance()->result->lines[1]->remaining)->toBe([$nm->id => 0, $lp->id => 8, $mp->id => 8]);

    // Line 1 may still pick the NM copies; line 2's select must not offer them.
    preg_match('/<select id="deck-variant-2".*?<\/select>/s', $component->html(), $select);

    expect($select)->not->toBeEmpty()
        ->and($select[0])->toContain('<option value="'.$lp->id.'"')
        ->and($select[0])->toContain('<option value="'.$mp->id.'"')
        ->and($select[0])->not->toContain('<option value="'.$nm->id.'"');

    $component->call('chooseVariant', 2, $nm->id)->assertSet('choices', []);

    $component->call('chooseVariant', 2, $mp->id)->assertSet('choices', [2 => ['variant' => $mp->id]]);
});

test('every refused action on a stale result says so next to its control', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 4, 10000);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(DeckImport::class)->set('raw', '2x OP05-119')->call('check');
    $signature = shownSignature($component);
    $component->set('raw', '3x OP05-119');

    $component->call('addAll', $signature)->assertSet('addNotice', __('storefront.deck.stale'));
    $component->call('alertMissing')->assertSet('alertNotice', __('storefront.deck.stale'));
    $component->call('save')->assertHasErrors(['saveName'])->assertSee(__('storefront.deck.stale'));
});

test('a guest who pressed the bulk alert finishes it after a real login and is scrolled to the result', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);
    $user = User::factory()->create();

    Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')->call('alertMissing')->assertRedirect(route('login'));

    $pending = session('deck.pending');

    $this->withSession(['deck.pending' => $pending])->get(route('login'))->assertSee(__('storefront.deck.login_note'));

    $this->withSession(['deck.pending' => $pending, 'url.intended' => route('deck.import')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('deck.import'));

    expect(RestockAlert::query()->count())->toBe(0);

    $this->get(route('deck.import'))
        ->assertSee(__('storefront.deck.alerts_set', ['n' => 1]))
        ->assertSee('deck-alerted', false);

    expect(RestockAlert::query()->where('user_id', $user->id)->count())->toBe(1);

    $this->get(route('deck.import'))->assertDontSee(__('storefront.deck.alerts_set', ['n' => 1]));
    $this->get(route('login'))->assertDontSee(__('storefront.deck.login_note'));
});

test('the phone bar says how many of the list it adds, and a changed list is a prompt, not an error', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 2, 10000);

    $component = Livewire::test(DeckImport::class)->set('raw', '5x OP05-119')->call('check')
        ->assertSee(__('storefront.deck.bar_cards', ['have' => 2, 'want' => 5]))
        ->assertSee('id="deck-add-bar"', false);

    $component->call('addAll', 'not-what-was-shown')->assertSet('addFailed', false)->assertSet('addNotice', __('storefront.deck.total_changed'));
});

test('the header and the phone nav both link to the importer, and the importer page hides the phone nav', function () {
    deckShop();

    $home = $this->get(route('home'))->getContent();

    expect(substr_count($home, 'href="'.route('deck.import').'"'))->toBe(2);

    $this->get(route('deck.import'))->assertDontSee('aria-label="'.__('storefront.nav.bottom').'"', false);
});

test('the account page shows the stored count of cards a saved list asks for, and confirms before deleting', function () {
    $shop = deckShop();
    $owner = User::factory()->create();
    SavedDeckList::factory()->create(['user_id' => $owner->id, 'game_id' => $shop['onePiece']->id, 'name' => 'Red aggro', 'requested_cards' => 7]);

    $this->actingAs($owner)->get(route('account'))
        ->assertSee(__('storefront.account.decks.meta', ['n' => 7, 'game' => 'One Piece']))
        ->assertSee('x-on:submit', false)
        ->assertSee('Red aggro ?', false);
});

test('the full bucket opens by itself when one of its rows failed', function () {
    $shop = deckShop();
    $variants = [];
    $lines = [];

    foreach (range(1, 9) as $i) {
        $printing = Printing::factory()->for($shop['zoro'])->for($shop['op05'], 'set')->create(['number' => (string) (300 + $i), 'language' => Language::En]);
        $variants[] = deckVariant($printing, 4, 10000);
        $lines[] = '1x OP05-'.(300 + $i);
    }

    $failed = $variants[3]->id;

    app()->instance(AddDeckToCart::class, new class($failed) extends AddDeckToCart
    {
        public function __construct(private int $failed) {}

        public function handle(Cart $cart, Game $game, array $variantQty): void
        {
            throw new DeckNotReserved([$this->failed]);
        }
    });

    $component = Livewire::test(DeckImport::class)->set('raw', implode("\n", $lines))->call('check')->assertSee('open: false', false);
    $component->call('addAll', shownSignature($component))->assertSee('open: true', false);
});

test('two lists that differ only in letter case are two lists, and no statement filters on the pasted text', function () {
    deckShop();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::actingAs(User::factory()->create())->test(DeckImport::class)->set('raw', '4x luffy')->call('check')->call('save')
        ->set('raw', '4X Luffy')->call('check')->call('save');

    // SQL equality on `raw_text` is case-insensitive under MySQL's default collation; SQLite cannot show that, so the
    // statement itself is checked: the comparison is made in PHP.
    expect(SavedDeckList::query()->count())->toBe(2)
        ->and(collect($queries)->filter(fn (string $sql): bool => preg_match('/\bwhere\b.*"?raw_text"?\s*(=|like|in)/i', $sql) === 1)->all())->toBe([]);
});

/**
 * Counts every call to the bulk restock action, passing it on.
 */
function countRestockCalls(): object
{
    $spy = new class(app(SetRestockAlerts::class)) extends SetRestockAlerts
    {
        public int $calls = 0;

        public function __construct(private SetRestockAlerts $real) {}

        public function handle(User $user, array $variantIds): int
        {
            $this->calls++;

            return $this->real->handle($user, $variantIds);
        }
    };
    app()->instance(SetRestockAlerts::class, $spy);

    return $spy;
}

test('a guest alert replays once after a storefront login, not on every visit', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);
    $user = User::factory()->create();
    $spy = countRestockCalls();

    Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')->call('alertMissing');
    $pending = session('deck.pending');

    $this->withSession(['deck.pending' => $pending, 'url.intended' => route('deck.import')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('deck.import'));

    expect(session()->has('deck.pending'))->toBeFalse();

    $this->get(route('deck.import'));
    $this->get(route('deck.import'));
    $this->get(route('deck.import'));

    expect($spy->calls)->toBe(1);
});

test('a login that is not the storefront one never replays what a guest left, and clears it', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);
    $spy = countRestockCalls();
    $pending = ['raw' => '2x OP05-119', 'game' => $shop['onePiece']->id, 'language' => null, 'alert' => true, 'at' => now()->timestamp];

    $this->actingAs(User::factory()->create())->withSession(['deck.pending' => $pending])->get(route('deck.import'))
        ->assertDontSee(__('storefront.deck.summary_title'));

    $this->get(route('deck.import'));

    expect($spy->calls)->toBe(0)
        ->and(session()->has('deck.pending'))->toBeFalse()
        ->and(RestockAlert::query()->count())->toBe(0);
});

test('a restore payload older than 15 minutes is ignored', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);
    $user = User::factory()->create();
    $spy = countRestockCalls();
    $payload = fn (int $minutes): array => ['raw' => '2x OP05-119', 'game' => $shop['onePiece']->id, 'language' => null, 'alert' => true, 'at' => now()->subMinutes($minutes)->timestamp];

    $this->actingAs($user)->withSession(['deck.restore' => $payload(16)])->get(route('deck.import'))
        ->assertDontSee(__('storefront.deck.summary_title'));

    expect($spy->calls)->toBe(0);

    $this->withSession(['deck.restore' => $payload(14)])->get(route('deck.import'))
        ->assertSee(__('storefront.deck.summary_title'));

    expect($spy->calls)->toBe(1);
});

test('staff cannot set the bulk alert', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);

    Livewire::actingAs(User::factory()->staff()->create())->test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')
        ->call('alertMissing')
        ->assertSet('alertSet', false)
        ->assertSee((new AlertRefused('unavailable'))->message());

    expect(RestockAlert::query()->count())->toBe(0);
});

test('a full row shows what is left after earlier lines, not the shop stock', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 5, 10000);

    Livewire::test(DeckImport::class)->set('raw', "1x OP05-119\n1x OP05-119")->call('check')
        ->assertSee(__('storefront.deck.have', ['n' => 5]))
        ->assertSee(__('storefront.deck.have', ['n' => 4]));
});

test('with nothing to add there is no add-all button and no phone bar, and a forced call says why', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);

    Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')
        ->assertDontSee('id="deck-add-bar"', false)
        ->assertDontSee(__('storefront.deck.add_all_short'))
        ->call('addAll', '')
        ->assertSet('addNotice', __('storefront.deck.nothing_to_add'));
});

test('the stale line sits under the bulk alert button, and the summary card is the scroll target', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);

    Livewire::test(DeckImport::class)->set('raw', '2x OP05-119')->call('check')
        ->assertSee('x-show="dirty || false" x-cloak class="basis-full text-small font-medium text-low">'.__('storefront.deck.stale'), false)
        ->assertSee("(dirty || false) && 'opacity-60'", false)
        ->assertSee('x-ref="summary" tabindex="-1" class="scroll-mt-4', false);
});

test('a restore payload is consumed by the page that reads it, even when the session does not expire it', function () {
    $shop = deckShop();
    deckVariant($shop['luffyEn'], 0, 10000);
    $spy = countRestockCalls();
    $payload = ['raw' => '2x OP05-119', 'game' => $shop['onePiece']->id, 'language' => null, 'alert' => true, 'at' => now()->timestamp];

    $this->actingAs(User::factory()->create())->withSession(['deck.restore' => $payload])->get(route('deck.import'));
    $this->get(route('deck.import'));
    $this->get(route('deck.import'));

    expect($spy->calls)->toBe(1)->and(session()->has('deck.restore'))->toBeFalse();
});

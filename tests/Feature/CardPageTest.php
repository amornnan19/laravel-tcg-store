<?php

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Livewire\Storefront\VariantTable;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\PricePoint;
use App\Models\Printing;
use App\Models\Variant;
use App\Support\PriceChart;
use Database\Seeders\PricePointSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Each Livewire render leaves cyclic garbage behind; collecting it per test keeps the whole suite inside the default memory limit.
afterEach(fn () => gc_collect_cycles());

function cardPageCard(string $name = 'Charizard ex'): Card
{
    return Card::factory()->for(Game::factory())->create(['name_en' => $name]);
}

function cardPagePrinting(Card $card, ?CardSet $set = null, Language $language = Language::En, array $attributes = []): Printing
{
    return Printing::factory()
        ->for($card)
        ->for($set ?? CardSet::factory()->for($card->game), 'set')
        ->create(['language' => $language, 'rarity_code' => 'sar', 'has_foil' => true, ...$attributes]);
}

function cardPageVariant(Printing $printing, CardCondition $condition = CardCondition::Nm, Finish $finish = Finish::Normal, int $qty = 5, ?int $priceSatang = 100000, array $attributes = []): Variant
{
    return Variant::factory()->for($printing)->create([
        'condition' => $condition,
        'finish' => $finish,
        'qty' => $qty,
        'price_satang' => $priceSatang,
        ...$attributes,
    ]);
}

function cardPageTable(Card $card, Printing $printing): Testable
{
    return Livewire::test(VariantTable::class, ['cardId' => $card->id, 'printingId' => $printing->id]);
}

/**
 * A card with a JP and an EN printing: EN is cheaper but ordered second by the enum, JP has a sold-out NM row.
 *
 * @return array{card: Card, ja: Printing, en: Printing, jaNm: Variant, jaLp: Variant, enNm: Variant, enLp: Variant}
 */
function cardPageTwoLanguages(): array
{
    $card = cardPageCard();
    $set = CardSet::factory()->for($card->game)->create(['code' => 'SV-3']);
    $ja = cardPagePrinting($card, $set, Language::Ja, ['number' => '215']);
    $en = cardPagePrinting($card, $set, Language::En, ['number' => '215']);

    return [
        'card' => $card,
        'ja' => $ja,
        'en' => $en,
        'jaNm' => cardPageVariant($ja, CardCondition::Nm, qty: 0, priceSatang: 300000),
        'jaLp' => cardPageVariant($ja, CardCondition::Lp, qty: 4, priceSatang: 250000),
        'enNm' => cardPageVariant($en, CardCondition::Nm, qty: 6, priceSatang: 198000),
        'enLp' => cardPageVariant($en, CardCondition::Lp, qty: 2, priceSatang: 162000),
    ];
}

test('the card page shows the card, its rows and the sample caption', function () {
    $card = cardPageCard();
    $printing = cardPagePrinting($card);
    cardPageVariant($printing, qty: 3, priceSatang: 429000, attributes: ['note_th' => 'ภาพจริงทุกใบ']);

    $this->get(route('card.show', $card))
        ->assertOk()
        ->assertSee('Charizard ex')
        ->assertSee($printing->display_code)
        ->assertSee('ภาพจริงทุกใบ')
        ->assertSee('฿ 4,290')
        ->assertDontSee('wire:click="add');
});

test('?p= selects the printing the header shows', function () {
    $card = cardPageCard();
    $set = CardSet::factory()->for($card->game)->create(['code' => 'SV-3']);
    $first = cardPagePrinting($card, $set, attributes: ['number' => '006']);
    $second = cardPagePrinting($card, $set, attributes: ['number' => '215']);
    cardPageVariant($first, priceSatang: 5000);
    cardPageVariant($second, priceSatang: 900000);

    $this->get(route('card.show', ['card' => $card, 'p' => $second->id]))->assertSee('<title>Charizard ex · SV3-215 · ', false);
    $this->get(route('card.show', $card))->assertSee('<title>Charizard ex · SV3-006 · ', false);
    // A junk value, or a printing of another card, falls back to the default.
    $this->get(route('card.show', ['card' => $card, 'p' => 'abc']))->assertOk()->assertSee('<title>Charizard ex · SV3-006 · ', false);
    $this->get(route('card.show', ['card' => $card, 'p' => cardPagePrinting(cardPageCard('Other'))->id]))->assertOk()->assertSee('<title>Charizard ex · SV3-006 · ', false);
});

test('a trashed card, an inactive game and a card with nothing visible are a 404', function () {
    $card = cardPageCard();
    cardPageVariant(cardPagePrinting($card));
    $this->get(route('card.show', $card))->assertOk();

    $inactive = cardPageCard('Hidden Game Card');
    cardPageVariant(cardPagePrinting($inactive));
    $inactive->game->update(['is_active' => false]);
    $this->get(route('card.show', $inactive))->assertNotFound();

    $trashed = cardPageCard('Trashed Card');
    cardPageVariant(cardPagePrinting($trashed));
    $trashed->delete();
    $this->get(route('card.show', $trashed->slug))->assertNotFound();

    $this->get(route('card.show', cardPageCard('No Printings')))->assertNotFound();
});

test('the foil sheen shows on the large art only when a foil copy is available', function () {
    $card = cardPageCard();
    $printing = cardPagePrinting($card);
    cardPageVariant($printing);
    cardPageVariant($printing, finish: Finish::Foil, qty: 0);

    $this->get(route('card.show', $card))->assertDontSee('foil', false);

    Variant::query()->where('finish', Finish::Foil->value)->update(['qty' => 2]);

    $this->get(route('card.show', $card))->assertSee('foil', false);
});

test('tabs show the languages of the card and the page opens on the printing language', function () {
    ['card' => $card, 'ja' => $ja, 'en' => $en] = cardPageTwoLanguages();

    cardPageTable($card, $en)->assertSet('language', 'en')->assertSee('aria-label="'.__('storefront.card.language_group').'"', false);
    cardPageTable($card, $ja)->assertSet('language', 'ja');

    $single = cardPageCard('One Language');
    cardPageVariant(cardPagePrinting($single));
    cardPageTable($single, $single->printings->first())->assertDontSee(__('storefront.card.language_group'));
});

test('changing the tab selects the cheapest available row of that language and resets the quantity', function () {
    ['card' => $card, 'en' => $en, 'jaLp' => $jaLp, 'enLp' => $enLp, 'enNm' => $enNm] = cardPageTwoLanguages();

    cardPageTable($card, $en)
        ->assertSet('variantId', $enLp->id)
        ->call('selectVariant', $enNm->id)
        ->call('increment')->call('increment')
        ->assertSet('qty', 3)
        ->call('selectLanguage', 'ja')
        ->assertSet('language', 'ja')
        ->assertSet('variantId', $jaLp->id)
        ->assertSet('qty', 1)
        ->call('selectLanguage', 'en')
        ->assertSet('variantId', $enLp->id)
        ->assertSet('qty', 1);
});

test('a language the card does not have is ignored', function () {
    ['card' => $card, 'en' => $en] = cardPageTwoLanguages();

    cardPageTable($card, $en)->call('selectLanguage', 'th')->assertSet('language', 'en')->call('selectLanguage', 'nonsense')->assertSet('language', 'en');
});

test('the stepper stops at the available quantity and says so in a sentence', function () {
    ['card' => $card, 'en' => $en, 'enLp' => $enLp] = cardPageTwoLanguages();

    $table = cardPageTable($card, $en)->assertSet('variantId', $enLp->id)
        ->assertSee(__('storefront.card.cap', ['n' => 2, 'minutes' => config('shop.reservation_minutes')]))
        ->assertDontSee(__('storefront.card.cap_reached', ['n' => 2, 'minutes' => config('shop.reservation_minutes')]))
        ->call('decrement')->assertSet('qty', 1)
        ->call('increment')->assertSet('qty', 2)
        ->assertSee(__('storefront.card.cap_reached', ['n' => 2, 'minutes' => config('shop.reservation_minutes')]))
        ->call('increment')->assertSet('qty', 2);

    // At the cap the plus button is aria-disabled and still focusable: `disabled` would drop focus from it.
    expect($table->html())
        ->toMatch('/<button[^>]*aria-disabled="true"[^>]*aria-label="'.preg_quote(__('storefront.card.increase'), '/').'"/s')
        ->not->toMatch('/<button[^>]*\sdisabled[^>]*aria-label="'.preg_quote(__('storefront.card.increase'), '/').'"/s');
});

test('choosing a row with less stock pulls the quantity down to its cap', function () {
    ['card' => $card, 'en' => $en, 'enLp' => $enLp, 'enNm' => $enNm] = cardPageTwoLanguages();

    cardPageTable($card, $en)->call('selectVariant', $enNm->id)->set('qty', 5)->call('selectVariant', $enLp->id)->assertSet('qty', 2);
});

test('the sticky bar total is the quantity times the price of the selected row', function () {
    ['card' => $card, 'en' => $en, 'enNm' => $enNm] = cardPageTwoLanguages();

    cardPageTable($card, $en)
        ->call('selectVariant', $enNm->id)
        ->call('increment')->call('increment')
        ->assertSee('฿ 5,940')
        ->assertSee(__('storefront.card.selected_line', ['variant' => 'NM · EN · '.Finish::Normal->label(), 'qty' => 3]));
});

test('the add button sits beside the total and the cap sentence carries the lock line', function () {
    ['card' => $card, 'en' => $en] = cardPageTwoLanguages();

    $html = cardPageTable($card, $en)->assertSee(__('storefront.card.total'))->html();

    expect(substr_count($html, 'wire:click="addToCart"'))->toBe(2)
        ->and($html)->toContain(__('storefront.card.add'))
        ->and($html)->toContain('ล็อกสต็อกให้ '.config('shop.reservation_minutes').' นาทีเมื่อกดซื้อ');
});

test('no add button is rendered when nothing in the language can be bought', function () {
    ['card' => $card, 'ja' => $ja, 'jaLp' => $jaLp] = cardPageTwoLanguages();
    $jaLp->update(['qty' => 0]);

    expect(cardPageTable($card, $ja)->html())->not->toContain('wire:click="addToCart"');
});

test('sold-out and unpriced rows stay visible, dimmed, and cannot be chosen', function () {
    ['card' => $card, 'ja' => $ja, 'jaNm' => $jaNm, 'jaLp' => $jaLp] = cardPageTwoLanguages();
    $unpriced = cardPageVariant($ja, CardCondition::Mp, qty: 3, priceSatang: null);

    $table = cardPageTable($card, $ja);

    expect($table->html())
        ->toMatch('/<button[^>]*wire:key="row-'.$jaNm->id.'"[^>]*disabled/s')
        ->toMatch('/wire:key="row-'.$jaNm->id.'"[^>]*opacity-55/s');
    $table->assertSee(__('storefront.stock.out_of_stock'))->assertSee(__('storefront.card.no_price'))
        ->call('selectVariant', $jaNm->id)->assertSet('variantId', $jaLp->id)
        ->call('selectVariant', $unpriced->id)->assertSet('variantId', $jaLp->id);
});

test('a language with nothing to buy shows the sold-out sentence and no bar', function () {
    $card = cardPageCard();
    $printing = cardPagePrinting($card);
    cardPageVariant($printing, qty: 0);

    cardPageTable($card, $printing)->assertSee(__('storefront.card.none_available'))->assertDontSee(__('storefront.card.total'))->assertSee(__('storefront.stock.out_of_stock'));
});

test('rows are grouped per printing when a language has more than one', function () {
    $card = cardPageCard();
    $set = CardSet::factory()->for($card->game)->create(['code' => 'SV-3']);
    $one = cardPagePrinting($card, $set, attributes: ['number' => '006', 'rarity_code' => 'rr']);
    $two = cardPagePrinting($card, $set, attributes: ['number' => '215']);
    cardPageVariant($one);
    cardPageVariant($two);

    cardPageTable($card, $one)->assertSee('wire:key="group-'.$one->id.'"', false)->assertSee('wire:key="group-'.$two->id.'"', false);

    $solo = cardPageCard('Solo');
    $only = cardPagePrinting($solo, attributes: ['number' => '001']);
    cardPageVariant($only);

    cardPageTable($solo, $only)->assertDontSee('wire:key="group-', false);
});

test('the component shows nothing of a card whose game has been switched off', function () {
    $card = cardPageCard();
    $printing = cardPagePrinting($card);
    cardPageVariant($printing, priceSatang: 777700);
    $card->game->update(['is_active' => false]);

    cardPageTable($card, $printing)->assertDontSee('฿ 7,777')->assertDontSee('wire:key="row-', false);
});

test('component state cannot be pointed at another card', function () {
    ['card' => $card, 'en' => $en] = cardPageTwoLanguages();

    cardPageTable($card, $en)->set('cardId', 999);
})->throws(CannotUpdateLockedPropertyException::class);

test('a tampered variant id falls back to a row that can be bought', function () {
    ['card' => $card, 'en' => $en, 'enLp' => $enLp] = cardPageTwoLanguages();
    $foreign = cardPageVariant(cardPagePrinting(cardPageCard('Foreign')));

    cardPageTable($card, $en)
        ->set('variantId', $foreign->id)->assertSee('฿ 1,620')->assertDontSee('฿ 1,000')
        ->set('variantId', $enLp->id)->call('selectVariant', $foreign->id)->assertSet('variantId', $enLp->id);
});

test('calls bundled in one request follow the newest selection', function () {
    ['card' => $card, 'ja' => $ja, 'en' => $en, 'jaLp' => $jaLp, 'enNm' => $enNm, 'enLp' => $enLp] = cardPageTwoLanguages();

    // increment reads the selected row, the cap and the chart; the next call changes what they derive from.
    $table = cardPageTable($card, $en)->update(calls: [
        ['path' => '', 'method' => 'increment', 'params' => []],
        ['path' => '', 'method' => 'selectVariant', 'params' => [$enNm->id]],
    ]);

    expect($table->html())
        ->toMatch('/wire:key="row-'.$enNm->id.'"[^>]*aria-checked="true"/s')
        ->toMatch('/wire:key="row-'.$enLp->id.'"[^>]*aria-checked="false"/s');
    $table->assertSee('฿ 3,960')->assertSee(__('storefront.card.cap', ['n' => 6, 'minutes' => config('shop.reservation_minutes')]));

    $table = cardPageTable($card, $en)->update(calls: [
        ['path' => '', 'method' => 'increment', 'params' => []],
        ['path' => '', 'method' => 'selectLanguage', 'params' => ['ja']],
    ]);

    expect($table->html())->toMatch('/wire:key="row-'.$jaLp->id.'"[^>]*aria-checked="true"/s');
    $table->assertSet('language', 'ja')->assertSet('qty', 1)->assertSee('฿ 2,500')->assertSee(__('storefront.card.cap', ['n' => 4, 'minutes' => config('shop.reservation_minutes')]));
});

test('rows have one tab stop, the selected row', function () {
    ['card' => $card, 'en' => $en, 'enLp' => $enLp, 'enNm' => $enNm] = cardPageTwoLanguages();

    expect(cardPageTable($card, $en)->html())
        ->toMatch('/wire:key="row-'.$enLp->id.'"[^>]*tabindex="0"/s')
        ->toMatch('/wire:key="row-'.$enNm->id.'"[^>]*tabindex="-1"/s');
});

test('the header printing is the first group and an empty note shows a dash', function () {
    $card = cardPageCard();
    $set = CardSet::factory()->for($card->game)->create(['code' => 'SV-3']);
    $one = cardPagePrinting($card, $set, attributes: ['number' => '006']);
    $two = cardPagePrinting($card, $set, attributes: ['number' => '215']);
    cardPageVariant($one);
    cardPageVariant($two);

    cardPageTable($card, $two)->assertSeeInOrder(['SV3-215', 'SV3-006'])->assertSee(__('storefront.card.no_note'));
    cardPageTable($card, $one)->assertSeeInOrder(['SV3-006', 'SV3-215']);
});

test('the header summary describes the printing in the header', function () {
    $card = cardPageCard();
    $set = CardSet::factory()->for($card->game)->create(['code' => 'SV-3']);
    $cheap = cardPagePrinting($card, $set, attributes: ['number' => '006']);
    $dear = cardPagePrinting($card, $set, attributes: ['number' => '215']);
    cardPageVariant($cheap, qty: 3, priceSatang: 5000);
    cardPageVariant($dear, qty: 4, priceSatang: 900000);
    cardPageVariant($dear, CardCondition::Lp, qty: 2, priceSatang: 700000);

    $this->get(route('card.show', ['card' => $card, 'p' => $dear->id]))
        ->assertSeeInOrder(['฿ 7,000', '&ndash;', '฿ 9,000'], false)->assertSee(__('storefront.card.stock_total', ['n' => 6]));
    $this->get(route('card.show', ['card' => $card, 'p' => $cheap->id]))
        ->assertDontSee('&ndash;', false)->assertSee(__('storefront.card.stock_total', ['n' => 3]));
});

test('the default printing never has only unpriced stock', function () {
    $card = cardPageCard();
    $set = CardSet::factory()->for($card->game)->create(['code' => 'SV-3']);
    $unpriced = cardPagePrinting($card, $set, attributes: ['number' => '001']);
    $priced = cardPagePrinting($card, $set, attributes: ['number' => '002']);
    cardPageVariant($unpriced, qty: 5, priceSatang: null);
    // A cheap sold-out row would win the price sort if stock alone decided.
    cardPageVariant($unpriced, CardCondition::Lp, qty: 0, priceSatang: 100);
    cardPageVariant($priced, qty: 1, priceSatang: 900000);

    $this->get(route('card.show', $card))->assertSee('<title>Charizard ex · SV3-002 · ', false);

    // With nothing priced and available anywhere, the oldest printing is shown.
    Variant::query()->where('printing_id', $priced->id)->update(['qty' => 0]);
    $this->get(route('card.show', $card))->assertOk();
});

test('the info table lists the set, number, rarity, type, languages and traits from data', function () {
    $card = Card::factory()->for(Game::factory())->create(['name_en' => 'Charizard ex', 'card_type' => 'pokemon', 'traits' => ['color' => ['Fire', 'Dark'], 'power' => '330', 'odd_key' => 'x']]);
    $set = CardSet::factory()->for($card->game)->create(['code' => 'SV-3', 'name_en' => 'Obsidian Flames']);
    cardPageVariant(cardPagePrinting($card, $set, Language::Ja, ['number' => '215/197']));
    cardPageVariant(cardPagePrinting($card, $set, Language::En, ['number' => '215/197']));

    $this->get(route('card.show', $card))
        ->assertSeeInOrder([__('storefront.card.info_title'), 'SV-3 Obsidian Flames', '215/197', 'SAR', 'Pokemon', 'JP · EN', __('storefront.card.trait.color'), 'Fire, Dark', __('storefront.card.trait.power'), '330', 'Odd key', 'x']);
});

test('the chart shows the selected variant history and is hidden below two points', function () {
    $card = cardPageCard();
    $printing = cardPagePrinting($card);
    $variant = cardPageVariant($printing, priceSatang: 400000);

    cardPageTable($card, $printing)->assertDontSee(__('storefront.card.chart_title'));

    PricePoint::factory()->for($variant)->create(['on_date' => today()->subDays(2), 'avg_satang' => 380000]);
    cardPageTable($card, $printing)->assertDontSee('<svg', false)->assertDontSee(__('storefront.card.chart_title'));

    PricePoint::factory()->for($variant)->create(['on_date' => today(), 'avg_satang' => 400000]);
    cardPageTable($card, $printing)->assertSee(__('storefront.card.chart_title'))->assertSee('<path d="M2', false);
});

test('the chart caption says sample data only while the flag is on', function () {
    $card = cardPageCard();
    $printing = cardPagePrinting($card);
    $variant = cardPageVariant($printing);
    PricePoint::factory()->for($variant)->create(['on_date' => today()->subDay(), 'avg_satang' => 90000]);
    PricePoint::factory()->for($variant)->create(['on_date' => today(), 'avg_satang' => 100000]);

    config()->set('shop.sample_price_history', true);
    cardPageTable($card, $printing)->assertSee('2 วัน · ข้อมูลตัวอย่าง');

    config()->set('shop.sample_price_history', false);
    cardPageTable($card, $printing)->assertSee('2 วัน')->assertDontSee('ข้อมูลตัวอย่าง');
});

test('the chart axis names its real ends: the first point relative to today and the last point or today', function () {
    $card = cardPageCard();
    $printing = cardPagePrinting($card);
    $variant = cardPageVariant($printing);
    PricePoint::factory()->for($variant)->create(['on_date' => today()->subDays(10), 'avg_satang' => 90000]);
    PricePoint::factory()->for($variant)->create(['on_date' => today()->subDays(3), 'avg_satang' => 100000]);

    cardPageTable($card, $printing)
        ->assertSee(__('storefront.card.days_ago', ['n' => 10]))
        ->assertSee(today()->subDays(3)->locale('th')->translatedFormat('j M'))
        ->assertSee('11 วัน · ข้อมูลตัวอย่าง')
        ->assertDontSee(__('storefront.card.today'));

    PricePoint::factory()->for($variant)->create(['on_date' => today(), 'avg_satang' => 120000]);

    cardPageTable($card, $printing)->assertSee(__('storefront.card.today'))->assertSee('฿ 900')->assertSee('฿ 1,200');
});

test('the chart ignores history older than 90 days', function () {
    $card = cardPageCard();
    $printing = cardPagePrinting($card);
    $variant = cardPageVariant($printing);
    PricePoint::factory()->for($variant)->create(['on_date' => today()->subDays(120), 'avg_satang' => 50000]);
    PricePoint::factory()->for($variant)->create(['on_date' => today()->subDays(100), 'avg_satang' => 60000]);

    cardPageTable($card, $printing)->assertDontSee('<svg', false);
});

test('chart geometry puts the newest price at the right edge and the highest price at the top', function () {
    $points = collect([
        PricePoint::factory()->make(['on_date' => today()->subDays(60), 'avg_satang' => 100000]),
        PricePoint::factory()->make(['on_date' => today()->subDays(30), 'avg_satang' => 200000]),
        PricePoint::factory()->make(['on_date' => today(), 'avg_satang' => 150000]),
    ]);

    $chart = PriceChart::make($points);

    expect($chart['path'])->toStartWith('M2 84')
        ->and($chart['end_x'])->toBe(324.0)
        ->and($chart['days'])->toBe(60)
        ->and($chart['change_percent'])->toBe(-25.0)
        ->and($chart['low_satang'])->toBe(100000)
        ->and($chart['high_satang'])->toBe(200000)
        ->and(PriceChart::make($points->take(1)))->toBeNull();
});

test('other cards of the set come in number order, twelve at most, from the same language', function () {
    $card = cardPageCard();
    $set = CardSet::factory()->for($card->game)->create(['code' => 'SV-3']);
    $current = cardPagePrinting($card, $set, attributes: ['number' => '100']);
    cardPageVariant($current);

    foreach ([30, 5, 200, ...range(101, 112)] as $number) {
        $other = cardPagePrinting(cardPageCard('Card '.str_pad((string) $number, 3, '0', STR_PAD_LEFT)), $set, attributes: ['number' => (string) $number]);
        cardPageVariant($other);
    }

    cardPagePrinting(cardPageCard('Japanese Twin'), $set, Language::Ja, ['number' => '1']);

    $html = $this->get(route('card.show', $card))->assertOk()->assertSee('SV3-5', false)->content();

    expect(substr_count($html, 'href="'.url('/c/').'/card-'))->toBe(12)
        ->and($html)->not->toContain('Japanese Twin')
        ->and($html)->not->toContain('Card 200')
        ->and(strpos($html, 'Card 005'))->toBeLessThan(strpos($html, 'Card 030'))
        ->and(strpos($html, 'Card 030'))->toBeLessThan(strpos($html, 'Card 101'));
});

test('the card page runs a bounded number of queries however many other cards there are', function () {
    $card = cardPageCard();
    $set = CardSet::factory()->for($card->game)->create();
    cardPageVariant(cardPagePrinting($card, $set, attributes: ['number' => '1']));
    cardPageVariant(cardPagePrinting(cardPageCard('Card 0'), $set, attributes: ['number' => '0']));

    $queries = function () use ($card): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('card.show', $card))->assertOk();

        return count(DB::getQueryLog());
    };

    $few = $queries();

    foreach (range(2, 14) as $number) {
        cardPageVariant(cardPagePrinting(cardPageCard('Card '.$number), $set, attributes: ['number' => (string) $number]));
    }

    $many = $queries();

    expect($many)->toBe($few + 0)->and($many)->toBeLessThanOrEqual(22);
});

test('the sample history seeder gives NM variants of rr, sar and sec 90 days around the current price', function () {
    $card = cardPageCard();
    $sar = cardPagePrinting($card, attributes: ['rarity_code' => 'sar']);
    $common = cardPagePrinting(cardPageCard('Common'), attributes: ['rarity_code' => 'c']);
    $nm = cardPageVariant($sar, priceSatang: 400000);
    $lp = cardPageVariant($sar, CardCondition::Lp, priceSatang: 300000);
    $unpriced = cardPageVariant(cardPagePrinting(cardPageCard('Unpriced'), attributes: ['rarity_code' => 'sec']), priceSatang: null);
    $plain = cardPageVariant($common);

    config(['shop.demo_data' => true]);
    $this->seed(PricePointSeeder::class);

    $rows = PricePoint::query()->where('variant_id', $nm->id)->orderBy('on_date')->get();

    expect($rows)->toHaveCount(90)
        ->and($rows->last()->on_date->isToday())->toBeTrue()
        ->and($rows->last()->avg_satang)->toBe(400000)
        ->and($rows->min('avg_satang'))->toBeGreaterThanOrEqual(340000)
        ->and($rows->max('avg_satang'))->toBeLessThanOrEqual(460000)
        ->and($rows->every(fn (PricePoint $row): bool => $row->low_satang <= $row->avg_satang && $row->avg_satang <= $row->high_satang && $row->sold_qty >= 0 && $row->sold_qty <= 3))->toBeTrue()
        ->and(PricePoint::query()->whereIn('variant_id', [$lp->id, $unpriced->id, $plain->id])->count())->toBe(0);

    config(['shop.demo_data' => true]);
    $this->seed(PricePointSeeder::class);
    expect(PricePoint::query()->where('variant_id', $nm->id)->count())->toBe(90);
});

test('a printing alone in its set and language still links to its set page, from the meta line and a browse link', function () {
    $card = cardPageCard('Lonely Card');
    $printing = cardPagePrinting($card);
    cardPageVariant($printing);

    $this->get(route('card.show', $card))
        ->assertOk()
        ->assertDontSee(__('storefront.card.other_cards', ['code' => $printing->set->display_code]))
        ->assertSeeHtml('<a href="'.route('set.show', $printing->set).'" class="inline-flex min-h-11 items-center text-small text-accent hover:text-ink">'.__('storefront.home.browse_set').'</a>')
        ->assertSeeHtml('<a href="'.route('set.show', $printing->set).'" class="inline-flex min-h-11 items-center rounded-tag tabular-nums underline decoration-line-strong underline-offset-4 hover:text-ink">'.$printing->display_code.'</a>');
});

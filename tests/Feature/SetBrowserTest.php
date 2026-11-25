<?php

use App\Enums\Language;
use App\Livewire\Storefront\SetBrowser;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

function browsableSet(int $cardCount = 120, string $code = 'op-05'): CardSet
{
    return CardSet::factory()->for(Game::factory())->create(['code' => $code, 'card_count' => $cardCount, 'name_en' => 'Awakening']);
}

function browsablePrinting(CardSet $set, string $number, string $name = 'Some Card', int $qty = 3, Language $language = Language::En, string $rarity = 'c'): Printing
{
    $printing = Printing::factory()
        ->for($set, 'set')
        ->for(Card::factory()->for($set->game)->create(['name_en' => $name]))
        ->create(['number' => $number, 'language' => $language, 'rarity_code' => $rarity]);
    Variant::factory()->for($printing)->create(['qty' => $qty, 'price_satang' => 5000]);

    return $printing;
}

function browser(CardSet $set, array $query = [])
{
    return Livewire::withQueryParams($query)->test(SetBrowser::class, ['setId' => $set->id]);
}

test('the set page renders the header and breadcrumb for the stored code', function () {
    $set = browsableSet();
    browsablePrinting($set, '001', 'Luffy');

    $this->get(route('set.show', $set))
        ->assertOk()
        ->assertSee('OP-05 — Awakening')
        ->assertSee(route('game.show', $set->game), false)
        ->assertSee('Luffy')
        ->assertSee(__('storefront.home.set_in_stock', ['n' => 1]));
});

test('a set of an inactive game and an unknown code are 404', function () {
    $set = CardSet::factory()->for(Game::factory()->create(['is_active' => false]))->create(['code' => 'hid-01']);

    $this->get(route('set.show', $set))->assertNotFound();
    $this->get('/s/nope')->assertNotFound();
});

test('printings are ordered by the digits of the number, then the number as written', function () {
    $set = browsableSet();
    foreach (['10', '2', '062', '215/197', '1', 'P-50'] as $number) {
        browsablePrinting($set, $number, 'Card '.str_replace('/', '-', $number));
    }

    // 'P-50' is number 50: a plain string or natural sort would put it after '215/197'.
    browser($set)->assertSeeInOrder(['Card 1<', 'Card 2<', 'Card 10<', 'Card P-50', 'Card 062', 'Card 215-197'], false);
});

test('range chips are fifty numbers wide, end at card_count, and add an extras chip', function () {
    $set = browsableSet(120);
    foreach (['001', '050', '051', '101', '120', '125', '134'] as $number) {
        browsablePrinting($set, $number, 'N'.$number);
    }

    browser($set)
        ->assertSee('001–050')
        ->assertSee('051–100')
        ->assertSee('101–120')
        ->assertSee('125–134 · '.__('storefront.set.extras'))
        ->call('selectRange', 'extra')
        ->assertSee('N125')
        ->assertSee('N134')
        ->assertDontSee('N120')
        ->call('selectRange', '1')
        ->assertSee('N051')
        ->assertDontSee('N050')
        ->assertDontSee('N101')
        ->call('selectRange', '1')
        ->assertSet('range', '')
        ->assertSee('N001');
});

test('a range that is not a chip of the set is dropped', function () {
    $set = browsableSet(60);
    browsablePrinting($set, '001', 'Only One');

    browser($set, ['range' => '0'])->assertSet('range', '0')->assertSee('Only One');
    browser($set, ['range' => '999'])->assertViewHas('activeRange', '')->assertViewHas('isFiltered', false)->assertSee('Only One');
});

test('the in stock switch is off by default and hides sold out cards when on', function () {
    $set = browsableSet();
    browsablePrinting($set, '001', 'Have It');
    browsablePrinting($set, '002', 'Sold Out One', qty: 0);

    browser($set)
        ->assertSet('inStockOnly', false)
        ->assertSee('Sold Out One')
        ->assertSee(__('storefront.stock.out_of_stock'))
        ->set('inStockOnly', true)
        ->assertSee('Have It')
        ->assertDontSee('Sold Out One');

    browser($set, ['stock' => '1'])->assertDontSee('Sold Out One');
});

test('the in-set search matches a card name or part of a number', function () {
    $set = browsableSet();
    browsablePrinting($set, '001', 'Monkey D. Luffy');
    browsablePrinting($set, '190', 'Zoro');
    browsablePrinting($set, '191', 'Nami');

    browser($set)
        ->set('query', 'luffy')
        ->assertSee('Monkey D. Luffy')
        ->assertDontSee('Zoro')
        ->set('query', '19')
        ->assertSee('Zoro')
        ->assertSee('Nami')
        ->assertDontSee('Luffy')
        ->set('query', 'nobody')
        ->assertSee(__('storefront.set.empty'))
        ->call('clearFilters')
        ->assertSet('query', '')
        ->assertSee('Luffy');

    browser($set, ['q' => 'zoro'])->assertSee('Zoro')->assertDontSee('Nami');
});

test('every language is shown, labelled only when the set has more than one', function () {
    $set = browsableSet();
    browsablePrinting($set, '001', 'English Card');
    $single = browser($set)->html();
    expect($single)->not->toContain('001 · EN');

    browsablePrinting($set, '001', 'Japanese Card', language: Language::Ja);

    browser($set)->assertSee('English Card')->assertSee('Japanese Card')->assertSee('001 · EN')->assertSee('001 · JP');
});

test('a trashed card is not listed', function () {
    $set = browsableSet();
    browsablePrinting($set, '001', 'Visible One');
    browsablePrinting($set, '002', 'Trashed One')->card->delete();

    browser($set)->assertSee('Visible One')->assertDontSee('Trashed One');
});

test('load more shows the next forty-eight and names their numbers', function () {
    $set = browsableSet(200);
    foreach (range(1, 100) as $n) {
        browsablePrinting($set, str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'Card '.$n);
    }

    $component = browser($set)
        ->assertSee('Card 48')
        ->assertDontSee('Card 49')
        ->assertSee(__('storefront.set.load_numbers', ['from' => '049', 'to' => '096']));

    $component->call('loadMore')->assertSee('Card 96')->assertDontSee('Card 97')
        ->assertSee(__('storefront.set.load_numbers', ['from' => '097', 'to' => '100']));

    $component->call('loadMore')->assertSee('Card 100')->assertViewHas('next', null)->assertDontSee('โหลดเลข');
});

test('changing a filter starts again from the first page', function () {
    $set = browsableSet(200);
    foreach (range(1, 60) as $n) {
        browsablePrinting($set, str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'Card '.$n);
    }

    browser($set)->call('loadMore')->assertSet('pages', 2)->set('query', 'Card')->assertSet('pages', 1);
});

test('the query count does not grow with the number of tiles', function () {
    $small = browsableSet(200, 'sm-01');
    foreach (range(1, 6) as $n) {
        browsablePrinting($small, (string) $n, 'S'.$n);
    }
    $large = browsableSet(200, 'lg-01');
    foreach (range(1, 48) as $n) {
        browsablePrinting($large, (string) $n, 'L'.$n);
    }

    DB::enableQueryLog();
    $this->get(route('set.show', $small))->assertOk();
    $few = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->get(route('set.show', $large))->assertOk()->assertSee('L48');
    $many = count(DB::getQueryLog());

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(16);
});

test('tampered properties fall back to safe values', function () {
    $set = browsableSet();
    browsablePrinting($set, '001', 'Card One');

    browser($set, ['q' => ['x'], 'range' => 'drop table', 'stock' => 'maybe'])
        ->assertSet('query', '')
        ->assertSet('range', '')
        ->assertSet('inStockOnly', false)
        ->assertSee('Card One');
});

test('the search text keeps what was typed, a trailing space included, and still filters by the trimmed text', function () {
    $set = browsableSet();
    browsablePrinting($set, '001', 'Monkey D. Luffy');
    browsablePrinting($set, '002', 'Zoro');

    browser($set)
        ->set('query', 'Monkey ')
        ->assertSet('query', 'Monkey ')
        ->assertSee('Luffy')
        ->assertDontSee('Zoro');
});

test('a code shared by two games resolves to the same set every time', function () {
    $inactive = CardSet::factory()->for(Game::factory()->create(['is_active' => false]))->create(['code' => 'dup-01', 'name_en' => 'Hidden Twin']);
    $first = CardSet::factory()->for(Game::factory())->create(['code' => 'dup-01', 'name_en' => 'First Twin']);
    $second = CardSet::factory()->for(Game::factory())->create(['code' => 'dup-01', 'name_en' => 'Second Twin']);

    $this->get('/s/dup-01')->assertOk()->assertSee('First Twin')->assertDontSee('Hidden Twin')->assertDontSee('Second Twin');

    $first->game->update(['is_active' => false]);
    $this->get('/s/dup-01')->assertOk()->assertSee('Second Twin');

    $second->game->update(['is_active' => false]);
    $this->get('/s/dup-01')->assertNotFound();
});

test('number 000 belongs to the first chip, which starts at 000', function () {
    $set = browsableSet(101);
    foreach (['000', '001', '050', '051'] as $number) {
        browsablePrinting($set, $number, 'N'.$number);
    }

    browser($set)
        ->assertSee('000–050')
        ->assertSee('051–100')
        ->call('selectRange', '0')
        ->assertSee('N000')
        ->assertSee('N050')
        ->assertDontSee('N051');
});

test('chips come from the numbers that exist, whatever card_count says', function () {
    $zero = browsableSet(0, 'zero-01');
    foreach (['1', '75'] as $number) {
        browsablePrinting($zero, $number, 'Z'.$number);
    }
    $huge = browsableSet(100, 'huge-01');
    browsablePrinting($huge, '1', 'H1');
    browsablePrinting($huge, '99999999', 'H99');

    browser($zero)->assertSee('001–050')->assertSee('051–075');

    $started = microtime(true);
    browser($huge)->assertSee('001–050')->assertSee('99999999–99999999 · '.__('storefront.set.extras'));
    expect(microtime(true) - $started)->toBeLessThan(2.0);
});

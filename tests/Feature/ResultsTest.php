<?php

use App\Enums\CardCondition;
use App\Enums\Language;
use App\Livewire\Storefront\FilterPanel;
use App\Livewire\Storefront\ResultGrid;
use App\Livewire\Storefront\SearchBox;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\Variant;
use App\Search\CatalogueResults;
use App\Search\ResultFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Meilisearch\Client as MeilisearchClient;
use Tests\Support\FakeResultEngine;

uses(RefreshDatabase::class);

function resultPrinting(CardSet $set, string $name, int $qty = 3, int $priceSatang = 129000, array $printing = []): Printing
{
    $record = Printing::factory()->for($set, 'set')->for(Card::factory()->for($set->game)->create(['name_en' => $name]))->create($printing);
    Variant::factory()->for($record)->create(['qty' => $qty, 'price_satang' => $priceSatang]);

    return $record;
}

function useResultEngine(FakeResultEngine $engine): FakeResultEngine
{
    app(EngineManager::class)->extend('results', fn () => $engine);
    config()->set('scout.driver', 'results');

    return $engine;
}

/**
 * What the filter panel draws for a results page: the panel data ResultGrid builds, rendered by FilterPanel itself.
 *
 * @param  array<string, mixed>  $query
 */
function panelHtml(array $query = [], ?string $slug = null): string
{
    $panel = Livewire::withQueryParams($query)->test(ResultGrid::class, ['gameSlug' => $slug])->viewData('panel');

    return Livewire::test(FilterPanel::class, ['panel' => $panel])->html();
}

function useBrokenEngine(): FakeResultEngine
{
    $engine = new class extends FakeResultEngine
    {
        public int $attempts = 0;

        public function search(Builder $builder): never
        {
            $this->attempts++;

            throw new RuntimeException('Meilisearch is down');
        }
    };

    app(EngineManager::class)->extend('broken', fn () => $engine);
    config()->set('scout.driver', 'broken');

    return $engine;
}

/**
 * @return array{game: Game, set: CardSet}
 */
function resultShop(): array
{
    $game = Game::factory()->create(['slug' => 'one-piece', 'name_en' => 'One Piece', 'filters' => ['rarity', 'card_type', 'color', 'language']]);

    return ['game' => $game, 'set' => CardSet::factory()->for($game)->create(['code' => 'OP-05', 'name_en' => 'Awakening of the New Era'])];
}

test('the search route renders the results with the tiles of what is in stock', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Monkey D. Luffy');
    useResultEngine(new FakeResultEngine);

    $this->get(route('search', ['q' => 'luffy']))
        ->assertOk()
        ->assertSeeLivewire(ResultGrid::class)
        ->assertSeeLivewire(FilterPanel::class)
        ->assertSee('Monkey D. Luffy')
        ->assertSee('<title>'.__('storefront.results.title_query', ['query' => 'luffy']).' · ', false);
});

test('in stock only is on by default and the soldout parameter turns it off', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Monkey D. Luffy');
    $engine = useResultEngine(new FakeResultEngine);

    Livewire::test(ResultGrid::class)->assertSet('soldout', false);
    expect($engine->requests[0]['options']['filter'])->toBe('in_stock = true');

    $engine->requests = [];
    Livewire::withQueryParams(['soldout' => '1'])->test(ResultGrid::class)->assertSet('soldout', true);
    expect($engine->requests[0]['options'])->not->toHaveKey('filter');
});

test('the filters in the URL become the filter string, and the text goes to the engine', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Monkey D. Luffy');
    $engine = useResultEngine(new FakeResultEngine);

    Livewire::withQueryParams([
        'q' => 'luffy',
        'set' => ['OP-05'],
        'rarity' => ['sar', 'sec'],
        'language' => ['ja'],
        'condition' => ['nm'],
        'min' => '500',
        'max' => '5000',
        'foil' => '1',
        'sort' => 'price_asc',
        'view' => 'list',
    ])->test(ResultGrid::class)
        ->assertSet('query', 'luffy')
        ->assertSet('set', ['OP-05'])
        ->assertSet('rarity', ['sar', 'sec'])
        ->assertSet('min', 500)
        ->assertSet('max', 5000)
        ->assertSet('foil', true)
        ->assertSet('sort', 'price_asc')
        ->assertSet('view', 'list');

    $main = $engine->requests[0];

    expect($main['query'])->toBe('luffy')
        ->and($main['options']['filter'])->toBe('set_code IN ["OP-05"] AND rarity IN ["sar", "sec"] AND language IN ["ja"] AND conditions IN ["nm"] AND from_satang >= 50000 AND from_satang <= 500000 AND in_stock = true AND has_foil = true')
        ->and($main['orders'])->toBe([['column' => 'from_satang', 'direction' => 'asc']]);
});

test('a hand-edited URL is cleaned instead of breaking the page', function () {
    resultShop();
    $engine = useResultEngine(new FakeResultEngine);

    Livewire::withQueryParams(['rarity' => 'x', 'min' => 'abc', 'max' => '-5', 'language' => ['zz', 'ja'], 'condition' => ['nope'], 'sort' => 'bogus', 'view' => 'x', 'foil' => ['1'], 'q' => ['a']])
        ->test(ResultGrid::class)
        ->assertOk()
        ->assertSet('rarity', ['x'])
        ->assertSet('min', null)
        ->assertSet('max', null)
        ->assertSet('language', ['ja'])
        ->assertSet('condition', [])
        ->assertSet('sort', '')
        ->assertSet('view', 'grid')
        ->assertSet('foil', false)
        ->assertSet('query', '');

    $this->get('/search?rarity=x&min=abc&foil[]=1&sort[]=a&q[]=b')->assertOk();
});

test('toggling an option adds and removes it, and the price takes whole baht only', function () {
    resultShop();
    useResultEngine(new FakeResultEngine);

    Livewire::test(ResultGrid::class)
        ->call('toggle', 'rarity', 'sar')
        ->call('toggle', 'rarity', 'rr')
        ->assertSet('rarity', ['sar', 'rr'])
        ->call('toggle', 'rarity', 'sar')
        ->assertSet('rarity', ['rr'])
        ->call('toggle', 'nonsense', 'x')
        ->call('setPrice', 'min', '1200')
        ->call('setPrice', 'max', '12.5')
        ->assertSet('min', 1200)
        ->assertSet('max', null)
        ->call('toggleFlag', 'stock')
        ->assertSet('soldout', true)
        ->call('clearAll')
        ->assertSet('rarity', [])
        ->assertSet('min', null)
        ->assertSet('soldout', false);
});

test('a page of the game locks the game and has no game filter', function () {
    ['game' => $game, 'set' => $set] = resultShop();
    resultPrinting($set, 'Monkey D. Luffy');
    $engine = useResultEngine(new FakeResultEngine);

    $this->get(route('game.show', $game))
        ->assertOk()
        ->assertSee('<h1', false)
        ->assertSee('One Piece')
        ->assertDontSee('section-game')
        ->assertSee('aria-current="page"', false);

    expect($engine->requests[0]['options']['filter'])->toBe('game IN ["one-piece"] AND in_stock = true')
        ->and($engine->requests[0]['options']['facets'])->not->toContain('game');

    Livewire::test(ResultGrid::class, ['gameSlug' => 'one-piece'])->set('game', ['pokemon'])->assertSet('game', []);
});

test('an inactive or unknown game is a 404', function () {
    $inactive = Game::factory()->create(['is_active' => false]);

    $this->get(route('game.show', $inactive))->assertNotFound();
    $this->get('/g/no-such-game')->assertNotFound();
});

test('the filter panel offers only what the selected games declare, with the engine counts', function () {
    $onePiece = resultShop()['game'];
    $pokemon = Game::factory()->create(['slug' => 'pokemon', 'name_en' => 'Pokemon', 'filters' => ['rarity', 'card_type', 'language']]);
    $engine = useResultEngine(new FakeResultEngine(facets: [
        'game' => ['one-piece' => 3, 'pokemon' => 2],
        'rarity' => ['sar' => 4, 'c' => 9],
        'card_type' => ['leader' => 3],
        'traits.color' => ['Red' => 7],
        'traits.attribute' => ['Slash' => 2],
        'language' => ['ja' => 5, 'en' => 6],
        'conditions' => ['nm' => 8],
    ]));

    $color = __('storefront.results.group.color');
    $type = __('storefront.results.group.type');
    $attribute = __('storefront.results.group.attribute');

    // No game selected: only the generic filters.
    $generic = panelHtml();
    expect($generic)->toContain(__('storefront.results.group.rarity'), __('storefront.results.group.condition'), __('storefront.results.group.game'))
        ->not->toContain($type)->not->toContain($color)
        ->and(preg_replace('/\s+/', ' ', strip_tags($generic)))->toContain('SAR 4')->toContain('JP 5');

    expect($engine->requests[0]['options']['facets'])->toEqualCanonicalizing(['game', 'set_code', 'rarity', 'language', 'conditions']);

    // One Piece declares card type and colour, not attribute.
    expect(panelHtml(slug: $onePiece->slug))->toContain($type)->toContain($color)->toContain('Red')->not->toContain($attribute);

    // Pokemon declares card type only.
    expect(panelHtml(slug: $pokemon->slug))->toContain($type)->not->toContain($color);

    // Two games selected: the colour filter needs both to declare it.
    expect(panelHtml(['game' => ['one-piece', 'pokemon']]))->toContain($type)->not->toContain($color);
});

test('a selected option stays in the panel at zero so it can be switched off', function () {
    resultShop();
    useResultEngine(new FakeResultEngine(facets: ['rarity' => ['c' => 9]]));

    expect(preg_replace('/\s+/', ' ', strip_tags(panelHtml(['rarity' => ['sec']]))))->toContain('C 9')->toContain('SEC 0');
});

test('an option group counts against every other filter but its own', function () {
    resultShop();
    $engine = useResultEngine(new FakeResultEngine);

    Livewire::withQueryParams(['rarity' => ['sar'], 'condition' => ['nm']])->test(ResultGrid::class);

    $byFacet = collect($engine->requests)->mapWithKeys(fn (array $request): array => [implode(',', $request['options']['facets'] ?? []) => $request['options']['filter'] ?? '']);

    expect($byFacet['rarity'])->toBe('conditions IN ["nm"] AND in_stock = true')
        ->and($byFacet['conditions'])->toBe('rarity IN ["sar"] AND in_stock = true')
        ->and($byFacet['game,set_code,type,language'] ?? $byFacet->first(fn ($filter, $facets) => str_contains($facets, 'set_code')))->toBe('rarity IN ["sar"] AND conditions IN ["nm"] AND in_stock = true');
});

test('the empty state suggests removing the filter switched on last, with the count it would bring back', function () {
    resultShop();
    // The two filters together match nothing; either one alone matches printings.
    useResultEngine(new FakeResultEngine(total: fn (string $filter): int => str_contains($filter, 'conditions IN') && str_contains($filter, 'rarity IN') ? 0 : 24));

    $component = Livewire::test(ResultGrid::class)
        ->call('toggle', 'condition', 'nm')
        ->call('toggle', 'rarity', 'sar');

    $component
        ->assertSee(__('storefront.results.empty.title'))
        ->assertSee(__('storefront.results.empty.suggestion', ['n' => 24, 'filter' => 'SAR']))
        ->assertSee(__('storefront.results.empty.remove', ['name' => __('storefront.results.group.rarity')]))
        ->assertSeeHtml("wire:click=\"clearGroup('rarity')\"")
        ->call('clearGroup', 'rarity')
        ->assertSet('rarity', [])
        ->assertSet('condition', ['nm']);
});

test('the suggestion skips a filter whose removal still leaves nothing', function () {
    resultShop();
    // Only the condition is the culprit, though the rarity was switched on last.
    useResultEngine(new FakeResultEngine(total: fn (string $filter): int => str_contains($filter, 'conditions IN') ? 0 : 6));

    Livewire::test(ResultGrid::class)
        ->call('toggle', 'condition', 'nm')
        ->call('toggle', 'rarity', 'sar')
        ->assertSee(__('storefront.results.empty.suggestion', ['n' => 6, 'filter' => 'NM']))
        ->assertSeeHtml("wire:click=\"clearGroup('condition')\"");
});

test('the empty state of a default page offers the text hint and no button', function () {
    resultShop();
    useResultEngine(new FakeResultEngine(total: fn (): int => 0));

    $this->get(route('search', ['q' => 'zzzqqxx']))
        ->assertOk()
        ->assertSee(__('storefront.results.empty.title_query', ['query' => 'zzzqqxx']))
        ->assertSee(__('storefront.results.empty.hint'))
        ->assertDontSee('clearGroup(', false)
        ->assertDontSee('wire:click="clearAll"', false)
        ->assertDontSee(__('storefront.results.include_sold_out'));
});

test('in stock only is a quiet default: no chip and no badge while on, a chip once off', function () {
    resultShop();
    useResultEngine(new FakeResultEngine);

    $quiet = Livewire::test(ResultGrid::class);
    expect($quiet->viewData('chips'))->toBe([])
        ->and($quiet->viewData('panel')['active'])->toBe(0);

    $off = Livewire::withQueryParams(['soldout' => '1', 'rarity' => ['sar']])->test(ResultGrid::class);
    expect(array_column($off->viewData('chips'), 'label'))->toBe(['SAR', __('storefront.results.include_sold_out')])
        ->and($off->viewData('panel')['active'])->toBe(2);

    $off->call('toggleFlag', 'stock')->assertSet('soldout', false)->call('toggle', 'rarity', 'sar');
    expect($off->viewData('chips'))->toBe([]);

    Livewire::withQueryParams(['soldout' => '1'])->test(ResultGrid::class)->call('clearAll')->assertSet('soldout', false);
});

test('a page opened from a URL suggests the most likely culprit first, in the documented group order', function () {
    resultShop();
    // The two filters together match nothing; either one alone matches printings.
    useResultEngine(new FakeResultEngine(total: fn (string $filter): int => str_contains($filter, 'conditions IN') && str_contains($filter, 'rarity IN') ? 0 : 24));

    Livewire::withQueryParams(['rarity' => ['sar'], 'condition' => ['nm']])->test(ResultGrid::class)
        ->assertSee(__('storefront.results.empty.suggestion', ['n' => 24, 'filter' => 'NM']))
        ->assertSeeHtml("wire:click=\"clearGroup('condition')\"");
});

test('with the engine down a suggestion is counted in the database without asking the engine again', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Roronoa Zoro', qty: 0);
    $engine = useBrokenEngine();

    Livewire::test(ResultGrid::class)->assertSee(__('storefront.results.empty.suggestion', ['n' => 1, 'filter' => __('storefront.results.toggle.in_stock')]));

    expect($engine->attempts)->toBe(1);
});

test('the page counter and the touched list cannot be written from the browser', function () {
    resultShop();
    useResultEngine(new FakeResultEngine);

    Livewire::test(ResultGrid::class)->set('pages', 9);
})->throws(CannotUpdateLockedPropertyException::class);

test('a listing with no text is in card number order and offers best match only with a text', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Monkey D. Luffy');
    $engine = useResultEngine(new FakeResultEngine);

    $plain = Livewire::test(ResultGrid::class, ['gameSlug' => 'one-piece']);
    expect($engine->requests[0]['orders'])->toBe([['column' => 'number_plain', 'direction' => 'asc']])
        ->and($plain->viewData('sorts'))->not->toContain('relevance');

    $engine->requests = [];
    $text = Livewire::withQueryParams(['q' => 'luffy'])->test(ResultGrid::class);
    expect($engine->requests[0]['orders'])->toBe([])
        ->and($text->viewData('sorts'))->toContain('relevance');

    $engine->requests = [];
    Livewire::withQueryParams(['sort' => 'relevance'])->test(ResultGrid::class);
    expect($engine->requests[0]['orders'])->toBe([['column' => 'number_plain', 'direction' => 'asc']]);

    $engine->requests = [];
    Livewire::withQueryParams(['sort' => 'price_desc'])->test(ResultGrid::class);
    expect($engine->requests[0]['orders'])->toBe([['column' => 'from_satang', 'direction' => 'desc']]);
});

test('when the engine is down the database answers with the limited filters and a notice', function () {
    ['set' => $set] = resultShop();
    $luffy = resultPrinting($set, 'Monkey D. Luffy', priceSatang: 50000);
    resultPrinting($set, 'Roronoa Zoro', qty: 0, priceSatang: 20000);
    resultPrinting($set, 'Nami', printing: ['language' => Language::Ja]);
    useBrokenEngine();

    Livewire::test(ResultGrid::class)
        ->assertSee(__('storefront.results.limited'))
        ->assertSee('Monkey D. Luffy')
        ->assertSee('Nami')
        ->assertDontSee('Roronoa Zoro')
        ->assertDontSee(__('storefront.results.group.rarity'))
        ->assertDontSee(__('storefront.results.group.condition'))
        ->assertDontSee(__('storefront.results.group.price'))
        ->assertSee(__('storefront.results.group.set'))
        ->assertSee(__('storefront.results.group.language'))
        ->set('soldout', true)
        ->assertSee('Roronoa Zoro')
        ->set('language', ['ja'])
        ->assertSee('Nami')
        ->assertDontSee('Monkey D. Luffy')
        ->set('language', [])
        ->set('query', 'luffy')
        ->assertSee('Monkey D. Luffy')
        ->assertDontSee('Nami')
        ->set('query', '')
        ->set('sort', 'price_desc')
        ->assertSeeInOrder(['Monkey D. Luffy', 'Roronoa Zoro']);

    expect($luffy->exists)->toBeTrue();
});

test('the fallback empty state suggests the filters it can honour', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Roronoa Zoro', qty: 0);
    useBrokenEngine();

    Livewire::test(ResultGrid::class)
        ->assertSee(__('storefront.results.empty.suggestion', ['n' => 1, 'filter' => __('storefront.results.toggle.in_stock')]))
        ->assertSeeHtml("wire:click=\"clearGroup('stock')\"")
        ->call('clearGroup', 'stock')
        ->assertSet('soldout', true)
        ->assertSee('Roronoa Zoro');
});

test('the grid lists 24 printings and loads 24 more on request', function () {
    ['set' => $set] = resultShop();
    foreach (range(1, 30) as $number) {
        resultPrinting($set, 'Card '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), printing: ['number' => (string) $number]);
    }
    useResultEngine(new FakeResultEngine);

    $loadMore = __('storefront.results.load_more', ['n' => 24]);

    Livewire::test(ResultGrid::class)
        ->assertSee('Card 24')
        ->assertDontSee('Card 25')
        ->assertSee(__('storefront.results.shown_of', ['shown' => 24, 'total' => 30]))
        ->assertSee($loadMore)
        ->call('loadMore')
        ->assertSee('Card 30')
        ->assertSee(__('storefront.results.shown_of', ['shown' => 30, 'total' => 30]))
        ->assertDontSee($loadMore)
        ->call('toggle', 'rarity', 'sar')
        ->assertSet('pages', 1);
});

test('list mode shows rows instead of tiles', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Monkey D. Luffy');
    useResultEngine(new FakeResultEngine);

    Livewire::test(ResultGrid::class)
        ->assertSeeHtml('data-view="grid"')
        ->set('view', 'list')
        ->assertSeeHtml('data-view="list"')
        ->assertSeeHtml('flex min-h-16 items-center gap-3')
        ->assertSee('Monkey D. Luffy');
});

test('a sold out printing shows what it cost, dimmed', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Roronoa Zoro', qty: 0, priceSatang: 74000);
    useResultEngine(new FakeResultEngine);

    Livewire::withQueryParams(['soldout' => '1'])->test(ResultGrid::class)
        ->assertSee('฿ 740')
        ->assertSee(__('storefront.stock.out_of_stock'))
        ->assertSeeHtml('text-faint');
});

test('the tile grid costs the same queries for 3 tiles as for 24', function () {
    ['set' => $set] = resultShop();
    useResultEngine(new FakeResultEngine);

    $queries = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ResultGrid::class)->assertOk();

        return array_column(DB::getQueryLog(), 'query');
    };

    foreach (range(1, 3) as $number) {
        resultPrinting($set, 'Card '.$number);
    }
    $few = $queries();

    foreach (range(4, 24) as $number) {
        resultPrinting($set, 'Card '.$number);
    }
    $many = $queries();

    $touching = fn (array $queries, string $table): int => count(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select * from "'.$table.'"')));

    expect(count($many))->toBe(count($few))
        ->and($touching($many, 'printings'))->toBe(1)
        ->and($touching($many, 'cards'))->toBe(1)
        ->and($touching($many, 'variants'))->toBe(1)
        ->and(count($many))->toBeLessThanOrEqual(7);
});

test('a card code typed into the search field goes straight to the card, text does not', function () {
    ['set' => $set] = resultShop();
    $printing = resultPrinting($set, 'Monkey D. Luffy', printing: ['number' => '119']);
    useResultEngine(new FakeResultEngine);

    $this->get(route('search', ['q' => 'OP05-119']))
        ->assertRedirect(route('card.show', ['card' => $printing->card, 'p' => $printing->id]));

    $this->get(route('search', ['q' => 'luffy']))->assertOk();
    $this->get(route('search', ['q' => 'OP05']))->assertOk();
});

test('the header search field starts with the text that was searched on the results page', function () {
    resultShop();
    useResultEngine(new FakeResultEngine);

    $this->get(route('search', ['q' => 'Luffy']))->assertSee('&quot;query&quot;:&quot;Luffy&quot;', false);
    $this->get(route('home', ['q' => 'Luffy']))->assertDontSee('&quot;query&quot;:&quot;Luffy&quot;', false);
});

test('the search field enters on a card code with the card as its target', function () {
    ['set' => $set] = resultShop();
    $printing = resultPrinting($set, 'Monkey D. Luffy', printing: ['number' => '119']);
    useResultEngine(new FakeResultEngine);

    Livewire::test(SearchBox::class)
        ->set('query', 'OP05-119')
        ->assertSeeHtml('data-enter="'.route('card.show', ['card' => $printing->card, 'p' => $printing->id]).'"')
        ->set('query', 'luffy')
        ->assertDontSeeHtml('data-enter=')
        ->assertSeeHtml('data-search-url="'.route('search').'"');
});

test('cancelling the sheet puts the filters back to the state it opened with', function () {
    resultShop();
    useResultEngine(new FakeResultEngine);

    Livewire::withQueryParams(['rarity' => ['sar']])->test(ResultGrid::class)
        ->call('toggle', 'condition', 'nm')
        ->set('min', 100)
        ->call('restore', ['rarity' => ['sar'], 'soldout' => false])
        ->assertSet('rarity', ['sar'])
        ->assertSet('condition', [])
        ->assertSet('min', null);
});

test('the filter panel shows the live count on the sheet button', function () {
    resultShop();
    useResultEngine(new FakeResultEngine(total: fn (): int => 124));

    Livewire::test(FilterPanel::class, ['panel' => ['sections' => [], 'price' => null, 'flags' => ['stock' => true], 'count' => 1240, 'active' => 0, 'limited' => false, 'keys' => ResultGrid::FILTER_KEYS]])
        ->assertSee(__('storefront.results.apply', ['n' => '1,240']));
});

test('Meilisearch counts every facet group against the other filters and sorts by price', function () {
    try {
        $reachable = Http::timeout(1)->get('http://127.0.0.1:7700/health')->successful();
    } catch (Throwable) {
        $reachable = false;
    }

    if (! $reachable) {
        $this->markTestSkipped('Meilisearch is not running on 127.0.0.1:7700.');
    }

    // The catalogue is built before the driver is switched, so no observer writes to an index of its own.
    ['game' => $game, 'set' => $set] = resultShop();
    $red = fn (string $name): Card => Card::factory()->for($game)->create(['name_en' => $name, 'card_type' => 'character', 'traits' => ['color' => ['Red']]]);

    $dear = Printing::factory()->for($set, 'set')->for($red('Pricey Sar'))->create(['rarity_code' => 'sar', 'number' => '001']);
    Variant::factory()->for($dear)->create(['qty' => 2, 'price_satang' => 90000]);
    $gone = Printing::factory()->for($set, 'set')->for($red('Sold Out Sar'))->create(['rarity_code' => 'sar', 'number' => '002']);
    Variant::factory()->for($gone)->create(['qty' => 0, 'price_satang' => 10000]);
    $cheap = Printing::factory()->for($set, 'set')->for($red('Cheap Rare'))->create(['rarity_code' => 'rr', 'number' => '003']);
    Variant::factory()->for($cheap)->create(['qty' => 5, 'price_satang' => 3000, 'condition' => CardCondition::Lp]);
    $mid = Printing::factory()->for($set, 'set')->for($red('Mid Rare'))->create(['rarity_code' => 'rr', 'number' => '004']);
    Variant::factory()->for($mid)->create(['qty' => 5, 'price_satang' => 30000]);
    $lord = Printing::factory()->for($set, 'set')->for($red('Dragon Lord'))->create(['rarity_code' => 'c', 'number' => '005']);
    Variant::factory()->for($lord)->create(['qty' => 5, 'price_satang' => 60000]);
    $typo = Printing::factory()->for($set, 'set')->for($red('Dragoon Pup'))->create(['rarity_code' => 'c', 'number' => '006']);
    Variant::factory()->for($typo)->create(['qty' => 5, 'price_satang' => 1000]);
    $sea = Printing::factory()->for($set, 'set')->for($red('Dragon of the Red Sea'))->create(['rarity_code' => 'c', 'number' => '007']);
    Variant::factory()->for($sea)->create(['qty' => 5, 'price_satang' => 20000]);

    config()->set('scout.driver', 'meilisearch');
    config()->set('scout.prefix', 'test_'.bin2hex(random_bytes(3)).'_');
    config()->set('scout.queue', false);
    config()->set('scout.after_commit', false);

    $client = app(MeilisearchClient::class);
    $index = (new Printing)->searchableAs();

    try {
        $client->waitForTasks([$client->createIndex($index, ['primaryKey' => 'id'])['taskUid']]);
        $client->waitForTasks([$client->index($index)->updateSettings(config('scout.meilisearch.index-settings')[Printing::class])['taskUid']]);

        $documents = Printing::query()->with(['card', 'set.game', 'variants'])->get()->map->toSearchableArray()->values()->all();
        $client->waitForTasks([$client->index($index)->addDocuments($documents)['taskUid']]);

        $results = app(CatalogueResults::class);

        // Filtered to SAR in stock: the rarity group still counts RR, because a group ignores its own filter.
        $page = $results->search(new ResultFilters(rarities: ['sar']), 'relevance', 24, ['rarity', 'condition', 'type', 'color']);

        expect($page->limited)->toBeFalse()
            ->and($page->total)->toBe(1)
            ->and($page->printings->pluck('id')->all())->toBe([$dear->id])
            ->and($page->facets['rarity'])->toBe(['c' => 3, 'rr' => 2, 'sar' => 1])
            ->and($page->facets['condition'])->toBe(['nm' => 1])
            ->and($page->facets['color'])->toBe(['Red' => 1])
            ->and($page->facets['type'])->toBe(['character' => 1]);

        // The engine sorts by the in-stock price, so a sold out printing comes last either way.
        $everything = new ResultFilters(inStockOnly: false);
        $ids = fn (string $sort): array => $results->search($everything, $sort, 24)->printings->pluck('id')->all();

        expect($ids('price_asc'))->toBe([$typo->id, $cheap->id, $sea->id, $mid->id, $lord->id, $dear->id, $gone->id])
            ->and($ids('price_desc'))->toBe([$dear->id, $lord->id, $mid->id, $sea->id, $cheap->id, $typo->id, $gone->id])
            ->and($ids('number'))->toBe([$dear->id, $gone->id, $cheap->id, $mid->id, $lord->id, $typo->id, $sea->id])
            // With a text, the price sort still decides the order, not the typo or word match behind it.
            ->and($results->search(new ResultFilters(text: 'dragon'), 'price_asc', 24)->printings->pluck('id')->all())->toBe([$typo->id, $sea->id, $lord->id])
            ->and($results->search(new ResultFilters(text: 'dragon'), 'price_desc', 24)->printings->pluck('id')->all())->toBe([$lord->id, $sea->id, $typo->id])
            ->and($results->count(new ResultFilters(conditions: ['lp'], minSatang: 2000, maxSatang: 5000)))->toBe(1)
            ->and($results->count(new ResultFilters(conditions: ['lp'], minSatang: 4000)))->toBe(0);
    } finally {
        $client->deleteIndex($index);
    }
})->group('meilisearch');

test('a hit whose card is trashed or whose game is inactive never reaches the grid', function () {
    ['set' => $set] = resultShop();
    resultPrinting($set, 'Visible Luffy');
    $trashed = resultPrinting($set, 'Trashed Zoro');
    $trashed->card->delete();
    $hidden = CardSet::factory()->for(Game::factory()->create(['is_active' => false]))->create();
    resultPrinting($hidden, 'Inactive Game Nami');
    useResultEngine(new FakeResultEngine);

    Livewire::test(ResultGrid::class)
        ->assertSee('Visible Luffy')
        ->assertDontSee('Trashed Zoro')
        ->assertDontSee('Inactive Game Nami');
});

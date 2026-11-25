<?php

use App\Livewire\Storefront\SearchBox;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Printing;
use App\Models\Variant;
use App\Search\AutocompleteResult;
use App\Search\CatalogueSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\NullEngine;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('scout.driver', 'collection');
});

/**
 * Livewire's own assertSeeInOrder reads the JSON response, where Thai is escaped, so check the HTML.
 *
 * @param  list<string>  $needles
 */
function expectInOrder(string $html, array $needles): void
{
    $offset = 0;

    foreach ($needles as $needle) {
        $position = strpos($html, $needle, $offset);

        expect($position)->not->toBeFalse("[{$needle}] not found after offset {$offset}");
        $offset = $position + strlen($needle);
    }
}

/**
 * @return array{set: CardSet, printing: Printing, soldOut: Printing}
 */
function storefrontCatalogue(): array
{
    $game = Game::factory()->create(['slug' => 'one-piece', 'is_active' => true]);
    $set = CardSet::factory()->for($game)->create(['code' => 'OP-05', 'name_en' => 'Awakening of the New Era', 'card_count' => 119, 'sort' => 1]);

    $hero = Card::factory()->for($game)->create(['name_en' => 'OP05 Hero', 'name_th' => 'ฮีโร่']);
    $villain = Card::factory()->for($game)->create(['name_en' => 'OP05 Villain']);

    $printing = Printing::factory()->for($hero)->for($set, 'set')->create(['number' => '119', 'rarity_code' => 'sar']);
    $soldOut = Printing::factory()->for($villain)->for($set, 'set')->create(['number' => '023', 'rarity_code' => 'u']);

    Variant::factory()->for($printing)->create(['qty' => 1, 'price_satang' => 340000]);
    Variant::factory()->for($soldOut)->create(['qty' => 0, 'price_satang' => 6000]);

    return compact('set', 'printing', 'soldOut');
}

test('a set code shows the jump row first, then the printings under the cards caption', function () {
    $catalogue = storefrontCatalogue();

    $component = Livewire::test(SearchBox::class)->set('query', 'op05');

    expectInOrder($component->html(), [
        __('storefront.search.jump_set', ['code' => 'OP-05']),
        __('storefront.search.jump_set_hint'),
        __('storefront.search.group_cards', ['n' => 2]),
        'OP05-',
    ]);

    $component
        ->assertSee('OP05 Hero')
        ->assertSee('OP05 Villain')
        ->assertSee('href="'.route('set.show', $catalogue['set']).'"', false)
        ->assertSee('href="'.route('card.show', $catalogue['printing']->card).'"', false);
});

test('a result row shows its code, rarity, price and stock', function () {
    storefrontCatalogue();

    Livewire::test(SearchBox::class)
        ->set('query', 'OP05 Hero')
        ->assertSee('OP05-119 · ', false)
        ->assertSee('SAR')
        ->assertSee('฿ 3,400')
        ->assertSee(__('storefront.search.low_stock', ['n' => 1]))
        ->set('query', 'OP05 Villain')
        ->assertSee(__('storefront.search.out_of_stock'))
        ->assertSee('opacity-55', false)
        ->assertSee('OP05-023 · ', false);
});

test('a result row prints its set code upper-case without the dash', function () {
    $game = Game::factory()->create(['is_active' => true]);
    $set = CardSet::factory()->for($game)->create(['code' => 'sv3pt5']);
    $printing = Printing::factory()->for(Card::factory()->for($game)->create(['name_en' => 'Charizard']))->for($set, 'set')->create(['number' => '25']);

    expect($printing->display_code)->toBe('SV3PT5-25');

    Livewire::test(SearchBox::class)->set('query', 'Charizard')->assertSee('SV3PT5-25 · ', false);
});

test('the price is accent for SAR and SEC and plain otherwise', function () {
    $catalogue = storefrontCatalogue();
    $common = Printing::factory()->for(Card::factory()->for($catalogue['set']->game)->create(['name_en' => 'OP05 Common']))->for($catalogue['set'], 'set')->create(['rarity_code' => 'c']);
    Variant::factory()->for($common)->create(['qty' => 5, 'price_satang' => 5000]);

    $priceClass = fn (string $html, string $price): string => preg_match('/<span class="([^"]*)">\s*'.preg_quote($price, '/').'/u', $html, $match) ? $match[1] : 'not found';

    $html = Livewire::test(SearchBox::class)->set('query', 'OP05')->html();

    expect($priceClass($html, '฿ 3,400'))->toContain('text-accent')
        ->and($priceClass($html, '฿ 50'))->not->toContain('text-accent')->not->toContain('text-faint');
});

test('the cards caption counts every hit, not only the rows listed', function () {
    $game = Game::factory()->create(['is_active' => true]);
    $set = CardSet::factory()->for($game)->create(['code' => 'ZZ-01']);

    foreach (range(1, 9) as $number) {
        Printing::factory()->for(Card::factory()->for($game)->create(['name_en' => 'Zephyr '.$number]))->for($set, 'set')->create(['number' => (string) $number]);
    }

    $component = Livewire::test(SearchBox::class)->set('query', 'Zephyr')
        ->assertSee(__('storefront.search.group_cards', ['n' => 9]))
        ->assertSee(__('storefront.search.announce_results', ['n' => 9]));

    expect(substr_count($component->html(), 'data-option'))->toBe(6);
});

test('the listbox carries the query it was rendered for and the input points at it only then', function () {
    storefrontCatalogue();

    $component = Livewire::test(SearchBox::class);

    $component->assertDontSee('aria-controls', false);

    $component->set('query', 'OP05 Hero')
        ->assertSee('id="search-listbox"', false)
        ->assertSee('data-query="OP05 Hero"', false)
        ->assertSee('aria-controls="search-listbox"', false);

    $component->set('query', 'zzzz')->assertDontSee('aria-controls', false)->assertDontSee('data-query', false);
});

test('remembering a search does not re-render the box', function () {
    $this->mock(CatalogueSearch::class)
        ->shouldReceive('autocomplete')
        ->once()
        ->andReturn(new AutocompleteResult(null, collect(), collect()));

    Livewire::test(SearchBox::class)->set('query', 'OP05')->call('remember', 'OP05');
});

test('a stale index never puts a trashed card, an inactive game or its sets on screen', function () {
    $catalogue = storefrontCatalogue();
    $catalogue['printing']->card->delete();

    $hidden = Game::factory()->create(['is_active' => false]);
    CardSet::factory()->for($hidden)->create(['code' => 'ZZ-09', 'name_en' => 'Hidden Set']);

    app(EngineManager::class)->extend('stale', fn () => new class extends CollectionEngine
    {
        public function paginate(Builder $builder, $perPage, $page)
        {
            return ['results' => Printing::query()->get()->all(), 'total' => Printing::query()->count()];
        }

        public function search(Builder $builder)
        {
            return ['results' => CardSet::query()->get()->all(), 'total' => CardSet::query()->count()];
        }
    });
    config()->set('scout.driver', 'stale');

    Livewire::test(SearchBox::class)
        ->set('query', 'anything')
        ->assertOk()
        ->assertSee('OP05 Villain')
        ->assertDontSee('OP05 Hero')
        ->assertSee('OP-05 — Awakening of the New Era')
        ->assertDontSee('Hidden Set');
});

test('a matching set is listed under the sets caption', function () {
    storefrontCatalogue();

    Livewire::test(SearchBox::class)
        ->set('query', 'Awakening')
        ->assertSee(__('storefront.search.group_sets', ['n' => 1]))
        ->assertSee('OP-05 — Awakening of the New Era')
        ->assertSee(__('storefront.search.set_cards', ['n' => 119]));
});

test('a query with no match says so', function () {
    storefrontCatalogue();

    Livewire::test(SearchBox::class)
        ->set('query', 'zzzz')
        ->assertSee(__('storefront.search.no_results', ['query' => 'zzzz']));
});

test('an empty query shows recent searches after one was opened, newest first, five at most', function () {
    storefrontCatalogue();

    $component = Livewire::test(SearchBox::class)->assertDontSee(__('storefront.search.recent'));

    foreach (['one', 'two', 'three', 'four', 'five', 'six', 'three'] as $term) {
        $component->call('remember', $term);
    }

    expect(session('storefront.recent_searches'))->toBe(['three', 'six', 'five', 'four', 'two']);

    $component->set('query', '')->assertSee(__('storefront.search.recent'))->assertDontSee('>one<', false);

    expectInOrder($component->html(), ['>three<', '>six<', '>five<', '>four<', '>two<']);

    $component->call('useRecent', 1)->assertSet('query', 'six');
});

test('the recent searches are hidden while something is typed', function () {
    storefrontCatalogue();

    Livewire::test(SearchBox::class)
        ->call('remember', 'Sheoldred')
        ->set('query', 'op05')
        ->assertDontSee(__('storefront.search.recent'));
});

test('the limited notice shows when the engine is down', function () {
    storefrontCatalogue();

    app(EngineManager::class)->extend('throwing', fn () => new class extends NullEngine
    {
        public function search(Builder $builder): never
        {
            throw new RuntimeException('Meilisearch is down');
        }
    });
    config()->set('scout.driver', 'throwing');

    $component = Livewire::test(SearchBox::class)->set('query', 'OP05 Hero');

    $component->assertSee(__('storefront.search.limited'))->assertSee('OP05 Hero');
});

test('the limited notice is hidden when the engine answers', function () {
    storefrontCatalogue();

    Livewire::test(SearchBox::class)
        ->set('query', 'OP05 Hero')
        ->assertDontSee(__('storefront.search.limited'));
});

test('the query is capped at 100 characters before the catalogue is searched', function () {
    $this->mock(CatalogueSearch::class)
        ->shouldReceive('autocomplete')
        ->with(str_repeat('a', 100))
        ->once()
        ->andReturn(new AutocompleteResult(null, collect(), collect()));

    Livewire::test(SearchBox::class)
        ->set('query', str_repeat('a', 150))
        ->assertSet('query', str_repeat('a', 100));
});

test('the cap counts characters, not bytes, and holds even if the property was not trimmed', function () {
    $this->mock(CatalogueSearch::class)
        ->shouldReceive('autocomplete')
        ->with(str_repeat('ก', 100))
        ->once()
        ->andReturn(new AutocompleteResult(null, collect(), collect()));

    $component = new SearchBox;
    $component->query = str_repeat('ก', 150);
    $component->render(app(CatalogueSearch::class));
});

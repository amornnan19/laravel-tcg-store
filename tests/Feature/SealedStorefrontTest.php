<?php

require_once __DIR__.'/../Support/sealed.php';

use App\Actions\AddToCart;
use App\Enums\SealedKind;
use App\Livewire\Storefront\SealedBuyBox;
use App\Livewire\Storefront\SearchBox;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Game;
use App\Models\Printing;
use App\Models\SealedProduct;
use App\Models\StockReservation;
use App\Search\CatalogueSearch;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\NullEngine;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

// The collection engine answers from the database, so the search tests run the engine path as well as the fallback.
beforeEach(fn () => config()->set('scout.driver', 'collection'));

function sealedThrowingEngine(): void
{
    app(EngineManager::class)->extend('throwing', fn () => new class extends NullEngine
    {
        public function search(Builder $builder): never
        {
            throw new RuntimeException('Meilisearch is down');
        }
    });
    config()->set('scout.driver', 'throwing');
}

test('the page shows the name, kind, game, set, price, stock, description and the add button', function () {
    $product = sealedProductInSet(['name_en' => 'OP-05 Booster Box', 'price_satang' => 280000, 'qty' => 12, 'description_th' => 'กล่องละ 24 ซอง']);

    $this->get(route('sealed.show', $product))
        ->assertOk()
        ->assertSee('OP-05 Booster Box')
        ->assertSee('บูสเตอร์บ็อกซ์')
        ->assertSee($product->game->name_en)
        ->assertSee('OP-05 — Awakening of the New Era')
        ->assertSee('฿ 2,800')
        ->assertSee(__('storefront.stock.in_stock', ['n' => 12]))
        ->assertSee('กล่องละ 24 ซอง')
        ->assertSee('wire:click="add"', false);
});

test('a product with a name in Thai shows it, and the slug comes from the English name', function () {
    $product = sealedProduct(['name_en' => 'Starter Deck Red', 'name_th' => 'สตาร์ทเตอร์เด็คสีแดง']);

    expect($product->slug)->toBe('starter-deck-red');

    $this->get(route('sealed.show', $product))->assertSee('สตาร์ทเตอร์เด็คสีแดง');
});

test('a product with no photo draws the neutral block, never card art, and one with a photo shows it', function () {
    Storage::fake('public');
    $plain = sealedProduct(['kind' => SealedKind::Accessory]);
    $photo = sealedProduct(['image_path' => 'sealed-photos/box.jpg']);

    $this->get(route('sealed.show', $plain))->assertOk()->assertSee('อุปกรณ์')->assertDontSee('<img', false)->assertDontSee('aspect-[63/88]', false);
    $this->get(route('sealed.show', $photo))->assertOk()->assertSee('sealed-photos/box.jpg', false);
});

test('an inactive product, a trashed one and one of a game the storefront hides are a 404', function () {
    $inactive = sealedProduct(['is_active' => false]);
    $trashed = sealedProduct();
    $trashed->delete();
    $hidden = sealedProductInSet();
    Game::query()->update(['is_active' => false]);

    $this->get(route('sealed.show', $inactive))->assertNotFound();
    $this->get('/p/'.$trashed->slug)->assertNotFound();
    $this->get(route('sealed.show', $hidden))->assertNotFound();
    $this->get('/p/no-such-product')->assertNotFound();
});

test('a pre-order product says it opens soon and has no button, and the box refuses an add', function () {
    $product = sealedProduct(['is_preorder' => true]);

    $this->get(route('sealed.show', $product))
        ->assertOk()
        ->assertSee('เปิดสั่งจองเร็ว ๆ นี้')
        ->assertDontSee('wire:click="add"', false)
        ->assertDontSee(__('storefront.sealed.add'));

    Livewire::test(SealedBuyBox::class, ['sealedProductId' => $product->id])->call('add')->assertSee(__('storefront.sealed.add_failed'));

    expect(CartItem::query()->count())->toBe(0)->and(StockReservation::query()->count())->toBe(0);
});

test('a sold-out product shows no button and says so', function () {
    $product = sealedProduct(['qty' => 0]);

    $this->get(route('sealed.show', $product))
        ->assertOk()
        ->assertSee(__('storefront.sealed.sold_out'))
        ->assertDontSee('wire:click="add"', false);
});

test('the stepper stops at the stock and a tampered quantity is clamped', function () {
    $product = sealedProduct(['qty' => 3]);

    Livewire::test(SealedBuyBox::class, ['sealedProductId' => $product->id])
        ->assertSet('qty', 1)
        ->call('increment')->call('increment')->call('increment')
        ->assertSet('qty', 3)
        ->assertSee(__('storefront.sealed.at_max', ['n' => 3]))
        ->call('decrement')->call('decrement')->call('decrement')
        ->assertSet('qty', 1)
        ->set('qty', 99)
        ->call('add');

    expect(StockReservation::query()->firstOrFail()->qty)->toBe(3);
});

test('adding holds the quantity for the visitor and the box then says it is in the cart', function () {
    $product = sealedProduct(['qty' => 5]);

    Livewire::test(SealedBuyBox::class, ['sealedProductId' => $product->id])
        ->set('qty', 2)
        ->call('add')
        ->assertDispatched('cart-updated')
        ->assertSet('qty', 1)
        ->assertSee(__('storefront.sealed.in_cart', ['n' => 2, 'minutes' => 15]))
        ->assertSee(route('cart'));

    expect(CartItem::query()->where('purchasable_type', 'sealed')->where('purchasable_id', $product->id)->firstOrFail()->qty)->toBe(2)
        ->and($product->fresh()->availableQty())->toBe(3);
});

test('adding stock another cart took between the render and the click changes nothing and says why', function () {
    $product = sealedProduct(['qty' => 2]);
    $box = Livewire::test(SealedBuyBox::class, ['sealedProductId' => $product->id])->set('qty', 2);

    app(AddToCart::class)->handle(Cart::factory()->create(), $product, 2);

    $box->call('add')->assertSee(__('storefront.sealed.sold_out'));

    expect(CartItem::query()->count())->toBe(1);
});

test('the autocomplete finds sealed products by name, Thai name and SKU, at most two, whichever way the search runs', function (bool $engineDown) {
    $engineDown && sealedThrowingEngine();
    sealedProduct(['name_en' => 'Zephyr Box One', 'name_th' => 'เซเฟอร์ บอกซ์', 'sku' => 'ZB-001']);
    sealedProduct(['name_en' => 'Zephyr Box Two', 'sku' => 'ZB-002']);
    sealedProduct(['name_en' => 'Zephyr Box Three', 'sku' => 'ZB-003']);
    sealedProduct(['name_en' => 'Unrelated Pack']);

    $search = fn (string $query) => app(CatalogueSearch::class)->autocomplete($query)->sealed;

    expect($search('Zephyr'))->toHaveCount(2)
        ->and($search('เซเฟอร์')->pluck('sku')->all())->toBe(['ZB-001'])
        ->and($search('ZB-002')->pluck('sku')->all())->toBe(['ZB-002'])
        ->and($search('Unrelated')->pluck('name_en')->all())->toBe(['Unrelated Pack'])
        ->and($search('nothing here'))->toBeEmpty();
})->with(['collection engine' => false, 'fallback' => true]);

test('the autocomplete never offers an inactive product, a trashed one or one of an inactive game', function (bool $engineDown) {
    $engineDown && sealedThrowingEngine();
    $inactive = sealedProduct(['name_en' => 'Quasar Inactive', 'is_active' => false]);
    $trashed = sealedProduct(['name_en' => 'Quasar Trashed']);
    $trashed->delete();
    $hidden = sealedProductInSet(['name_en' => 'Quasar Hidden']);
    $visible = sealedProduct(['name_en' => 'Quasar Visible']);
    Game::query()->whereKey($hidden->game_id)->update(['is_active' => false]);

    expect(app(CatalogueSearch::class)->autocomplete('Quasar')->sealed->pluck('name_en')->all())->toBe(['Quasar Visible']);
})->with(['collection engine' => false, 'fallback' => true]);

test('the autocomplete box lists the sealed group after the sets, each row a keyboard option linking to the page', function () {
    $game = Game::factory()->create();
    CardSet::factory()->for($game)->create(['code' => 'ZP-01', 'name_en' => 'Zephyr Set']);
    $product = sealedProduct(['name_en' => 'Zephyr Box', 'price_satang' => 150000, 'qty' => 4]);

    $html = Livewire::test(SearchBox::class)->set('query', 'Zephyr')
        ->assertSee(__('storefront.search.group_sealed', ['n' => 1]))
        ->assertSee(__('storefront.stock.in_stock', ['n' => 4]))
        ->assertSee('Zephyr Box')
        ->assertSee('บูสเตอร์บ็อกซ์')
        ->assertSee('฿ 1,500')
        ->html();

    expect(substr_count($html, 'data-option'))->toBe(2)
        ->and($html)->toContain('id="search-option-0"')
        ->and($html)->toContain('id="search-option-1"')
        ->and(strpos($html, route('set.show', CardSet::query()->firstOrFail())))->toBeLessThan(strpos($html, route('sealed.show', $product)))
        ->and($html)->toMatch('/<a\s[^>]*role="option"[^>]*data-option[^>]*href="'.preg_quote(route('sealed.show', $product), '/').'"/s');
});

test('a query that only sealed products match still shows a panel, not the empty message', function () {
    sealedProduct(['name_en' => 'Zephyr Box']);

    Livewire::test(SearchBox::class)->set('query', 'Zephyr')
        ->assertSee('Zephyr Box')
        ->assertDontSee(__('storefront.search.no_results', ['query' => 'Zephyr']));
});

test('a product is indexed only while the storefront shows it', function () {
    $game = Game::factory()->create();
    $product = sealedProduct(['game_id' => $game->id, 'sku' => 'IDX-1']);

    expect($product->shouldBeSearchable())->toBeTrue()
        ->and($product->searchableAs())->toBe('sealed_products')
        ->and($product->toSearchableArray())->toMatchArray(['id' => $product->id, 'kind' => 'booster_box', 'sku' => 'IDX-1', 'game' => $game->slug]);

    $product->update(['is_active' => false]);
    expect($product->fresh()->shouldBeSearchable())->toBeFalse();

    $product->update(['is_active' => true]);
    $game->update(['is_active' => false]);
    expect($product->fresh()->shouldBeSearchable())->toBeFalse();

    $game->update(['is_active' => true]);
    $product->delete();
    expect(SealedProduct::withTrashed()->find($product->id)->shouldBeSearchable())->toBeFalse();
});

test('the home page shows up to three purchasable in-stock products, one full row on desktop, and none that cannot be bought', function () {
    foreach (range(1, 5) as $number) {
        sealedProduct(['name_en' => "Shown Box {$number}"]);
    }
    sealedProduct(['name_en' => 'Hidden Sold Out', 'qty' => 0]);
    sealedProduct(['name_en' => 'Hidden Inactive', 'is_active' => false]);
    sealedProduct(['name_en' => 'Hidden Preorder', 'is_preorder' => true]);
    sealedProduct(['name_en' => 'Hidden Unpriced', 'price_satang' => null]);

    $html = $this->get(route('home'))->assertOk()->assertSee(__('storefront.sealed.section'))->getContent();

    expect(substr_count($html, 'Shown Box'))->toBe(3)
        ->and($html)->not->toContain('Hidden');
});

test('the home page has no sealed section when nothing can be bought', function () {
    sealedProduct(['qty' => 0]);

    $this->get(route('home'))->assertOk()->assertDontSee(__('storefront.sealed.section'));
});

test('the home page leaves a product whose stock another cart holds out', function () {
    $product = sealedProduct(['name_en' => 'Held Box', 'qty' => 2]);
    app(AddToCart::class)->handle(Cart::factory()->create(), $product, 2);

    $this->get(route('home'))->assertDontSee('Held Box');
});

test('the sealed index has its own settings keyed by the model class', function () {
    $settings = config('scout.meilisearch.index-settings');

    expect($settings)->toHaveKey(SealedProduct::class)
        ->and($settings[SealedProduct::class]['searchableAttributes'])->toContain('name_en', 'name_th', 'sku');
});

test('after the last unit goes into the visitor cart the box shows the cart status only', function () {
    $product = sealedProduct(['qty' => 2, 'price_satang' => 150000]);

    $html = Livewire::test(SealedBuyBox::class, ['sealedProductId' => $product->id])
        ->set('qty', 2)
        ->call('add')
        ->assertSee(__('storefront.sealed.in_cart', ['n' => 2, 'minutes' => 15]))
        ->assertSee(route('cart'))
        ->assertDontSee(__('storefront.sealed.sold_out'))
        ->html();

    expect($html)->toMatch('/<p class="[^"]*text-ink[^"]*">(?:(?!<\/p>).)*฿ 1,500/su')
        ->and($html)->not->toMatch('/<p class="[^"]*text-faint[^"]*">(?:(?!<\/p>).)*฿ 1,500/su')
        ->and($html)->not->toContain('หมด');
});

test('a product that is sold out for everyone says so once', function () {
    $product = sealedProduct(['qty' => 0]);

    $html = Livewire::test(SealedBuyBox::class, ['sealedProductId' => $product->id])->html();

    // "หมด" is inside both the stock indicator and "สินค้าหมดแล้ว", so one occurrence means one message.
    expect(substr_count($html, 'หมด'))->toBe(1);
});

test('the home section lists rows with game and kind under the name and no text inside the art', function () {
    $product = sealedProductInSet(['name_en' => 'Row Box']);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain($product->game->name_en.' · บูสเตอร์บ็อกซ์')
        ->and(substr_count($html, 'บูสเตอร์บ็อกซ์'))->toBe(1)
        ->and($html)->toContain('w-16')
        ->and($html)->toContain('grid grid-cols-1 gap-3 lg:grid-cols-3');
});

test('the product page art stays square and has no height cap', function () {
    $html = $this->get(route('sealed.show', sealedProduct()))->assertOk()->getContent();

    expect($html)->toContain('max-w-md lg:mx-0 lg:max-w-lg')->not->toContain('max-h-[55vh]');
});

test('hydrating a Scout hit never returns a trashed product or printing', function () {
    $live = sealedProduct(['name_en' => 'Hydrate Live']);
    $trashed = sealedProduct(['name_en' => 'Hydrate Trashed']);
    $trashed->delete();
    $printing = Printing::factory()->for(Card::factory()->for(Game::factory()->create())->create(), 'card')->for(CardSet::factory()->create(), 'set')->create();
    $trashedPrinting = Printing::factory()->for($printing->card, 'card')->for($printing->set, 'set')->create();
    $trashedPrinting->delete();

    $sealed = SealedProduct::search('x')->query(fn ($builder) => $builder->searchVisible());
    $printings = Printing::search('x')->query(fn ($builder) => $builder->searchVisible());

    expect((new SealedProduct)->getScoutModelsByIds($sealed, [$live->id, $trashed->id])->pluck('id')->all())->toBe([$live->id])
        ->and((new Printing)->getScoutModelsByIds($printings, [$printing->id, $trashedPrinting->id])->pluck('id')->all())->toBe([$printing->id]);
});

test('a failing sealed index falls back for sealed products only, and printings keep the engine', function () {
    app(EngineManager::class)->extend('sealed-down', fn () => new class extends CollectionEngine
    {
        public function search(Builder $builder)
        {
            return $builder->model instanceof SealedProduct ? throw new RuntimeException('no sealed index') : parent::search($builder);
        }

        public function get(Builder $builder)
        {
            return $builder->model instanceof SealedProduct ? throw new RuntimeException('no sealed index') : parent::get($builder);
        }

        public function paginate(Builder $builder, $perPage, $page)
        {
            return $builder->model instanceof SealedProduct ? throw new RuntimeException('no sealed index') : parent::paginate($builder, $perPage, $page);
        }
    });
    config()->set('scout.driver', 'sealed-down');

    $game = Game::factory()->create();
    $printing = Printing::factory()->for(Card::factory()->for($game)->create(['name_en' => 'Zephyr Card']), 'card')->for(CardSet::factory()->for($game)->create(), 'set')->create();
    sealedProduct(['name_en' => 'Zephyr Box', 'sku' => 'ZB-9']);
    sealedProduct(['name_en' => 'Zephyr Hidden', 'is_active' => false]);

    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e->getMessage();
    });

    $result = app(CatalogueSearch::class)->autocomplete('Zephyr');

    expect($result->limited)->toBeFalse()
        ->and($result->printings->pluck('id')->all())->toBe([$printing->id])
        ->and($result->sealed->pluck('sku')->all())->toBe(['ZB-9'])
        ->and($reported)->toBe(['no sealed index']);

    // A missing index is a deploy state, not an outage: the next keystrokes fall back without reporting again.
    app(CatalogueSearch::class)->autocomplete('Zephyr');
    app(CatalogueSearch::class)->autocomplete('Zephyr B');

    expect($reported)->toBe(['no sealed index']);
});

test('an autocomplete row never offers stock the shop cannot sell', function () {
    sealedProduct(['name_en' => 'Zephyr Preorder', 'is_preorder' => true, 'qty' => 4]);
    sealedProduct(['name_en' => 'Zephyr Unpriced', 'price_satang' => null, 'qty' => 10]);

    Livewire::test(SearchBox::class)->set('query', 'Zephyr')
        ->assertSee('Zephyr Preorder')
        ->assertSee('Zephyr Unpriced')
        ->assertSee(__('storefront.sealed.preorder_soon'))
        ->assertDontSee(__('storefront.stock.in_stock', ['n' => 4]))
        ->assertDontSee(__('storefront.stock.in_stock', ['n' => 10]));
});

<?php

require_once __DIR__.'/../Support/sealed.php';

use App\Actions\AddToCart;
use App\Actions\CancelOrder;
use App\Actions\ChangeCartQuantity;
use App\Actions\PlaceOrder;
use App\Actions\RefundOrder;
use App\Actions\RemoveCartItem;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutBlocked;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Filament\Resources\Orders\OrderResource;
use App\Jobs\ReleaseExpiredReservations;
use App\Livewire\Storefront\CartPanel;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\Cart;
use App\Models\Game;
use App\Models\GradedItem;
use App\Models\Order;
use App\Models\SealedProduct;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Variant;
use App\Support\PurchasableTypes;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

beforeEach(function () {
    config(['shop.promptpay_id' => '0812345678']);
});

test('a hold takes stock away from other carts like a variant, and a cart can take what is left', function () {
    $product = sealedProduct(['qty' => 5]);
    $first = Cart::factory()->create();
    $second = Cart::factory()->create();

    $line = app(AddToCart::class)->handle($first, $product, 3);

    expect($line->purchasable_type)->toBe('sealed')
        ->and($line->unit_price_satang)->toBe(280000)
        ->and($product->fresh()->availableQty())->toBe(2)
        ->and($product->fresh()->qty)->toBe(5)
        ->and(fn () => app(AddToCart::class)->handle($second, $product, 3))->toThrow(InsufficientStock::class)
        ->and(SealedProduct::query()->available()->whereKey($product->id)->exists())->toBeTrue();

    app(AddToCart::class)->handle($second, $product, 2);

    expect($product->fresh()->availableQty())->toBe(0)
        ->and(SealedProduct::query()->available()->whereKey($product->id)->exists())->toBeFalse()
        ->and(fn () => app(AddToCart::class)->handle(Cart::factory()->create(), $product, 1))->toThrow(InsufficientStock::class);
});

test('raising a line above the stock is refused and a lower stock never goes below zero', function () {
    $product = sealedProduct(['qty' => 3]);
    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $product, 2);

    app(ChangeCartQuantity::class)->handle($line, 3);

    expect(fn () => app(ChangeCartQuantity::class)->handle($line->fresh(), 4))->toThrow(InsufficientStock::class)
        ->and($line->fresh()->qty)->toBe(3);
});

test('an expired hold gives its stock back and the release job removes it without touching the product', function () {
    $product = sealedProduct(['qty' => 2]);
    app(AddToCart::class)->handle(Cart::factory()->create(), $product, 2);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);

    expect($product->fresh()->availableQty())->toBe(2);

    (new ReleaseExpiredReservations)->handle();

    expect(StockReservation::query()->count())->toBe(0)->and($product->fresh()->qty)->toBe(2);
});

test('removing the line frees the stock', function () {
    $product = sealedProduct(['qty' => 2]);
    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $product, 2);

    app(RemoveCartItem::class)->handle($line);

    expect($product->fresh()->availableQty())->toBe(2);
});

test('an inactive, trashed, pre-order, unpriced or hidden-game product is not purchasable and cannot be held', function (string $case) {
    $product = match ($case) {
        'inactive' => sealedProduct(['is_active' => false]),
        'trashed' => tap(sealedProduct(), fn (SealedProduct $product) => $product->delete()),
        'pre-order' => sealedProduct(['is_preorder' => true]),
        'unpriced' => sealedProduct(['price_satang' => null]),
        'game off' => sealedProductInSet(),
    };

    if ($case === 'game off') {
        Game::query()->update(['is_active' => false]);
    }

    expect(SealedProduct::withTrashed()->find($product->id)->isPurchasable())->toBeFalse()
        ->and(fn () => app(AddToCart::class)->handle(Cart::factory()->create(), SealedProduct::withTrashed()->find($product->id), 1))->toThrow(NotPurchasable::class)
        ->and(StockReservation::query()->count())->toBe(0);
})->with(['inactive', 'trashed', 'pre-order', 'unpriced', 'game off']);

test('a product with no game, such as sleeves, is purchasable, and so is one of an active game', function () {
    expect(sealedProduct()->isPurchasable())->toBeTrue()
        ->and(sealedProductInSet()->isPurchasable())->toBeTrue();
});

test('placing an order sells the product, snapshots it as sealed and takes the quantity out of stock', function () {
    Notification::fake();
    $product = sealedProductInSet(['qty' => 5, 'name_en' => 'OP-05 Booster Box', 'sku' => 'BX-OP05']);
    $cart = Cart::factory()->create();
    app(AddToCart::class)->handle($cart, $product, 2);

    $order = app(PlaceOrder::class)->handle($cart, gradedCheckoutData());
    $line = $order->items()->firstOrFail();

    expect($product->fresh()->qty)->toBe(3)
        ->and($line->kind)->toBe('sealed')
        ->and($line->purchasable_type)->toBe('sealed')
        ->and($line->purchasable_id)->toBe($product->id)
        ->and($line->card_name)->toBe('OP-05 Booster Box')
        ->and($line->set_code)->toBe('OP-05')
        ->and($line->detail)->toBe('บูสเตอร์บ็อกซ์ · OP-05')
        ->and($line->detail)->not->toContain('BX-OP05')
        ->and([$line->number, $line->language, $line->condition, $line->finish])->toBe([null, null, null, null])
        ->and($line->qty)->toBe(2)
        ->and($line->line_total_satang)->toBe(560000)
        ->and($order->insurance_satang)->toBe(0)
        ->and(StockReservation::query()->count())->toBe(0);
});

test('a product with no set snapshots an empty set code, and the snapshot survives a rename', function () {
    Notification::fake();
    $product = sealedProduct(['name_en' => 'Sleeves 60', 'sku' => 'SL-60']);
    $order = app(PlaceOrder::class)->handle(gradedCart([$product]), gradedCheckoutData());

    $product->update(['name_en' => 'Renamed']);

    expect($order->items()->firstOrFail()->card_name)->toBe('Sleeves 60')
        ->and($order->items()->firstOrFail()->set_code)->toBe('');
});

test('a sealed id equal to a variant id sells the sealed product and leaves the variant alone', function () {
    Notification::fake();
    $variant = gradedVariant(qty: 5);
    $product = sealedProduct(['qty' => 4]);
    expect($product->id)->toBe($variant->id);

    $order = app(PlaceOrder::class)->handle(gradedCart([$variant, $product]), gradedCheckoutData());

    expect($variant->fresh()->qty)->toBe(4)
        ->and($product->fresh()->qty)->toBe(3)
        ->and($order->items()->orderBy('id')->pluck('kind')->all())->toBe(['single', 'sealed']);
});

test('a mixed cart locks the variants, then the graded items, then the sealed products before any other read', function () {
    Notification::fake();
    $cart = gradedCart([gradedVariant(), gradedItem(), sealedProduct()]);

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(PlaceOrder::class)->handle($cart, gradedCheckoutData());

    $begin = array_search('BEGIN', $log, true);

    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1])->toStartWith('select * from "variants" where "id" in (')
        ->and($log[$begin + 2])->toStartWith('select * from "graded_items" where "id" in (')
        ->and($log[$begin + 3])->toStartWith('select * from "sealed_products" where "id" in (');
});

test('the lock order puts the sealed products last and resolves them by their alias', function () {
    expect(PurchasableTypes::lockOrder())->toBe([
        Variant::class,
        GradedItem::class,
        SealedProduct::class,
    ])->and(PurchasableTypes::classFor('sealed'))->toBe(SealedProduct::class);
});

test('an order refuses a line whose product was deactivated, trashed or run short since the hold', function (string $change) {
    Notification::fake();
    $product = sealedProduct(['qty' => 3]);
    $cart = Cart::factory()->create();
    app(AddToCart::class)->handle($cart, $product, 2);

    match ($change) {
        'deactivated' => $product->update(['is_active' => false]),
        'trashed' => $product->delete(),
        'run short' => $product->update(['qty' => 1]),
        'made a pre-order' => $product->update(['is_preorder' => true]),
    };

    expect(fn () => app(PlaceOrder::class)->handle($cart, gradedCheckoutData()))->toThrow(CheckoutBlocked::class)
        ->and(Order::query()->count())->toBe(0)
        ->and(SealedProduct::withTrashed()->find($product->id)->qty)->toBe($change === 'run short' ? 1 : 3);
})->with(['deactivated', 'trashed', 'run short', 'made a pre-order']);

test('cancelling returns the quantity to the product and the variant', function () {
    Notification::fake();
    $variant = gradedVariant(qty: 5);
    $product = sealedProduct(['qty' => 5]);
    $cart = Cart::factory()->create();
    app(AddToCart::class)->handle($cart, $variant, 1);
    app(AddToCart::class)->handle($cart, $product, 3);
    $order = app(PlaceOrder::class)->handle($cart, gradedCheckoutData());

    expect($product->fresh()->qty)->toBe(2);

    app(CancelOrder::class)->handle($order, 'ลูกค้าขอยกเลิก');

    expect($product->fresh()->qty)->toBe(5)->and($variant->fresh()->qty)->toBe(5);
});

test('refunding returns the quantity to the product', function () {
    Notification::fake();
    $product = sealedProduct(['qty' => 5]);
    $cart = Cart::factory()->create();
    app(AddToCart::class)->handle($cart, $product, 3);
    $order = app(PlaceOrder::class)->handle($cart, gradedCheckoutData());
    $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    $order->payments()->update(['status' => PaymentStatus::Confirmed->value, 'confirmed_at' => now()]);

    app(RefundOrder::class)->handle($order, 'ของไม่ตรง');

    expect($product->fresh()->qty)->toBe(5);
});

test('cancelling an order whose product was trashed since still returns its quantity', function () {
    Notification::fake();
    $product = sealedProduct(['qty' => 5]);
    $cart = Cart::factory()->create();
    app(AddToCart::class)->handle($cart, $product, 3);
    $order = app(PlaceOrder::class)->handle($cart, gradedCheckoutData());
    $product->delete();

    app(CancelOrder::class)->handle($order, 'ยกเลิก');

    expect(SealedProduct::withTrashed()->find($product->id)->qty)->toBe(5);
});

test('the cart shows a sealed line with its kind and a link, and treats a trashed product as gone', function () {
    $product = sealedProduct(['name_en' => 'OP-05 Booster Box', 'price_satang' => 280000]);
    $cart = gradedCart([$product]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CartPanel::class)
        ->assertSee('OP-05 Booster Box')
        ->assertSee('บูสเตอร์บ็อกซ์')
        ->assertSee('฿ 2,800')
        ->assertSee(route('sealed.show', $product))
        ->assertSee(route('checkout'));

    $product->delete();

    Livewire::test(CartPanel::class)
        ->assertSee(__('storefront.cart.gone'))
        ->assertSee('OP-05 Booster Box')
        ->assertDontSee(route('checkout'));
});

test('the stepper on a line whose product was trashed since refreshes the cart instead of failing', function (string $action) {
    $product = sealedProduct(['qty' => 5]);
    $cart = Cart::factory()->create();
    $line = app(AddToCart::class)->handle($cart, $product, 2);
    session(['cart_token' => $cart->token]);
    $product->delete();

    Livewire::test(CartPanel::class)->call($action, $line->id)->assertOk()->assertSee(__('storefront.cart.gone'));

    expect($line->fresh()->qty)->toBe(2);
})->with(['increment', 'decrement']);

test('a sealed-only order charges no protection even when the flag is forced, and a mixed one still does', function () {
    Notification::fake();
    config(['shipping.protection_satang' => 5000]);
    $forced = gradedCheckoutData();
    $forced->protection = true;

    $sealedOnly = app(PlaceOrder::class)->handle(gradedCart([sealedProduct()]), $forced);
    $mixed = app(PlaceOrder::class)->handle(gradedCart([sealedProduct(), gradedVariant()]), $forced);

    expect($sealedOnly->protection_satang)->toBe(0)->and($mixed->protection_satang)->toBe(5000);
});

test('checkout of a sealed-only cart offers no packing and does not tick it for an expensive box', function () {
    $cart = gradedCart([sealedProduct(['price_satang' => 900000])]);
    session(['cart_token' => $cart->token]);

    Livewire::test(CheckoutForm::class)
        ->assertSet('form.protection', false)
        ->assertDontSee('wire:model.live="form.protection"', false);
});

test('a sealed line is drawn square on the cart and the order page, never as card art', function (bool $withPhoto) {
    Notification::fake();
    $product = sealedProduct(['image_path' => $withPhoto ? 'sealed-photos/box.jpg' : null]);
    $cart = gradedCart([$product]);
    session(['cart_token' => $cart->token]);

    $cartHtml = Livewire::test(CartPanel::class)->html();
    $order = app(PlaceOrder::class)->handle($cart, gradedCheckoutData());
    $orderHtml = $this->get($order->signedUrl())->assertOk()->getContent();

    foreach ([$cartHtml, $orderHtml] as $html) {
        expect($html)->toContain('aspect-square')->not->toContain('aspect-[63/88]');
    }
})->with(['with a photo' => true, 'without a photo' => false]);

test('the order page and the admin item list show a placed sealed line', function () {
    Notification::fake();
    $product = sealedProduct(['name_en' => 'OP-05 Booster Box', 'sku' => 'BX-OP05']);
    $order = app(PlaceOrder::class)->handle(gradedCart([$product]), gradedCheckoutData());

    $this->get($order->signedUrl())->assertOk()->assertSee('OP-05 Booster Box')->assertDontSee('BX-OP05')->assertSee('บูสเตอร์บ็อกซ์');

    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create())
        ->get(OrderResource::getUrl('view', ['record' => $order]))
        ->assertOk()
        ->assertSee('OP-05 Booster Box')
        ->assertSee('BX-OP05');
});

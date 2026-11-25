<?php

use App\Actions\AddToCart;
use App\Actions\ChangeCartQuantity;
use App\Actions\RemoveCartItem;
use App\Actions\ReserveStock;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Game;
use App\Models\Printing;
use App\Models\StockReservation;
use App\Models\Variant;
use App\Support\CurrentCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function cartShopVariant(int $qty = 5, ?int $priceSatang = 100000, CardCondition $condition = CardCondition::Nm): Variant
{
    $card = Card::factory()->for(Game::factory())->create();
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create();

    return Variant::factory()->for($printing)->create([
        'condition' => $condition,
        'finish' => Finish::Normal,
        'qty' => $qty,
        'price_satang' => $priceSatang,
    ]);
}

function cartHold(Variant $variant, int $qty, ?Cart $cart = null, bool $expired = false): StockReservation
{
    $factory = $expired ? StockReservation::factory()->expired() : StockReservation::factory();

    return $factory->create([
        'cart_id' => $cart ?? Cart::factory()->create(),
        'purchasable_id' => $variant->id,
        'qty' => $qty,
    ]);
}

test('available quantity drops while a reservation is live and returns when it expires', function () {
    $variant = cartShopVariant(qty: 3);
    cartHold($variant, 2);

    $loaded = fn (): Variant => Variant::query()->withReservedQty()->findOrFail($variant->id);
    $inScope = fn (): bool => Variant::query()->available()->whereKey($variant->id)->exists();

    expect($variant->availableQty())->toBe(1)
        ->and($loaded()->availableQty())->toBe(1)
        ->and($inScope())->toBeTrue();

    cartHold($variant, 1);

    expect($variant->availableQty())->toBe(0)
        ->and($variant->isAvailable())->toBeFalse()
        ->and($loaded()->availableQty())->toBe(0)
        ->and($inScope())->toBeFalse();

    $this->travel(16)->minutes();

    expect($variant->availableQty())->toBe(3)
        ->and($loaded()->availableQty())->toBe(3)
        ->and($inScope())->toBeTrue();
});

test('a variant held by a cart is also out of a whereHas available filter', function () {
    $variant = cartShopVariant(qty: 1);
    cartHold($variant, 1);

    expect(Printing::query()->whereHas('variants', fn ($variants) => $variants->available())->count())->toBe(0);

    $this->travel(16)->minutes();

    expect(Printing::query()->whereHas('variants', fn ($variants) => $variants->available())->count())->toBe(1);
});

test('ReserveStock refuses more than is available and says how many are left', function () {
    $variant = cartShopVariant(qty: 5);
    $cart = Cart::factory()->create();

    try {
        app(ReserveStock::class)->handle($cart, $variant, 6);
        $this->fail('Expected InsufficientStock.');
    } catch (InsufficientStock $exception) {
        expect($exception->available)->toBe(5);
    }

    expect(StockReservation::query()->count())->toBe(0);
});

test('the second cart cannot reserve the last copy the first holds', function () {
    $variant = cartShopVariant(qty: 1);
    $first = Cart::factory()->create();
    $second = Cart::factory()->create();

    app(ReserveStock::class)->handle($first, $variant, 1);

    try {
        app(ReserveStock::class)->handle($second, $variant, 1);
        $this->fail('Expected InsufficientStock.');
    } catch (InsufficientStock $exception) {
        expect($exception->available)->toBe(0);
    }

    expect(StockReservation::query()->where('cart_id', $second->id)->exists())->toBeFalse();
});

test('an expired hold of another cart does not block a reservation', function () {
    $variant = cartShopVariant(qty: 1);
    cartHold($variant, 1, expired: true);
    $cart = Cart::factory()->create();

    $reservation = app(ReserveStock::class)->handle($cart, $variant, 1);

    expect($reservation->qty)->toBe(1)->and($reservation->isLive())->toBeTrue();
});

test('an expired hold of the same cart counts as zero before the new quantity is added', function () {
    $variant = cartShopVariant(qty: 5);
    $cart = Cart::factory()->create();
    cartHold($variant, 3, $cart, expired: true);

    $reservation = app(ReserveStock::class)->handle($cart, $variant, 1);

    expect($reservation->qty)->toBe(1)
        ->and(StockReservation::query()->where('cart_id', $cart->id)->count())->toBe(1);
});

test('adding the same variant twice makes one line and one reservation with the summed quantity', function () {
    $variant = cartShopVariant(qty: 10);
    $cart = Cart::factory()->create();

    app(AddToCart::class)->handle($cart, $variant, 1);
    app(AddToCart::class)->handle($cart, $variant, 2);

    $item = CartItem::query()->sole();
    $reservation = StockReservation::query()->sole();

    expect($item->qty)->toBe(3)
        ->and($item->cart_id)->toBe($cart->id)
        ->and($item->purchasable_type)->toBe('variant')
        ->and($reservation->qty)->toBe(3)
        ->and($variant->availableQty())->toBe(7);
});

test('the line price is the price at add time and a price edit does not change a held line', function () {
    $variant = cartShopVariant(qty: 10, priceSatang: 100000);
    $cart = Cart::factory()->create();

    app(AddToCart::class)->handle($cart, $variant, 1);
    $variant->update(['price_satang' => 250000]);
    app(AddToCart::class)->handle($cart, $variant->fresh(), 1);

    expect(CartItem::query()->sole()->unit_price_satang)->toBe(100000);
});

test('adding to a line whose hold expired starts it over at the current price and the added quantity', function () {
    $variant = cartShopVariant(qty: 10, priceSatang: 100000);
    $cart = Cart::factory()->create();

    app(AddToCart::class)->handle($cart, $variant, 2);
    $variant->update(['price_satang' => 250000]);
    $this->travel(16)->minutes();
    app(AddToCart::class)->handle($cart, $variant->fresh(), 1);

    $item = CartItem::query()->sole();

    expect($item->qty)->toBe(1)
        ->and($item->unit_price_satang)->toBe(250000)
        ->and(StockReservation::query()->sole()->qty)->toBe(1);
});

test('AddToCart refuses a variant with no price', function () {
    $variant = cartShopVariant(priceSatang: null);
    $cart = Cart::factory()->create();

    expect(fn () => app(AddToCart::class)->handle($cart, $variant, 1))->toThrow(NotPurchasable::class)
        ->and(CartItem::query()->count())->toBe(0)
        ->and(StockReservation::query()->count())->toBe(0);
});

test('AddToCart refuses a variant whose card is trashed', function () {
    $variant = cartShopVariant();
    $variant->printing->card->delete();
    $cart = Cart::factory()->create();

    expect(fn () => app(AddToCart::class)->handle($cart, $variant, 1))->toThrow(NotPurchasable::class)
        ->and(CartItem::query()->count())->toBe(0)
        ->and(StockReservation::query()->count())->toBe(0);
});

test('raising a quantity renews the hold and lowering it keeps the time', function () {
    $variant = cartShopVariant(qty: 10);
    $cart = Cart::factory()->create();
    $item = app(AddToCart::class)->handle($cart, $variant, 2);
    $startedAt = StockReservation::query()->sole()->expires_at;

    $this->travel(5)->minutes();
    app(ChangeCartQuantity::class)->handle($item, 1);

    $lowered = StockReservation::query()->sole();
    expect($lowered->qty)->toBe(1)->and($lowered->expires_at->equalTo($startedAt))->toBeTrue()
        ->and($item->fresh()->qty)->toBe(1);

    app(ChangeCartQuantity::class)->handle($item->fresh(), 4);

    $raised = StockReservation::query()->sole();
    expect($raised->qty)->toBe(4)
        ->and($raised->expires_at->greaterThan($startedAt))->toBeTrue()
        ->and($raised->expires_at->toDateTimeString() === now()->addMinutes(config('shop.reservation_minutes'))->toDateTimeString())->toBeTrue()
        ->and($item->fresh()->qty)->toBe(4);
});

test('raising a quantity past what is available is refused and changes nothing', function () {
    $variant = cartShopVariant(qty: 3);
    $item = app(AddToCart::class)->handle(Cart::factory()->create(), $variant, 2);

    expect(fn () => app(ChangeCartQuantity::class)->handle($item, 4))->toThrow(InsufficientStock::class)
        ->and($item->fresh()->qty)->toBe(2)
        ->and(StockReservation::query()->sole()->qty)->toBe(2);
});

test('a line whose hold expired cannot be changed, and a quantity below one is refused', function () {
    $variant = cartShopVariant(qty: 3);
    $item = app(AddToCart::class)->handle(Cart::factory()->create(), $variant, 2);

    expect(fn () => app(ChangeCartQuantity::class)->handle($item, 0))->toThrow(InvalidArgumentException::class);

    $this->travel(16)->minutes();

    expect(fn () => app(ChangeCartQuantity::class)->handle($item, 1))->toThrow(LogicException::class);
});

test('removing a line deletes it and its reservation and gives the stock back', function () {
    $variant = cartShopVariant(qty: 3);
    $cart = Cart::factory()->create();
    $kept = app(AddToCart::class)->handle($cart, cartShopVariant(qty: 3), 1);
    $item = app(AddToCart::class)->handle($cart, $variant, 2);

    app(RemoveCartItem::class)->handle($item);

    expect(CartItem::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(StockReservation::query()->count())->toBe(1)
        ->and($variant->availableQty())->toBe(3);
});

test('the search index counts on-hand stock while the live summary subtracts a reservation', function () {
    $variant = cartShopVariant(qty: 3);
    cartHold($variant, 2);
    $printing = $variant->printing->fresh();

    expect($printing->toSearchableArray())->toMatchArray(['in_stock' => true, 'available_qty' => 3, 'conditions' => ['nm']])
        ->and($printing->stockSummary())->toMatchArray(['in_stock' => true, 'available_qty' => 1])
        ->and($printing->stockSummary(onHand: true))->toMatchArray(['in_stock' => true, 'available_qty' => 3]);

    cartHold($variant, 1);

    $printing = $printing->fresh();

    expect($printing->toSearchableArray())->toMatchArray(['in_stock' => true, 'available_qty' => 3])
        ->and($printing->stockSummary())->toMatchArray(['in_stock' => false, 'available_qty' => 0, 'conditions' => []]);
});

test('a reserving transaction takes the variant lock before any other read', function () {
    $variant = cartShopVariant(qty: 5);
    $cart = Cart::factory()->create();
    $item = app(AddToCart::class)->handle($cart, cartShopVariant(qty: 5), 1);

    $firstQuery = function (Closure $action): string {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $action();

        return $queries[0];
    };

    // SQLite writes no FOR UPDATE, so this proves the order (the variants select first), not the lock itself.
    expect($firstQuery(fn () => app(AddToCart::class)->handle($cart, $variant, 1)))->toStartWith('select * from "variants"')
        ->and($firstQuery(fn () => app(ChangeCartQuantity::class)->handle($item, 2)))->toStartWith('select * from "variants"')
        ->and($firstQuery(fn () => app(ReserveStock::class)->handle($cart, $variant, 1)))->toStartWith('select * from "variants"');
});

test('the current cart is created once per session and its creation lock is released', function () {
    $first = app(CurrentCart::class)->findOrCreate();
    $second = app(CurrentCart::class)->findOrCreate();

    expect($second->is($first))->toBeTrue()
        ->and(Cart::query()->count())->toBe(1)
        ->and(session('cart_token'))->toBe($first->token);

    $lock = Cache::lock('cart-create:'.session()->getId(), 5);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

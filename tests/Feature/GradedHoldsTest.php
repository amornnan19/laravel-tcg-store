<?php

require_once __DIR__.'/../Support/graded.php';

use App\Actions\AddToCart;
use App\Actions\CancelOrder;
use App\Actions\ChangeCartQuantity;
use App\Actions\PlaceOrder;
use App\Actions\RemoveCartItem;
use App\Actions\RenewCartItem;
use App\Actions\SwapCartItem;
use App\Enums\CardCondition;
use App\Enums\GradedStatus;
use App\Enums\RenewOutcome;
use App\Exceptions\GradedNotListable;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Jobs\ReleaseExpiredReservations;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Game;
use App\Models\GradedItem;
use App\Models\StockReservation;
use App\Models\Variant;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

test('the listing rule refuses a listed item without all three photos, from a plain create', function (string $missing) {
    expect(fn () => GradedItem::factory()->for(gradedPrinting())->create([$missing => null]))->toThrow(GradedNotListable::class)
        ->and(GradedItem::query()->count())->toBe(0);
})->with(['front_path', 'back_path', 'label_path']);

test('an item without photos can be kept withdrawn, but cannot be moved to listed until they are filled', function () {
    $item = GradedItem::factory()->for(gradedPrinting())->withdrawn()->withoutPhotos()->create();

    expect(fn () => $item->update(['status' => GradedStatus::Listed]))->toThrow(GradedNotListable::class)
        ->and($item->fresh()->status)->toBe(GradedStatus::Withdrawn);

    $item->update(['status' => GradedStatus::Listed, 'front_path' => 'a.jpg', 'back_path' => 'b.jpg', 'label_path' => 'c.jpg']);

    expect($item->fresh()->status)->toBe(GradedStatus::Listed);
});

test('holding a graded item reserves it, and a second cart is refused', function () {
    $item = gradedItem();
    $first = Cart::factory()->create();
    $second = Cart::factory()->create();

    $line = app(AddToCart::class)->handle($first, $item, 1);

    expect($item->fresh()->status)->toBe(GradedStatus::Reserved)
        ->and($line->purchasable_type)->toBe('graded')
        ->and($line->unit_price_satang)->toBe($item->price_satang)
        ->and(fn () => app(AddToCart::class)->handle($second, $item, 1))->toThrow(InsufficientStock::class)
        ->and(CartItem::query()->count())->toBe(1);
});

test('the same cart cannot take a second copy of a one-of-a-kind item, and a quantity above one is refused', function () {
    $item = gradedItem();
    $cart = Cart::factory()->create();

    expect(fn () => app(AddToCart::class)->handle($cart, $item, 2))->toThrow(InsufficientStock::class)
        ->and($item->fresh()->status)->toBe(GradedStatus::Listed)
        ->and(StockReservation::query()->count())->toBe(0);

    $line = app(AddToCart::class)->handle($cart, $item, 1);

    expect(fn () => app(AddToCart::class)->handle($cart, $item, 1))->toThrow(InsufficientStock::class)
        ->and(fn () => app(ChangeCartQuantity::class)->handle($line, 2))->toThrow(InsufficientStock::class)
        ->and($line->fresh()->qty)->toBe(1);
});

test('the release job sends an expired hold back to listed', function () {
    $item = gradedItem();
    app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);

    (new ReleaseExpiredReservations)->handle();

    expect($item->fresh()->status)->toBe(GradedStatus::Listed)
        ->and(StockReservation::query()->count())->toBe(0);
});

test('the release job leaves an item reserved while another cart holds it live', function () {
    $item = gradedItem(['status' => GradedStatus::Reserved]);
    StockReservation::factory()->forPurchasable($item)->expired()->create();
    StockReservation::factory()->forPurchasable($item)->create();

    (new ReleaseExpiredReservations)->handle();

    expect($item->fresh()->status)->toBe(GradedStatus::Reserved);
});

test('removing the line sends the item back to listed', function () {
    $item = gradedItem();
    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);

    app(RemoveCartItem::class)->handle($line);

    expect($item->fresh()->status)->toBe(GradedStatus::Listed)
        ->and(StockReservation::query()->count())->toBe(0);
});

test('finding an expired line that cannot be bought again on renew sends the item back to listed', function () {
    $item = gradedItem();
    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);
    Game::query()->update(['is_active' => false]);

    $result = app(RenewCartItem::class)->handle($line);

    expect($result->outcome)->toBe(RenewOutcome::Gone)
        ->and($item->fresh()->status)->toBe(GradedStatus::Listed);
});

test('renewing an expired line takes the item again and reserves it', function () {
    $item = gradedItem();
    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);

    $result = app(RenewCartItem::class)->handle($line);

    expect($result->outcome)->toBe(RenewOutcome::Renewed)
        ->and($item->fresh()->status)->toBe(GradedStatus::Reserved)
        ->and(StockReservation::query()->live()->count())->toBe(1);
});

test('a renew finds the item taken by another cart, with no substitute even when a variant of the same id has an in-stock sibling', function () {
    // The variant shares the graded item's id and is sold out, and has a sibling in stock: were the line read as a
    // variant, that sibling would be offered as a substitute.
    $variant = gradedVariant(qty: 0);
    Variant::factory()->for($variant->printing)->create(['condition' => CardCondition::Lp, 'qty' => 3, 'price_satang' => 1000]);
    $item = gradedItem(['id' => $variant->id]);
    expect($item->id)->toBe($variant->id);

    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);
    app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);

    $result = app(RenewCartItem::class)->handle($line);

    expect($result->outcome)->toBe(RenewOutcome::Taken)
        ->and($result->substitute)->toBeNull()
        ->and($item->fresh()->status)->toBe(GradedStatus::Reserved);
});

test('a reserved item whose hold ran out is purchasable and can be held by another cart, healing a missed flip', function () {
    $item = gradedItem();
    app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);

    $stale = $item->fresh();

    expect($stale->status)->toBe(GradedStatus::Reserved)
        ->and($stale->holdableQty())->toBe(1)
        ->and($stale->isPurchasable())->toBeTrue();

    $line = app(AddToCart::class)->handle($other = Cart::factory()->create(), $item, 1);

    expect($line->cart_id)->toBe($other->id)
        ->and(StockReservation::query()->live()->where('cart_id', $other->id)->exists())->toBeTrue();
});

test('a withdrawn or sold item, or one whose game is hidden, cannot be put in a cart', function (callable $make) {
    $item = $make();

    expect($item->isPurchasable())->toBeFalse()
        ->and(fn () => app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1))->toThrow(NotPurchasable::class);
})->with([
    'withdrawn' => [fn () => gradedItem(['status' => GradedStatus::Withdrawn])],
    'sold' => [fn () => gradedItem(['status' => GradedStatus::Sold])],
    'hidden game' => [function () {
        $item = gradedItem();
        Game::query()->update(['is_active' => false]);

        return $item;
    }],
]);

test('a line is resolved by its own type: a graded id equal to a variant id never touches the variant', function () {
    $variant = gradedVariant(qty: 5);
    $item = gradedItem(['id' => $variant->id]);
    expect($item->id)->toBe($variant->id);

    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);

    expect(fn () => app(ChangeCartQuantity::class)->handle($line, 2))->toThrow(InsufficientStock::class)
        ->and(StockReservation::query()->where('purchasable_type', 'variant')->count())->toBe(0)
        ->and($variant->fresh()->qty)->toBe(5);

    app(RemoveCartItem::class)->handle($line);

    expect($variant->fresh()->qty)->toBe(5)
        ->and($item->fresh()->status)->toBe(GradedStatus::Listed);
});

test('a swap refuses a line that is not a variant and changes nothing', function () {
    // A variant that shares the graded item's id, is out of stock and has a cheaper sibling on offer: were the line
    // read as a variant, the sibling would be offered and the swap would replace the graded line.
    $variant = gradedVariant(qty: 0);
    $sibling = Variant::factory()->for($variant->printing)->create(['condition' => CardCondition::Lp, 'qty' => 3, 'price_satang' => 1000]);
    $item = gradedItem(['id' => $variant->id]);
    expect($item->id)->toBe($variant->id);

    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);

    expect(app(SwapCartItem::class)->handle($line, $sibling->id))->toBeNull()
        ->and(CartItem::query()->where('purchasable_type', 'graded')->count())->toBe(1)
        ->and(CartItem::query()->where('purchasable_type', 'variant')->count())->toBe(0);
});

test('the listing rule needs a certificate number too, for listed and for reserved', function (GradedStatus $status, string $missing) {
    expect(fn () => GradedItem::factory()->for(gradedPrinting())->create(['status' => $status, $missing => null]))->toThrow(GradedNotListable::class)
        ->and(GradedItem::query()->count())->toBe(0);
})->with([
    'listed without cert' => [GradedStatus::Listed, 'cert_number'],
    'reserved without cert' => [GradedStatus::Reserved, 'cert_number'],
    'reserved without front photo' => [GradedStatus::Reserved, 'front_path'],
    'reserved without label photo' => [GradedStatus::Reserved, 'label_path'],
]);

test('an item that lost a photo or its certificate by a raw write is not purchasable and holds nothing', function (string $cleared) {
    $item = gradedItem();
    DB::table('graded_items')->where('id', $item->id)->update([$cleared => null]);
    $item = $item->fresh();

    expect($item->isPurchasable())->toBeFalse()
        ->and($item->holdableQty())->toBe(0)
        ->and(fn () => app(AddToCart::class)->handle(Cart::factory()->create(), $item, 1))->toThrow(NotPurchasable::class);
})->with(['cert_number', 'front_path', 'back_path', 'label_path']);

test('the purchasable scope alone refuses a cleared certificate, apart from the in-memory check', function () {
    $item = gradedItem();
    DB::table('graded_items')->where('id', $item->id)->update(['cert_number' => null]);

    expect(GradedItem::query()->purchasable()->whereKey($item->id)->exists())->toBeFalse();
});

test('holdableQty alone refuses a cleared certificate', function () {
    $item = gradedItem();
    DB::table('graded_items')->where('id', $item->id)->update(['cert_number' => null]);

    expect($item->fresh()->holdableQty())->toBe(0);
});

test('removing, renewing and releasing with a photo cleared mid-hold withdraws the item and does not throw', function (string $path) {
    Log::spy();
    $item = gradedItem();
    $cart = Cart::factory()->create();
    $line = app(AddToCart::class)->handle($cart, $item, 1);
    DB::table('graded_items')->where('id', $item->id)->update(['front_path' => null]);

    match ($path) {
        'remove' => app(RemoveCartItem::class)->handle($line),
        'renew' => (function () use ($line) {
            StockReservation::query()->update(['expires_at' => now()->subMinute()]);
            app(RenewCartItem::class)->handle($line);
        })(),
        'job' => (function () {
            StockReservation::query()->update(['expires_at' => now()->subMinute()]);
            (new ReleaseExpiredReservations)->handle();
        })(),
    };

    expect($item->fresh()->status)->toBe(GradedStatus::Withdrawn);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ($context['graded_item_id'] ?? null) === $item->id)->once();
})->with(['remove', 'renew', 'job']);

test('the release job carries on past an item that fails to release, and reports the failure', function () {
    Exceptions::fake();
    $first = gradedItem();
    $second = gradedItem();
    app(AddToCart::class)->handle(Cart::factory()->create(), $first, 1);
    app(AddToCart::class)->handle(Cart::factory()->create(), $second, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);

    // The first row cannot be written back: a trigger raises only for it.
    if (DB::getDriverName() === 'pgsql') {
        DB::unprepared("create function graded_fail() returns trigger language plpgsql as \$\$ begin if new.id = {$first->id} then raise exception 'boom'; end if; return new; end \$\$");
        DB::unprepared('create trigger graded_fail before update on graded_items for each row execute function graded_fail()');
    } else {
        DB::unprepared("create trigger graded_fail before update on graded_items when new.id = {$first->id} begin select raise(abort, 'boom'); end");
    }

    (new ReleaseExpiredReservations)->handle();

    if (DB::getDriverName() === 'pgsql') {
        DB::unprepared('drop trigger graded_fail on graded_items');
        DB::unprepared('drop function graded_fail()');
    } else {
        DB::unprepared('drop trigger graded_fail');
    }

    expect($second->fresh()->status)->toBe(GradedStatus::Listed)
        ->and($first->fresh()->status)->toBe(GradedStatus::Reserved);
    Exceptions::assertReported(QueryException::class);
});

test('a slab relisted and sold again keeps its sold state when the old order is cancelled', function () {
    Notification::fake();
    config(['shop.promptpay_id' => '0812345678']);
    $item = gradedItem();

    $old = app(PlaceOrder::class)->handle(gradedCart([$item]), gradedCheckoutData());
    expect($item->fresh()->status)->toBe(GradedStatus::Sold);

    // Staff relist it by hand, and another customer buys it.
    $item->fresh()->update(['status' => GradedStatus::Listed]);
    $new = app(PlaceOrder::class)->handle(gradedCart([$item->fresh()]), gradedCheckoutData());

    app(CancelOrder::class)->handle($old, 'ยกเลิกคำสั่งซื้อเก่า');

    expect($item->fresh()->status)->toBe(GradedStatus::Sold);

    app(CancelOrder::class)->handle($new, 'ยกเลิก');

    expect($item->fresh()->status)->toBe(GradedStatus::Listed);
});

test('a swap locks the line variant and the candidate in ascending id order before any other read', function () {
    $printing = gradedPrinting();
    $sibling = Variant::factory()->for($printing)->create(['condition' => CardCondition::Lp, 'qty' => 3, 'price_satang' => 1000]);
    $variant = Variant::factory()->for($printing)->create(['condition' => CardCondition::Nm, 'qty' => 1, 'price_satang' => 5000]);
    expect($sibling->id)->toBeLessThan($variant->id);

    $line = app(AddToCart::class)->handle(Cart::factory()->create(), $variant, 1);
    StockReservation::query()->update(['expires_at' => now()->subMinute()]);
    app(AddToCart::class)->handle(Cart::factory()->create(), $variant, 1);
    expect(app(RenewCartItem::class)->handle($line)->substitute?->id)->toBe($sibling->id);

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = ['BEGIN', []];
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = [$query->sql, $query->bindings];
    });

    expect(app(SwapCartItem::class)->handle($line, $sibling->id))->not->toBeNull();

    $begin = collect($log)->search(fn (array $entry): bool => $entry[0] === 'BEGIN');

    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1][0])->toStartWith('select * from "variants" where "id" in (')
        // Both ids in the one locking read, which takes the rows in ascending id order, whatever order they were named in.
        ->and(array_map('intval', $log[$begin + 1][1]))->toEqualCanonicalizing([$sibling->id, $variant->id])
        ->and($log[$begin + 1][0])->toContain('order by "id" asc');
});

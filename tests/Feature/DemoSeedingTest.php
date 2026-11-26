<?php

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\BuylistPrice;
use App\Models\GradedItem;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\PricePoint;
use App\Models\SealedProduct;
use App\Models\User;
use Database\Seeders\BuylistPriceSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OrderSeeder;
use Database\Seeders\PricePointSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Seeds the way `db:seed` does in the given environment, with `SHOP_DEMO_DATA` on or off. */
function seedAs(string $environment, bool $demoData): void
{
    app()['env'] = $environment;
    config(['shop.demo_data' => $demoData]);

    // Not `$this->seed()`: `db:seed` asks for confirmation in production.
    (new DatabaseSeeder)->setContainer(app())->run();
}

test('production without the flag seeds the catalogue only', function () {
    seedAs('production', false);

    expect(Order::count())->toBe(0)
        ->and(PricePoint::count())->toBe(0)
        ->and(GradedItem::count())->toBe(0)
        ->and(SealedProduct::count())->toBe(0)
        ->and(Preorder::count())->toBe(0)
        ->and(BuylistPrice::count())->toBe(0)
        ->and(User::count())->toBe(0)
        ->and(DB::table('variants')->count())->toBeGreaterThan(0);
});

test('production with SHOP_DEMO_DATA seeds the demo content and no known-password account', function () {
    seedAs('production', true);

    expect(Order::count())->toBeGreaterThan(0)
        ->and(PricePoint::count())->toBeGreaterThan(0)
        ->and(GradedItem::count())->toBeGreaterThan(0)
        ->and(SealedProduct::count())->toBeGreaterThan(0)
        ->and(Preorder::count())->toBeGreaterThan(0)
        ->and(BuylistPrice::count())->toBeGreaterThan(0)
        ->and(User::query()->whereIn('email', ['customer@example.com', 'test@example.com'])->exists())->toBeFalse()
        ->and(Order::query()->whereNotNull('user_id')->exists())->toBeFalse()
        ->and(DB::table('buylist_submissions')->count())->toBe(0)
        ->and(DB::table('store_credit_entries')->count())->toBe(0)
        ->and(DB::table('saved_deck_lists')->count())->toBe(0);
});

test('local seeds everything, including the known-password accounts', function () {
    seedAs('local', false);

    expect(Order::count())->toBeGreaterThan(0)
        ->and(BuylistPrice::count())->toBeGreaterThan(0)
        ->and(User::query()->where('email', 'test@example.com')->value('is_staff'))->toBeTruthy()
        ->and(User::query()->where('email', 'customer@example.com')->exists())->toBeTrue()
        ->and(DB::table('buylist_submissions')->count())->toBe(1)
        ->and(DB::table('store_credit_entries')->count())->toBeGreaterThan(0)
        ->and(DB::table('saved_deck_lists')->count())->toBeGreaterThan(0);
});

test('outside local, demo orders are only in states no scheduled job acts on, and mail nobody real', function () {
    seedAs('production', true);

    $statuses = Order::query()->pluck('status')->map->value->unique()->sort()->values()->all();

    expect($statuses)->toBe(['cancelled', 'delivered', 'packing', 'paid', 'refunded', 'shipped'])
        ->and(Order::query()->where('contact_email', 'not like', '%@example.invalid')->exists())->toBeFalse()
        ->and(DB::table('payments')->where('status', PaymentStatus::Submitted->value)->exists())->toBeFalse()
        ->and(Order::query()->where('kind', OrderKind::Preorder->value)->exists())->toBeFalse()
        ->and(Preorder::count())->toBe(2)
        ->and(Preorder::query()->where('reserved_count', '>', 0)->exists())->toBeFalse();
});

test('locally the awaiting-payment orders, the waiting slip and the pre-order deposit order are still seeded', function () {
    seedAs('local', false);

    expect(Order::query()->where('status', OrderStatus::AwaitingPayment->value)->count())->toBe(2)
        ->and(DB::table('payments')->where('status', PaymentStatus::Submitted->value)->exists())->toBeTrue()
        ->and(Order::query()->where('status', OrderStatus::AwaitingBalance->value)->exists())->toBeTrue()
        ->and(Order::query()->where('contact_email', 'like', '%@example.invalid')->exists())->toBeFalse();
});

test('a second db:seed in production demo mode changes nothing and does not throw', function () {
    $snapshot = fn (): array => [
        'orders' => Order::count(),
        'order_items' => DB::table('order_items')->count(),
        'payments' => DB::table('payments')->count(),
        'price_points' => PricePoint::count(),
        'graded' => GradedItem::count(),
        'sealed' => SealedProduct::count(),
        'preorders' => Preorder::count(),
        'buylist_prices' => BuylistPrice::count(),
        'variants' => DB::table('variants')->count(),
        'variant_qty' => (int) DB::table('variants')->sum('qty'),
        'sealed_qty' => (int) DB::table('sealed_products')->sum('qty'),
    ];

    seedAs('production', true);
    $first = $snapshot();

    seedAs('production', true);

    expect($first['orders'])->toBeGreaterThan(0)
        ->and($snapshot())->toBe($first);
});

test('a demo seeder run on its own refuses outside a local or demo environment', function (string $seeder) {
    seedAs('production', false);

    (new $seeder)->setContainer(app())->run();

    expect(Order::count())->toBe(0)
        ->and(PricePoint::count())->toBe(0)
        ->and(BuylistPrice::count())->toBe(0);
})->with([OrderSeeder::class, PricePointSeeder::class, BuylistPriceSeeder::class]);

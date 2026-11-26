<?php

use App\Actions\CancelOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Exceptions\PaymentRefused;
use App\Jobs\CancelUnpaidOrders;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printing;
use App\Models\Variant;
use App\Notifications\OrderCancelled;
use Database\Seeders\OrderSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function cancellableVariant(int $qty): Variant
{
    $card = Card::factory()->for(Game::factory())->create();
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create();

    return Variant::factory()->for($printing)->create(['qty' => $qty, 'price_satang' => 100000]);
}

/**
 * An order whose item is `$qty` of the variant, as PlaceOrder leaves it (the stock is already out of `qty`).
 */
function orderOf(Variant $variant, int $qty, string $state = 'awaitingPayment'): Order
{
    return Order::factory()->{$state}()->has(OrderItem::factory()->state([
        'purchasable_type' => $variant->getMorphClass(),
        'purchasable_id' => $variant->id,
        'qty' => $qty,
        'unit_price_satang' => 100000,
        'line_total_satang' => 100000 * $qty,
    ]), 'items')->create();
}

test('cancelling an order returns the quantity to the variant and rejects its open payment', function () {
    Notification::fake();
    $variant = cancellableVariant(qty: 1);
    $order = orderOf($variant, qty: 2);

    $cancelled = app(CancelOrder::class)->handle($order, 'ลูกค้าขอยกเลิก');

    expect($variant->refresh()->qty)->toBe(3)
        ->and($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($order->payments()->firstOrFail()->status)->toBe(PaymentStatus::Rejected)
        ->and($order->payments()->firstOrFail()->rejected_reason)->toBe('ลูกค้าขอยกเลิก');
});

test('a paid order can be cancelled too, and its quantity comes back', function () {
    Notification::fake();
    $variant = cancellableVariant(qty: 0);
    $order = orderOf($variant, qty: 1, state: 'paid');

    app(CancelOrder::class)->handle($order, 'ของเสียหาย');

    expect($variant->refresh()->qty)->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

test('a variant that was deleted since is skipped and the order still cancels', function () {
    Notification::fake();
    $variant = cancellableVariant(qty: 1);
    $order = orderOf($variant, qty: 1);
    $variant->delete();

    app(CancelOrder::class)->handle($order, 'ยกเลิก');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

test('an order that already shipped or was cancelled cannot be cancelled, and no quantity moves', function (string $state) {
    Notification::fake();
    $variant = cancellableVariant(qty: 1);
    $order = orderOf($variant, qty: 1, state: $state);

    expect(fn () => app(CancelOrder::class)->handle($order, 'ยกเลิก'))->toThrow(IllegalOrderTransition::class);

    expect($variant->refresh()->qty)->toBe(1);
    Notification::assertNothingSent();
})->with(['shipped', 'delivered', 'cancelled']);

test('cancelling notifies the order email with the signed link', function () {
    Notification::fake();
    $order = orderOf(cancellableVariant(qty: 1), qty: 1);

    app(CancelOrder::class)->handle($order, 'ยกเลิก');

    Notification::assertSentOnDemand(OrderCancelled::class, function (OrderCancelled $notification, array $channels, AnonymousNotifiable $notifiable) use ($order): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === $order->contact_email
            && $mail->actionUrl === $order->signedUrl()
            && $notification instanceof ShouldQueue;
    });
});

test('cancelling locks the variants before any other read inside its transaction', function () {
    Notification::fake();
    $order = orderOf(cancellableVariant(qty: 1), qty: 1);

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(CancelOrder::class)->handle($order, 'ยกเลิก');

    $begin = array_search('BEGIN', $log, true);

    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1])->toStartWith('select * from "variants" where "id" in (');
});

test('the unpaid-order job cancels only overdue orders without a submitted slip, and returns the quantity', function () {
    Notification::fake();
    $overdueVariant = cancellableVariant(qty: 0);
    $freshVariant = cancellableVariant(qty: 0);
    $slipVariant = cancellableVariant(qty: 0);
    $paidVariant = cancellableVariant(qty: 0);

    $overdue = orderOf($overdueVariant, 2, 'overdue');
    $fresh = orderOf($freshVariant, 1);
    $withSlip = tap(orderOf($slipVariant, 1, 'overdue'), fn (Order $order) => $order->payments()->update(['status' => PaymentStatus::Submitted->value]));
    $paid = orderOf($paidVariant, 1, 'paid');
    $paid->update(['payment_due_at' => now()->subHour()]);

    app()->call([new CancelUnpaidOrders, 'handle']);

    expect($overdue->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($overdue->cancelled_at)->not->toBeNull()
        ->and($overdue->payments()->firstOrFail()->rejected_reason)->toBe(__('storefront.order.cancel_unpaid'))
        ->and($overdueVariant->refresh()->qty)->toBe(2)
        ->and($fresh->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($freshVariant->refresh()->qty)->toBe(0)
        ->and($withSlip->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($slipVariant->refresh()->qty)->toBe(0)
        ->and($paid->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($paidVariant->refresh()->qty)->toBe(0);

    Notification::assertSentOnDemandTimes(OrderCancelled::class, 1);
});

test('an order becomes overdue exactly when its payment window ends', function () {
    Notification::fake();
    $variant = cancellableVariant(qty: 0);
    $order = orderOf($variant, 1);

    $this->travel(29)->minutes();
    app()->call([new CancelUnpaidOrders, 'handle']);
    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment);

    $this->travel(2)->minutes();
    app()->call([new CancelUnpaidOrders, 'handle']);
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($variant->refresh()->qty)->toBe(1);
});

test('the job is queued and scheduled every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->description, CancelUnpaidOrders::class) || str_contains($event->command ?? '', CancelUnpaidOrders::class));

    expect(new CancelUnpaidOrders)->toBeInstanceOf(ShouldQueue::class)
        ->and($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('seeded open orders took their stock, so cancelling one returns exactly what it took', function () {
    Notification::fake();
    Storage::fake('local');
    $variants = collect(range(1, 7))->map(fn (): Variant => cancellableVariant(qty: 5));
    $before = $variants->mapWithKeys(fn (Variant $variant): array => [$variant->id => $variant->qty]);

    // The seeder's local shape: open orders, a waiting slip. A deployed demo seeds a quieter set.
    app()['env'] = 'local';
    app(OrderSeeder::class)->run();

    $orders = Order::query()->with('items')->orderBy('id')->get();
    $stock = fn (Order $order): int => Variant::query()->findOrFail($order->items->sole()->purchasable_id)->qty;
    $took = fn (Order $order): int => $before[$order->items->sole()->purchasable_id] - $stock($order);

    expect($orders)->toHaveCount(7);

    foreach ($orders as $order) {
        $open = in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::Paid, OrderStatus::Packing], true);

        expect($took($order))->toBe($open ? $order->items->sole()->qty : 0)
            ->and($stock($order))->toBeGreaterThanOrEqual(0);
    }

    $cancellable = $orders->filter(fn (Order $order): bool => in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::Paid], true));

    // The seeded order whose slip waits for staff is decided first: cancelling it is refused.
    [$waiting, $cancellable] = $cancellable->partition(fn (Order $order): bool => $order->payments()->where('status', PaymentStatus::Submitted->value)->exists());

    expect($waiting)->toHaveCount(1)
        ->and(fn () => app(CancelOrder::class)->handle($waiting->sole(), 'ยกเลิก'))->toThrow(PaymentRefused::class);

    foreach ($cancellable as $order) {
        app(CancelOrder::class)->handle($order, 'ยกเลิก');

        expect($stock($order))->toBe($before[$order->items->sole()->purchasable_id]);
    }
});

test('cancelling stores the reason on the order', function () {
    Notification::fake();
    $order = orderOf(cancellableVariant(qty: 1), qty: 1);

    app(CancelOrder::class)->handle($order, 'ลูกค้าขอยกเลิก');

    expect($order->refresh()->cancel_reason)->toBe('ลูกค้าขอยกเลิก');
});

test('the job leaves an order alone when a slip arrives or the window is reopened after it was listed', function (string $change) {
    Notification::fake();
    $variant = cancellableVariant(qty: 0);
    $order = orderOf($variant, 1, 'overdue');

    $flipped = false;
    DB::beforeExecuting(function (string $query) use (&$flipped, $order, $change): void {
        // The locked re-read of the order is the first select by its id; the job's list never filters on the key.
        if ($flipped || ! str_contains($query, 'from "orders" where "orders"."id" = ')) {
            return;
        }

        $flipped = true;

        if ($change === 'slip') {
            DB::table('payments')->where('order_id', $order->id)->update(['status' => PaymentStatus::Submitted->value]);
        } else {
            DB::table('orders')->where('id', $order->id)->update(['payment_due_at' => now()->addMinutes(30)]);
        }
    });

    app()->call([new CancelUnpaidOrders, 'handle']);

    expect($flipped)->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->cancelled_at)->toBeNull()
        ->and($variant->refresh()->qty)->toBe(0);
    Notification::assertNothingSent();
})->with(['slip', 'reopened window']);

test('a guard that fails under the lock makes the action do nothing and return null', function () {
    Notification::fake();
    $variant = cancellableVariant(qty: 0);
    $order = orderOf($variant, 1);

    expect(app(CancelOrder::class)->handle($order, 'ยกเลิก', fn (Order $locked): bool => false))->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($variant->refresh()->qty)->toBe(0);
});

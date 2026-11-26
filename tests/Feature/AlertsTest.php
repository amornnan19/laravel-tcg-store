<?php

use App\Actions\AddToCart;
use App\Actions\CancelOrder;
use App\Actions\ChangeCartQuantity;
use App\Actions\RefundOrder;
use App\Actions\RemoveCartItem;
use App\Actions\RemovePriceAlert;
use App\Actions\RemoveRestockAlert;
use App\Actions\SetPriceAlert;
use App\Actions\SetRestockAlerts;
use App\Actions\ToggleWishlist;
use App\Enums\AlertChannel;
use App\Enums\Finish;
use App\Exceptions\AlertRefused;
use App\Jobs\CheckVariantAlerts;
use App\Jobs\ReleaseExpiredReservations;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Cart;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceAlert;
use App\Models\Printing;
use App\Models\RestockAlert;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Variant;
use App\Models\WishlistItem;
use App\Notifications\PriceAlertFired;
use App\Notifications\RestockAlertFired;
use App\Notifications\ShopNotification;
use App\Support\Money;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function alertVariant(int $qty = 0, ?int $priceSatang = 100000, array $attributes = []): Variant
{
    $card = Card::factory()->for(Game::factory())->create(['name_en' => 'Charizard ex']);
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create();

    return Variant::factory()->for($printing)->create(['qty' => $qty, 'price_satang' => $priceSatang, ...$attributes]);
}

/** Runs the check for a variant now, as the queue would. */
function runAlertCheck(Variant $variant): void
{
    (new CheckVariantAlerts($variant->id))->handle();
}

function alertHold(Variant $variant, int $qty, bool $expired = false): StockReservation
{
    $factory = $expired ? StockReservation::factory()->expired() : StockReservation::factory();

    return $factory->create(['purchasable_id' => $variant->id, 'qty' => $qty]);
}

test('the factories build valid rows', function () {
    expect(WishlistItem::factory()->create()->printing)->toBeInstanceOf(Printing::class)
        ->and(PriceAlert::factory()->fired()->create()->is_active)->toBeFalse()
        ->and(RestockAlert::factory()->notified()->create()->notified_at)->not->toBeNull()
        ->and(RestockAlert::factory()->create()->channel)->toBe(AlertChannel::Email);
});

test('toggling the wishlist adds the printing, then removes it', function () {
    $user = User::factory()->create();
    $printing = alertVariant()->printing;

    expect(app(ToggleWishlist::class)->handle($user, $printing))->toBeTrue()
        ->and($user->wishlistItems()->count())->toBe(1)
        ->and(app(ToggleWishlist::class)->handle($user, $printing))->toBeFalse()
        ->and($user->wishlistItems()->count())->toBe(0);
});

test('a price alert refuses a target that is not above zero', function () {
    app(SetPriceAlert::class)->handle(User::factory()->create(), alertVariant(qty: 3), 0);
})->throws(AlertRefused::class, 'invalid_target');

test('a price alert refuses a variant that cannot be bought', function () {
    app(SetPriceAlert::class)->handle(User::factory()->create(), alertVariant(qty: 3, priceSatang: null), 50000);
})->throws(AlertRefused::class, 'unavailable');

test('a price alert refuses when the price on sale is already at or under the target', function (int $target) {
    $variant = alertVariant(qty: 3, priceSatang: 100000);

    try {
        app(SetPriceAlert::class)->handle(User::factory()->create(), $variant, $target);
    } catch (AlertRefused $refused) {
        expect($refused->reason)->toBe('price_reached')
            ->and($refused->message())->toContain(Money::fromSatang(100000)->format());

        return;
    }

    $this->fail('The alert was not refused.');
})->with([100000, 150000]);

test('a price alert refuses a customer with neither LINE nor an email', function () {
    $user = User::factory()->withLine()->create(['email' => null, 'line_notify_opt_in' => false]);

    app(SetPriceAlert::class)->handle($user, alertVariant(qty: 3), 50000);
})->throws(AlertRefused::class, 'no_channel');

test('a price alert records the channel and one row per user and variant, woken by setting it again', function () {
    $user = User::factory()->create();
    $variant = alertVariant(qty: 3);
    $first = app(SetPriceAlert::class)->handle($user, $variant, 50000);
    PriceAlert::query()->whereKey($first->id)->update(['is_active' => false, 'last_fired_at' => now()]);

    app(SetPriceAlert::class)->handle($user, $variant, 60000);

    $alert = PriceAlert::query()->sole();
    expect($alert->target_satang)->toBe(60000)
        ->and($alert->is_active)->toBeTrue()
        ->and($alert->channel)->toBe(AlertChannel::Email)
        ->and($alert->id)->toBe($first->id);
});

test('a price alert records LINE when the customer prefers it', function () {
    config(['services.line.messaging_token' => 'token']);
    $user = User::factory()->withLine()->create(['line_notify_opt_in' => true]);

    expect(app(SetPriceAlert::class)->handle($user, alertVariant(qty: 3), 50000)->channel)->toBe(AlertChannel::Line);
});

test('a sold-out variant at or under the target may be watched: it waits for stock', function () {
    $alert = app(SetPriceAlert::class)->handle(User::factory()->create(), alertVariant(qty: 0, priceSatang: 100000), 150000);

    expect($alert->is_active)->toBeTrue();
});

test('restock alerts skip variants in stock and variants that cannot be bought, and take many ids', function () {
    $user = User::factory()->create();
    $soldOutA = alertVariant(qty: 0);
    $soldOutB = alertVariant(qty: 2);
    alertHold($soldOutB, 2);
    $inStock = alertVariant(qty: 4);
    $unpriced = alertVariant(qty: 0, priceSatang: null);

    $set = app(SetRestockAlerts::class)->handle($user, [$soldOutA->id, $soldOutB->id, $inStock->id, $unpriced->id, 999999]);

    expect($set)->toBe(2)
        ->and($user->restockAlerts()->pluck('variant_id')->sort()->values()->all())->toBe([$soldOutA->id, $soldOutB->id]);
});

test('restock alerts wake one that fired and refuse a customer with no channel', function () {
    $user = User::factory()->create();
    $variant = alertVariant(qty: 0);
    $fired = RestockAlert::factory()->notified()->create(['user_id' => $user->id, 'variant_id' => $variant->id]);

    app(SetRestockAlerts::class)->handle($user, [$variant->id]);

    expect($fired->refresh()->is_active)->toBeTrue()->and($fired->notified_at)->toBeNull()
        ->and(RestockAlert::query()->count())->toBe(1);

    $noChannel = User::factory()->withLine()->create(['email' => null, 'line_notify_opt_in' => false]);

    expect(fn () => app(SetRestockAlerts::class)->handle($noChannel, [$variant->id]))->toThrow(AlertRefused::class, 'no_channel');
});

test('removing an alert deletes the customer\'s own and never another customer\'s', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $price = PriceAlert::factory()->create(['user_id' => $owner->id]);
    $restock = RestockAlert::factory()->create(['user_id' => $owner->id]);

    expect(app(RemovePriceAlert::class)->handle($other, $price))->toBeFalse()
        ->and(app(RemoveRestockAlert::class)->handle($other, $restock))->toBeFalse()
        ->and(PriceAlert::query()->count() + RestockAlert::query()->count())->toBe(2)
        ->and(app(RemovePriceAlert::class)->handle($owner, $price))->toBeTrue()
        ->and(app(RemoveRestockAlert::class)->handle($owner, $restock))->toBeTrue()
        ->and(PriceAlert::query()->count() + RestockAlert::query()->count())->toBe(0);
});

test('a restock alert fires once when stock goes from none to some through a model save, then deactivates', function () {
    Notification::fake();
    $variant = alertVariant(qty: 0);
    $alert = RestockAlert::factory()->create(['variant_id' => $variant->id]);

    Queue::fake();
    $variant->update(['qty' => 3]);
    Queue::assertPushed(CheckVariantAlerts::class, fn (CheckVariantAlerts $job): bool => $job->variantId === $variant->id);

    runAlertCheck($variant);
    runAlertCheck($variant);

    Notification::assertSentToTimes($alert->user, RestockAlertFired::class, 1);
    expect($alert->refresh()->is_active)->toBeFalse()->and($alert->notified_at)->not->toBeNull();
});

test('an alert does not fire while every unit is held by a reservation', function () {
    Notification::fake();
    $variant = alertVariant(qty: 2);
    $alert = RestockAlert::factory()->create(['variant_id' => $variant->id]);
    alertHold($variant, 2);

    runAlertCheck($variant);

    Notification::assertNothingSent();
    expect($alert->refresh()->is_active)->toBeTrue();
});

test('an alert fires after the release job frees the stock', function () {
    Notification::fake();
    $variant = alertVariant(qty: 2);
    $alert = RestockAlert::factory()->create(['variant_id' => $variant->id]);
    alertHold($variant, 2, expired: true);

    // Synchronous queue: the release job dispatches the check, which runs now.
    (new ReleaseExpiredReservations)->handle();

    expect(StockReservation::query()->count())->toBe(0);
    Notification::assertSentTo($alert->user, RestockAlertFired::class);
});

test('an alert fires after a cancelled order returns its quantity', function () {
    Notification::fake();
    $variant = alertVariant(qty: 0);
    $alert = RestockAlert::factory()->create(['variant_id' => $variant->id]);
    $order = Order::factory()->awaitingPayment()->has(OrderItem::factory()->state([
        'purchasable_type' => $variant->getMorphClass(),
        'purchasable_id' => $variant->id,
        'qty' => 2,
        'unit_price_satang' => 100000,
        'line_total_satang' => 200000,
    ]), 'items')->create();

    app(CancelOrder::class)->handle($order, 'test');

    Notification::assertSentTo($alert->user, RestockAlertFired::class);
});

test('cancelling and refunding each queue exactly one check per returned variant', function () {
    config(['queue.default' => 'database']);
    Notification::fake();
    $queuedChecks = fn (Variant $variant): int => DB::table('jobs')->where('payload', 'like', '%CheckVariantAlerts%')->where('payload', 'like', '%variantId__;i:'.$variant->id.';%')->count();
    $make = fn (Variant $variant, string $state) => Order::factory()->{$state}()->has(OrderItem::factory()->count(2)->state([
        'purchasable_type' => $variant->getMorphClass(),
        'purchasable_id' => $variant->id,
        'qty' => 1,
        'unit_price_satang' => 100000,
        'line_total_satang' => 100000,
    ]), 'items')->create();
    $cancelled = alertVariant(qty: 0);
    $refunded = alertVariant(qty: 0);

    app(CancelOrder::class)->handle($make($cancelled, 'awaitingPayment'), 'test');
    app(RefundOrder::class)->handle($make($refunded, 'paid'), 'test');

    // Two items of one variant save it twice and the action asks once more: still one waiting job.
    expect($queuedChecks($cancelled))->toBe(1)->and($queuedChecks($refunded))->toBe(1);
});

test('the check is queued after the surrounding transaction commits, once per variant', function () {
    // A real queue: the fake pushes at once, whatever `afterCommit` says.
    config(['queue.default' => 'database']);
    $variant = alertVariant(qty: 0);
    $queuedChecks = fn (): int => DB::table('jobs')->where('payload', 'like', '%CheckVariantAlerts%')->count();

    DB::transaction(function () use ($variant, $queuedChecks): void {
        $variant->update(['qty' => 1]);
        $variant->update(['qty' => 2]);
        $variant->update(['note_th' => 'unrelated']);

        expect($queuedChecks())->toBe(0);
    });

    // Two quantity changes, one queued check: the unique lock drops the second.
    expect($queuedChecks())->toBe(1);
});

test('a rolled-back transaction queues nothing, and the unique lock is not taken before the commit', function () {
    config(['queue.default' => 'database']);
    $variant = alertVariant(qty: 0);
    $queuedChecks = fn (): int => DB::table('jobs')->where('payload', 'like', '%CheckVariantAlerts%')->count();
    $lock = fn () => Cache::lock(UniqueLock::getKey(new CheckVariantAlerts($variant->id)), 10);

    try {
        DB::transaction(function () use ($variant): void {
            $variant->update(['qty' => 3]);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect($queuedChecks())->toBe(0);

    DB::transaction(function () use ($variant, $lock): void {
        $variant->update(['qty' => 5]);

        // Taken inside the transaction, the lock would make a check that starts before the commit swallow this change.
        $probe = $lock();
        expect($probe->get())->toBeTrue();
        $probe->release();
    });

    expect($queuedChecks())->toBe(1);
});

test('dispatches for a variant while its check waits collapse into one job', function () {
    config(['queue.default' => 'database']);
    $variant = alertVariant(qty: 0);

    CheckVariantAlerts::dispatch($variant->id);
    CheckVariantAlerts::dispatch($variant->id);
    CheckVariantAlerts::dispatch($variant->id + 1);

    expect(DB::table('jobs')->where('payload', 'like', '%CheckVariantAlerts%')->count())->toBe(2);
});

test('a change committed while a check is running gets a job of its own', function () {
    config(['queue.default' => 'database']);
    Notification::fake();
    $variant = alertVariant(qty: 3);
    RestockAlert::factory()->create(['variant_id' => $variant->id]);
    $alert = RestockAlert::query()->where('variant_id', $variant->id)->sole();
    CheckVariantAlerts::dispatch($variant->id);
    $jobs = fn () => DB::table('jobs')->where('payload', 'like', '%CheckVariantAlerts%')->pluck('id')->all();
    $first = $jobs();
    expect($first)->toHaveCount(1);

    // The running check has read the variant; now a price drop commits and asks for another check.
    RestockAlert::retrieved(function () use ($variant): void {
        Variant::query()->whereKey($variant->id)->first()->update(['price_satang' => 1000]);
    });

    try {
        // Scout's indexing jobs share the table; only the check is run here.
        DB::table('jobs')->where('payload', 'not like', '%CheckVariantAlerts%')->delete();
        Queue::connection('database')->pop()->fire();
    } finally {
        RestockAlert::flushEventListeners();
    }

    // The first job ran (it claimed the alert) and, because its lock was released when it started, the price drop made
    // while it ran is queued as a new job.
    expect($alert->refresh()->is_active)->toBeFalse()
        ->and($jobs())->toHaveCount(1)
        ->and($jobs())->not->toBe($first);
});

test('lowering a cart line or removing it queues a check, raising it does not', function () {
    $variant = alertVariant(qty: 5);
    $cart = Cart::factory()->create();
    $item = app(AddToCart::class)->handle($cart, $variant, 3);

    Queue::fake();
    app(ChangeCartQuantity::class)->handle($item, 4);
    Queue::assertNothingPushed();

    app(ChangeCartQuantity::class)->handle($item->refresh(), 2);
    Queue::assertPushed(CheckVariantAlerts::class, 1);

    Queue::fake();
    app(RemoveCartItem::class)->handle($item->refresh());
    Queue::assertPushed(CheckVariantAlerts::class, fn (CheckVariantAlerts $job): bool => $job->variantId === $variant->id);
});

test('a price alert fires when the price drops to the target, and not above it', function () {
    Notification::fake();
    $variant = alertVariant(qty: 3, priceSatang: 100000);
    $alert = PriceAlert::factory()->create(['variant_id' => $variant->id, 'target_satang' => 80000]);

    runAlertCheck($variant);
    Notification::assertNothingSent();

    $variant->update(['price_satang' => 80000]);

    Notification::assertSentTo($alert->user, PriceAlertFired::class, fn (PriceAlertFired $n): bool => $n->targetSatang === 80000);
    expect($alert->refresh()->is_active)->toBeFalse()->and($alert->last_fired_at)->not->toBeNull();
});

test('a price alert on a sold-out variant waits, then fires on restock', function () {
    Notification::fake();
    $variant = alertVariant(qty: 0, priceSatang: 70000);
    $alert = PriceAlert::factory()->create(['variant_id' => $variant->id, 'target_satang' => 80000]);

    runAlertCheck($variant);
    Notification::assertNothingSent();
    expect($alert->refresh()->is_active)->toBeTrue();

    $variant->update(['qty' => 2]);

    Notification::assertSentTo($alert->user, PriceAlertFired::class);
});

test('an unpurchasable variant never fires', function (Closure $break) {
    Notification::fake();
    $variant = alertVariant(qty: 3);
    $restock = RestockAlert::factory()->create(['variant_id' => $variant->id]);
    $price = PriceAlert::factory()->create(['variant_id' => $variant->id, 'target_satang' => 999999]);
    $break($variant);

    runAlertCheck($variant);

    Notification::assertNothingSent();
    expect($restock->refresh()->is_active)->toBeTrue()->and($price->refresh()->is_active)->toBeTrue();
})->with([
    'no price' => [fn (Variant $variant) => Variant::query()->whereKey($variant->id)->update(['price_satang' => null])],
    'trashed printing' => [fn (Variant $variant) => $variant->printing->delete()],
    'trashed card' => [fn (Variant $variant) => $variant->printing->card->delete()],
    'inactive game' => [fn (Variant $variant) => $variant->printing->card->game->update(['is_active' => false])],
]);

test('an alert claimed by another worker between the read and the update is not notified twice', function (string $model) {
    Notification::fake();
    $variant = alertVariant(qty: 3, priceSatang: 50000);
    $alert = $model::factory()->create(['variant_id' => $variant->id, ...($model === PriceAlert::class ? ['target_satang' => 90000] : [])]);
    // Another worker claims the row right after this run has read it.
    $model::retrieved(fn ($row) => $model::query()->whereKey($row->id)->update(['is_active' => false]));

    try {
        runAlertCheck($variant);
    } finally {
        $model::flushEventListeners();
    }

    Notification::assertNothingSent();
})->with([RestockAlert::class, PriceAlert::class]);

test('the alert notifications are shop notifications that name the card and link to its page', function () {
    $variant = alertVariant(qty: 3, priceSatang: 80000, attributes: ['finish' => Finish::Foil]);
    $user = User::factory()->create();
    $restock = new RestockAlertFired($variant, $user->email);
    $price = new PriceAlertFired($variant, 90000, $user->email);
    $url = route('card.show', ['card' => $variant->printing->card, 'p' => $variant->printing->id]);

    expect($restock)->toBeInstanceOf(ShopNotification::class)->and($price)->toBeInstanceOf(ShopNotification::class);

    foreach ([$restock, $price] as $notification) {
        $line = $notification->toLine($user);

        expect($line)->toContain('Charizard ex')
            ->and($line)->toContain($variant->printing->display_code)
            ->and($line)->toContain($variant->printing->language->shortCode())
            ->and($line)->toContain($variant->conditionFinishLabel())
            ->and($line)->toContain(Money::fromSatang(80000)->format())
            ->and($line)->toEndWith("\n".$url);
    }

    expect($price->toLine($user))->toContain(Money::fromSatang(90000)->format())
        ->and(implode(' ', $restock->toMail($user)->introLines))->toContain('Charizard ex')
        ->and($restock->toMail($user)->actionUrl)->toBe($url);
});

test('a customer who prefers LINE gets the alert on LINE, otherwise by email', function () {
    config(['services.line.messaging_token' => 'token']);
    $variant = alertVariant(qty: 3);
    $line = User::factory()->withLine()->create(['line_notify_opt_in' => true]);
    $mail = User::factory()->create();

    expect((new RestockAlertFired($variant))->via($line))->toBe(['line'])
        ->and((new PriceAlertFired($variant, 1))->via($mail))->toBe(['mail']);
});

test('a LINE alert that cannot be delivered falls back to the email it carries', function () {
    Notification::fake();
    $variant = alertVariant(qty: 3);
    $notification = new RestockAlertFired($variant, 'owner@example.com');

    $notification->failed(new ConnectionException('LINE is down'));

    Notification::assertSentOnDemand(RestockAlertFired::class, fn ($n, array $channels, AnonymousNotifiable $notifiable): bool => $channels === ['mail']
        && $notifiable->routes['mail'] === 'owner@example.com');
});

test('a LINE alert for a customer without an email has no fallback', function () {
    Notification::fake();
    $notification = new PriceAlertFired(alertVariant(qty: 3), 1, null);

    $notification->failed(new ConnectionException('LINE is down'));

    expect($notification->fallbackMailRoute(null))->toBeNull();
    Notification::assertNothingSent();
});

test('the stock hook asks for a check only when stock goes up or the price goes down', function (array $before, array $change, bool $dispatches) {
    $variant = alertVariant(...$before);
    Queue::fake();

    $variant->update($change);

    $dispatches ? Queue::assertPushed(CheckVariantAlerts::class, 1) : Queue::assertNotPushed(CheckVariantAlerts::class);
})->with([
    'stock up' => [['qty' => 1], ['qty' => 2], true],
    'stock down' => [['qty' => 2], ['qty' => 1], false],
    'price down' => [['qty' => 1, 'priceSatang' => 5000], ['price_satang' => 4000], true],
    'price up' => [['qty' => 1, 'priceSatang' => 5000], ['price_satang' => 6000], false],
    'price set from none' => [['qty' => 1, 'priceSatang' => null], ['price_satang' => 4000], true],
    'price cleared' => [['qty' => 1, 'priceSatang' => 5000], ['price_satang' => null], false],
]);

test('a variant that becomes buyable without a qty or price change gets checked', function (Closure $restore) {
    $variant = alertVariant(qty: 3);
    $other = alertVariant(qty: 3);
    RestockAlert::factory()->create(['variant_id' => $variant->id]);
    PriceAlert::factory()->create(['variant_id' => $other->id, 'target_satang' => 1000]);
    $bystander = alertVariant(qty: 3);
    $restore($variant, $other, 'hide');
    Queue::fake();

    $restore($variant, $other, 'show');

    Queue::assertPushed(CheckVariantAlerts::class, fn (CheckVariantAlerts $job): bool => $job->variantId === $variant->id);
    Queue::assertNotPushed(CheckVariantAlerts::class, fn (CheckVariantAlerts $job): bool => $job->variantId === $bystander->id);
})->with([
    'game switched on' => [function (Variant $variant, Variant $other, string $step) {
        $game = $variant->printing->card->game;
        $step === 'hide' ? $game->update(['is_active' => false]) : $game->update(['is_active' => true]);
    }],
    'printing restored' => [function (Variant $variant, Variant $other, string $step) {
        $step === 'hide' ? $variant->printing->delete() : Printing::withTrashed()->find($variant->printing_id)->restore();
    }],
    'card restored' => [function (Variant $variant, Variant $other, string $step) {
        $step === 'hide' ? $variant->printing->card->delete() : Card::withTrashed()->find($variant->printing->card_id)->restore();
    }],
]);

test('a queued alert still sends after its printing is trashed, with the text it had when it fired', function () {
    config(['queue.default' => 'database']);
    $variant = alertVariant(qty: 3, priceSatang: 80000);
    $user = User::factory()->create();
    $variant->printing->card->update(['name_en' => 'Fired Name']);
    $notification = new RestockAlertFired($variant->fresh(), $user->email);
    $expectedLine = $notification->toLine($user);

    // While it waits: the price goes up and the printing is trashed.
    Variant::query()->whereKey($variant->id)->update(['price_satang' => 99900]);
    $variant->printing->delete();

    $serialised = unserialize(serialize($notification));

    expect($serialised->toLine($user))->toBe($expectedLine)
        ->and($serialised->toLine($user))->toContain('Fired Name')
        ->and($serialised->toLine($user))->toContain(Money::fromSatang(80000)->format());

    Notification::fake();
    $user->notify($serialised);
    Notification::assertSentTo($user, RestockAlertFired::class, fn (RestockAlertFired $n): bool => $n->toMail($user)->subject === __('notifications.restock_alert.subject', ['card' => 'Fired Name']));
});

test('the release job keeps a hold that was renewed between its read and its delete', function () {
    $variant = alertVariant(qty: 5);
    $cart = Cart::factory()->create();
    app(AddToCart::class)->handle($cart, $variant, 1);
    $hold = StockReservation::query()->sole();
    $hold->update(['expires_at' => now()->subMinute()]);
    $renewed = false;

    DB::listen(function ($query) use (&$renewed, $variant, $cart): void {
        // The job's read of the expired holds has just returned: the customer renews the same row in place.
        if (! $renewed && str_contains($query->sql, 'stock_reservations') && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'expires_at')) {
            $renewed = true;
            app(AddToCart::class)->handle($cart, $variant, 1);
        }
    });

    (new ReleaseExpiredReservations)->handle();

    expect($renewed)->toBeTrue()
        ->and(StockReservation::query()->count())->toBe(1)
        ->and(StockReservation::query()->sole()->id)->toBe($hold->id)
        ->and(StockReservation::query()->sole()->isLive())->toBeTrue();
});

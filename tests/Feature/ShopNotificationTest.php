<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Channels\LineChannel;
use App\Notifications\OrderCancelled;
use App\Notifications\OrderDelivered;
use App\Notifications\OrderPacked;
use App\Notifications\OrderPlaced;
use App\Notifications\OrderRefunded;
use App\Notifications\OrderShipped;
use App\Notifications\PaymentConfirmed;
use App\Notifications\PaymentRejected;
use App\Notifications\ShopNotification;
use App\Support\Money;
use App\Support\OrderRecipient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.line.messaging_token' => 'messaging-token']);
});

function optedInLineUser(array $overrides = []): User
{
    return User::factory()->withLine()->create(['line_notify_opt_in' => true, ...$overrides]);
}

test('via picks line for a linked, opted-in user when the token is set', function () {
    $user = optedInLineUser();

    expect((new OrderDelivered(Order::factory()->for($user)->delivered()->create()))->via($user))->toBe(['line']);
});

test('via falls back to mail when the user has not opted in, has no LINE, or the token is missing', function (Closure $make, ?string $token) {
    config(['services.line.messaging_token' => $token]);
    $user = $make();

    expect((new OrderDelivered(Order::factory()->create()))->via($user))->toBe(['mail']);
})->with([
    'linked but not opted in' => [fn () => User::factory()->withLine()->create(['line_notify_opt_in' => false]), 'messaging-token'],
    'opted in without LINE' => [fn () => User::factory()->create(['line_notify_opt_in' => true]), 'messaging-token'],
    'opted in but no token' => [fn () => optedInLineUser(), null],
]);

test('via sends mail to an anonymous mail route and nothing when there is no address', function () {
    $notification = new OrderDelivered(Order::factory()->create());
    $withMail = Notification::route('mail', 'guest@example.com');
    $withoutMail = Notification::route('sms', '0800000000');
    $noEmailUser = User::factory()->withLine()->create(['email' => null, 'line_notify_opt_in' => false]);

    expect($notification->via($withMail))->toBe(['mail'])
        ->and($notification->via($withoutMail))->toBe([])
        ->and($notification->via($noEmailUser))->toBe([]);
});

test('the line channel pushes the text to the user id with the bearer token', function () {
    Http::fake();
    $user = optedInLineUser();
    $order = Order::factory()->for($user)->delivered()->create();
    $notification = new OrderDelivered($order);

    $user->notifyNow($notification);

    Http::assertSent(function (HttpRequest $request) use ($user, $notification): bool {
        return $request->url() === 'https://api.line.me/v2/bot/message/push'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer messaging-token')
            && $request['to'] === $user->line_user_id
            && $request['messages'] === [['type' => 'text', 'text' => $notification->toLine($user)]];
    });
    Http::assertSentCount(1);
});

test('the line channel does nothing when the notifiable has no LINE id', function () {
    Http::fake();
    $user = User::factory()->create();

    app(LineChannel::class)->send($user, new OrderDelivered(Order::factory()->create()));

    Http::assertNothingSent();
});

function linePushOrder(?User $user = null): array
{
    $user ??= optedInLineUser();
    $order = Order::factory()->for($user)->delivered()->create();

    return [$user, $order, new OrderDelivered($order)];
}

test('the line channel sends the notification id as the retry key', function () {
    Http::fake();
    [$user, , $notification] = linePushOrder();
    $notification->id = '3f1c8f0e-6a52-4a53-9a46-0d9d1b1c2e11';

    $user->notifyNow($notification);

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('X-Line-Retry-Key', '3f1c8f0e-6a52-4a53-9a46-0d9d1b1c2e11'));
});

test('a 409 means LINE already took the retry key and is not an error', function () {
    Http::fake(['api.line.me/*' => Http::response(['message' => 'conflict'], 409)]);
    Notification::fake();
    [$user, , $notification] = linePushOrder();

    app(LineChannel::class)->send($user, $notification);

    Notification::assertNothingSent();
    Http::assertSentCount(1);
});

test('a 429 or a 5xx throws so the queue retries', function (int $status) {
    Http::fake(['api.line.me/*' => Http::response(['message' => 'later'], $status)]);
    Notification::fake();
    [$user, , $notification] = linePushOrder();

    expect(fn () => app(LineChannel::class)->send($user, $notification))->toThrow(RequestException::class);
    Notification::assertNothingSent();
})->with([429, 500, 503]);

test('a connection error throws so the queue retries', function () {
    Http::fake(fn () => throw new ConnectionException('unreachable'));
    [$user, , $notification] = linePushOrder();

    expect(fn () => app(LineChannel::class)->send($user, $notification))->toThrow(ConnectionException::class);
});

test('any other 4xx falls back to the order email and does not throw', function () {
    Http::fake(['api.line.me/*' => Http::response(['message' => 'bad'], 400)]);
    Notification::fake();
    [$user, $order, $notification] = linePushOrder();

    app(LineChannel::class)->send($user, $notification);

    Notification::assertSentOnDemand(OrderDelivered::class, fn (OrderDelivered $sent, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $order->contact_email && $channels === ['mail']);
});

test('a user who unlinked after the message was queued gets the email instead', function () {
    Http::fake();
    Notification::fake();
    [$user, $order, $notification] = linePushOrder();
    $user->forceFill(['line_user_id' => null])->save();

    app(LineChannel::class)->send($user->refresh(), $notification);

    Http::assertNothingSent();
    Notification::assertSentOnDemand(OrderDelivered::class, fn (OrderDelivered $sent, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $order->contact_email);
});

test('failed() sends the email when the retries ran out on a LINE error', function () {
    Notification::fake();
    [, $order, $notification] = linePushOrder();

    $notification->failed(new RequestException(new Illuminate\Http\Client\Response(new Response(500))));

    Notification::assertSentOnDemand(OrderDelivered::class, fn (OrderDelivered $sent, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $order->contact_email && $channels === ['mail']);
});

test('failed() leaves a failure that is not LINE alone', function () {
    Notification::fake();
    [, , $notification] = linePushOrder();

    $notification->failed(new RuntimeException('mail transport down'));

    Notification::assertNothingSent();
});

test('a shop notification gets three tries with a backoff', function () {
    $notification = new OrderDelivered(Order::factory()->create());

    expect($notification->tries)->toBe(3)
        ->and($notification->backoff())->toBe([10, 60]);
});

test('a shop notification is queued after commit', function () {
    expect(new OrderDelivered(Order::factory()->create()))
        ->toBeInstanceOf(ShopNotification::class)
        ->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('a guest order goes to its contact email', function () {
    Notification::fake();
    $order = Order::factory()->delivered()->create();

    OrderRecipient::notify($order, new OrderDelivered($order));

    Notification::assertSentOnDemand(OrderDelivered::class, function (OrderDelivered $notification, array $channels, AnonymousNotifiable $notifiable) use ($order): bool {
        return $notifiable->routes['mail'] === $order->contact_email && $channels === ['mail'];
    });
});

test('an opted-in user order goes to LINE', function () {
    Notification::fake();
    $user = optedInLineUser();
    $order = Order::factory()->for($user)->delivered()->create();

    OrderRecipient::notify($order, new OrderDelivered($order));

    Notification::assertSentTo($user, OrderDelivered::class, fn (OrderDelivered $notification, array $channels): bool => $channels === ['line']);
    Notification::assertSentOnDemandTimes(OrderDelivered::class, 0);
});

test('a linked user who has not opted in still gets the email the order was placed with', function () {
    Notification::fake();
    $user = User::factory()->withLine()->create(['line_notify_opt_in' => false]);
    $order = Order::factory()->for($user)->delivered()->create();

    OrderRecipient::notify($order, new OrderDelivered($order));

    Notification::assertSentOnDemand(OrderDelivered::class, fn (OrderDelivered $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $order->contact_email);
    Notification::assertNotSentTo($user, OrderDelivered::class);
});

test('every order notification writes a line text with the order number and the signed link', function (Closure $make) {
    [$order, $notification] = $make();

    $text = $notification->toLine(new User);

    expect($text)->toContain($order->number)
        ->and($text)->toEndWith("\n".$order->signedUrl());
})->with([
    'placed' => [function () {
        $order = Order::factory()->create();

        return [$order, new OrderPlaced($order)];
    }],
    'cancelled' => [function () {
        $order = Order::factory()->cancelled()->create();

        return [$order, new OrderCancelled($order)];
    }],
    'payment confirmed (paid)' => [function () {
        $order = Order::factory()->paid()->create();

        return [$order, new PaymentConfirmed($order)];
    }],
    'payment confirmed (partial)' => [function () {
        $order = Order::factory()->create();

        return [$order, new PaymentConfirmed($order)];
    }],
    'payment rejected' => [function () {
        $order = Order::factory()->create();
        $payment = Payment::factory()->for($order)->rejected('สลิปไม่ชัด')->create();

        return [$order, new PaymentRejected($order, $payment)];
    }],
    'packed' => [function () {
        $order = Order::factory()->packing()->create();

        return [$order, new OrderPacked($order)];
    }],
    'shipped' => [function () {
        $order = Order::factory()->shipped()->create();

        return [$order, new OrderShipped($order)];
    }],
    'delivered' => [function () {
        $order = Order::factory()->delivered()->create();

        return [$order, new OrderDelivered($order)];
    }],
    'refunded' => [function () {
        $order = Order::factory()->cancelled()->create();

        return [$order, new OrderRefunded($order, 12345)];
    }],
]);

test('the shipped and rejected line texts carry the tracking link and the reason', function () {
    $shipped = Order::factory()->shipped()->create();
    $order = Order::factory()->create();
    $payment = Payment::factory()->for($order)->rejected('ยอดไม่ตรง')->create();

    expect((new OrderShipped($shipped))->toLine(new User))
        ->toContain($shipped->courier->trackingUrl($shipped->tracking_number))
        ->toContain($shipped->tracking_number)
        ->and((new PaymentRejected($order, $payment))->toLine(new User))->toContain('ยอดไม่ตรง');
});

test('the line texts format money through Money', function () {
    $order = Order::factory()->cancelled()->create();

    expect((new OrderRefunded($order, 12345))->toLine(new User))->toContain(Money::fromSatang(12345)->format());
});

test('the opt-in endpoint saves the choice', function () {
    $user = User::factory()->withLine()->create();

    $this->actingAs($user)->post(route('account.notifications'), ['line_notify_opt_in' => '1'])
        ->assertRedirect(route('account'))
        ->assertSessionHas('status');
    expect($user->refresh()->line_notify_opt_in)->toBeTrue();

    $this->actingAs($user)->post(route('account.notifications'))->assertRedirect(route('account'));
    expect($user->refresh()->line_notify_opt_in)->toBeFalse();
});

test('the opt-in endpoint refuses without LINE or without the token', function (bool $linked, ?string $token) {
    config(['services.line.messaging_token' => $token]);
    $user = $linked ? User::factory()->withLine()->create() : User::factory()->create();

    $this->actingAs($user)->post(route('account.notifications'), ['line_notify_opt_in' => '1'])
        ->assertRedirect(route('account'))
        ->assertSessionHas('status_error', __('storefront.account.line_required'));
    expect($user->refresh()->line_notify_opt_in)->toBeFalse();
})->with([
    'no LINE' => [false, 'messaging-token'],
    'no token' => [true, null],
]);

test('the opt-in endpoint rejects a value that is not a boolean and is closed to guests', function () {
    $user = User::factory()->withLine()->create();

    $this->actingAs($user)->post(route('account.notifications'), ['line_notify_opt_in' => 'maybe'])
        ->assertSessionHasErrors('line_notify_opt_in');

    auth()->logout();
    $this->post(route('account.notifications'), ['line_notify_opt_in' => '1'])->assertRedirect(route('login'));
});

test('the account page shows the opt-in form when LINE and the token are there', function () {
    config(['shop.line_oa_url' => 'https://lin.ee/shop']);
    $user = optedInLineUser();

    $this->actingAs($user)->get(route('account'))
        ->assertOk()
        ->assertSee(route('account.notifications'))
        ->assertSee(__('storefront.account.notify_line'))
        ->assertSee('https://lin.ee/shop', false);
});

test('the account page omits the add-friend link when the shop has none', function () {
    config(['shop.line_oa_url' => null]);

    $this->actingAs(optedInLineUser())->get(route('account'))
        ->assertOk()
        ->assertSee(__('storefront.account.notify_line_hint'))
        ->assertDontSee(__('storefront.account.notify_line_add_friend'));
});

test('the cancelled line text says money already in comes back, as the mail does', function () {
    $order = Order::factory()->paid()->create();
    $received = $order->receivedSatang();

    expect($received)->toBeGreaterThan(0)
        ->and((new OrderCancelled($order))->toLine(new User))->toContain(Money::fromSatang($received)->format());

    $unpaid = Order::factory()->cancelled()->create();
    expect((new OrderCancelled($unpaid))->toLine(new User))->not->toContain(__('notifications.order_cancelled.line_refund', ['amount' => '']));
});

test('the rejected line text says the order is still held', function () {
    $order = Order::factory()->create();
    $payment = Payment::factory()->for($order)->rejected()->create();

    expect((new PaymentRejected($order, $payment))->toLine(new User))
        ->toContain(__('notifications.payment_rejected.line_deadline', ['minutes' => config('shop.payment_window_minutes')]));
});

test('the account page copy per state without a usable LINE channel', function () {
    config(['services.line.messaging_token' => null]);
    // LINE Login is not configured here, so the no-channel line is the email-only wording (LineCopyTest covers the configured copy).

    $this->actingAs(User::factory()->create())->get(route('account'))
        ->assertSee(__('storefront.account.notify_email'))
        ->assertDontSee(__('storefront.account.no_channel_email'));

    $this->actingAs(User::factory()->withLine()->create(['email' => null]))->get(route('account'))
        ->assertSee(__('storefront.account.line_not_open'))
        ->assertDontSee(__('storefront.account.no_channel_email'));

    $this->actingAs(User::factory()->create(['email' => null]))->get(route('account'))
        ->assertSee(__('storefront.account.no_channel_email'));
});

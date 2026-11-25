<?php

use App\Livewire\Storefront\PaymentPanel;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.url' => 'https://shop.example',
        'services.line.client_id' => 'dummy-channel-id',
        'services.line.client_secret' => 'dummy-secret',
        'services.line.redirect' => 'https://shop.example/auth/line/callback',
    ]);
});

function lineUser(array $overrides = []): SocialiteUser
{
    return SocialiteUser::fake([
        'id' => 'U1234567890',
        'name' => 'Somchai LINE',
        'email' => null,
        'avatar' => 'https://profile.line-scdn.net/avatar',
        ...$overrides,
    ]);
}

/** A staff user was never signed in: they are a guest and the panel sends them to its own login. */
function assertStaffNotLoggedIn($test): void
{
    $test->assertGuest();
    $test->get('/admin/variants')->assertRedirect(route('filament.admin.auth.login'));
}

test('a guest on the account page goes to the storefront login and a guest on the panel goes to the panel login', function () {
    $this->get('/account')->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->get('/admin/variants')->assertRedirect(route('filament.admin.auth.login'));
});

test('a signed-in visitor on the login page is sent to the account page', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect(route('account'));
});

test('email login signs a customer in and goes to the intended url', function () {
    $user = User::factory()->create();

    $this->get('/account')->assertRedirect(route('login'));

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($user);

    $this->post('/logout')->assertRedirect(route('home'));
    $this->assertGuest();

    $this->withSession(['url.intended' => route('cart')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('cart'));
});

test('a wrong password, a staff user and a user without a password all fail the same way and stay guests', function () {
    $customer = User::factory()->create();
    $staff = User::factory()->staff()->create();
    $lineOnly = User::factory()->withLine()->create(['password' => null]);

    foreach ([
        [$customer->email, 'wrong-password'],
        [$staff->email, 'password'],
        [$lineOnly->email, 'password'],
        ['nobody@example.com', 'password'],
    ] as [$email, $password]) {
        $this->post('/login', ['email' => $email, 'password' => $password])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }
});

test('the sixth login attempt is throttled', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toStartWith('ลองหลายครั้งเกินไป');
    $this->assertGuest();
});

test('registering creates the customer, logs them in and sends a verification link built from APP_URL', function () {
    Notification::fake();

    $this->post('http://evil.example/register', [
        'name' => 'Somchai',
        'email' => 'somchai@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
    ])->assertRedirect(route('account'));

    $user = User::query()->where('email', 'somchai@example.com')->firstOrFail();

    expect($user->is_staff)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('password-123', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, 'https://shop.example/email/verify/'.$user->id.'/') && ! str_contains($url, 'evil.example');
    });
});

test('registering validates the fields', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', ['name' => '', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'other'])
        ->assertSessionHasErrors(['name', 'email', 'password']);

    $this->assertGuest();
});

test('the verification link verifies the address and a tampered hash is a 403', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $url = null;
    $user->sendEmailVerificationNotification();
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url): bool {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });
    $relative = substr($url, strlen('https://shop.example'));

    $other = User::factory()->unverified()->create();
    $this->actingAs($other)->get($relative)->assertForbidden();
    expect($other->fresh()->email_verified_at)->toBeNull();

    $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('other@example.com')], absolute: false);
    $this->actingAs($user)->get($wrongHash)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->actingAs($user)->get($relative)->assertRedirect(route('account'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('an unsigned verification link is a 403 and a line-only user is sent nothing', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/email/verify/'.$user->id.'/'.sha1($user->email))->assertForbidden();

    $noEmail = User::factory()->withLine()->create(['email' => null, 'password' => null]);
    $this->actingAs($noEmail)->post('/email/verification-notification')->assertRedirect(route('account'));
    Notification::assertNothingSent();

    $this->actingAs($user)->post('/email/verification-notification')->assertRedirect(route('account'));
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('forgot password sends a customer a reset link built from APP_URL and says the same for unknown addresses', function () {
    Notification::fake();
    $customer = User::factory()->create();

    $response = $this->post('http://evil.example/forgot-password', ['email' => $customer->email])
        ->assertSessionHas('status', __('passwords.sent_generic'));

    Notification::assertSentTo($customer, ResetPassword::class, function (ResetPassword $notification) use ($customer): bool {
        $url = $notification->toMail($customer)->actionUrl;

        return str_starts_with($url, 'https://shop.example/reset-password/') && ! str_contains($url, 'evil.example');
    });

    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertSessionHas('status', __('passwords.sent_generic'));
});

test('forgot password sends a staff address nothing and gives the same response', function () {
    Notification::fake();
    $staff = User::factory()->staff()->create();

    $this->post('/forgot-password', ['email' => $staff->email])
        ->assertSessionHas('status', __('passwords.sent_generic'));

    Notification::assertNothingSent();
});

test('a reset changes the password and leaves the visitor a guest', function () {
    $customer = User::factory()->create();
    $token = Password::broker()->createToken($customer);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $customer->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertRedirect(route('login'))->assertSessionHas('status', __('passwords.reset'));

    expect(Hash::check('new-password-1', $customer->fresh()->password))->toBeTrue();
    $this->assertGuest();
});

test('a reset for a staff address is refused like an invalid token', function () {
    $staff = User::factory()->staff()->create();
    $token = Password::broker()->createToken($staff);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $staff->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertSessionHasErrors(['email' => __('passwords.token')]);

    expect(Hash::check('password', $staff->fresh()->password))->toBeTrue();
    $this->assertGuest();
});

test('the line redirect is a 404 when LINE is not configured and goes to LINE with the three scopes otherwise', function () {
    config(['services.line.client_id' => '']);
    $this->get('/auth/line')->assertNotFound();

    config(['services.line.client_id' => 'dummy-channel-id']);
    $response = $this->get('/auth/line')->assertRedirectContains('access.line.me');

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect(explode(' ', $query['scope']))->toEqualCanonicalizing(['profile', 'openid', 'email']);
});

test('the line redirect remembers the link intent only for a signed-in visitor', function () {
    $this->withSession(['line.intent' => 'link'])->get('/auth/line');
    expect(session()->has('line.intent'))->toBeFalse();

    $this->actingAs(User::factory()->create())->get('/auth/line');
    expect(session('line.intent'))->toBe('link');
});

test('a LINE login creates a new customer with no password and no email when LINE sent none', function () {
    Socialite::fake('line', lineUser());

    $this->get('/auth/line/callback')->assertRedirect(route('account'));

    $user = User::query()->where('line_user_id', 'U1234567890')->firstOrFail();
    expect($user->name)->toBe('Somchai LINE')
        ->and($user->email)->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->line_avatar_url)->toBe('https://profile.line-scdn.net/avatar')
        ->and($user->is_staff)->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

test('a LINE login with an empty display name falls back to a generic name and keeps LINE email when free', function () {
    Socialite::fake('line', lineUser(['name' => '', 'email' => 'new@example.com']));

    $this->get('/auth/line/callback');

    $user = User::query()->where('line_user_id', 'U1234567890')->firstOrFail();
    expect($user->name)->toBe('LINE user')->and($user->email)->toBe('new@example.com');
});

test('a LINE login for a known line id signs in and refreshes the cached name and avatar', function () {
    $user = User::factory()->withLine()->create(['line_user_id' => 'U1234567890', 'line_display_name' => 'Old name']);
    Socialite::fake('line', lineUser(['name' => 'New name', 'avatar' => 'https://profile.line-scdn.net/new']));

    $this->get('/auth/line/callback')->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->line_display_name)->toBe('New name')
        ->and($user->fresh()->line_avatar_url)->toBe('https://profile.line-scdn.net/new')
        ->and(User::query()->count())->toBe(1);
});

test('a LINE login links to a verified customer with the same email', function () {
    $user = User::factory()->create(['email' => 'match@example.com']);
    Socialite::fake('line', lineUser(['email' => 'match@example.com']));

    $this->get('/auth/line/callback')->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->line_user_id)->toBe('U1234567890')->and(User::query()->count())->toBe(1);
});

test('a LINE login does not link to an unverified match: it creates a new customer with a null email', function () {
    $victim = User::factory()->unverified()->create(['email' => 'victim@example.com']);
    Socialite::fake('line', lineUser(['email' => 'victim@example.com']));

    $this->get('/auth/line/callback')->assertRedirect(route('account'));

    expect($victim->fresh()->line_user_id)->toBeNull();
    $created = User::query()->where('line_user_id', 'U1234567890')->firstOrFail();
    expect($created->id)->not->toBe($victim->id)->and($created->email)->toBeNull();
    $this->assertAuthenticatedAs($created);
});

test('a LINE login is refused for staff, by line id and by email, and the staff user is never signed in', function () {
    $staffWithLine = User::factory()->staff()->create(['line_user_id' => 'U1234567890']);
    Socialite::fake('line', lineUser());

    $this->get('/auth/line/callback')->assertRedirect(route('login'))->assertSessionHas('status_error', __('auth.line_staff'));
    assertStaffNotLoggedIn($this);

    $staffByEmail = User::factory()->staff()->create(['email' => 'staff@example.com']);
    $staffWithLine->delete();
    Socialite::fake('line', lineUser(['id' => 'U-other', 'email' => 'staff@example.com']));

    $this->get('/auth/line/callback');

    expect($staffByEmail->fresh()->line_user_id)->toBeNull();
    $this->assertAuthenticated();
    expect(auth()->id())->not->toBe($staffByEmail->id);
    $this->get('/admin/variants')->assertForbidden();
    expect(auth()->user()->is_staff)->toBeFalsy();
});

test('linking LINE from the account page attaches it to the signed-in customer', function () {
    $user = User::factory()->create();
    Socialite::fake('line', lineUser());

    $this->actingAs($user)->withSession(['line.intent' => 'link'])
        ->get('/auth/line/callback')
        ->assertRedirect(route('account'))
        ->assertSessionHas('status', __('storefront.account.line_linked'));

    expect($user->fresh()->line_user_id)->toBe('U1234567890')->and($user->fresh()->line_display_name)->toBe('Somchai LINE');
});

test('linking refuses a LINE id that belongs to another user and refuses staff', function () {
    User::factory()->withLine()->create(['line_user_id' => 'U1234567890']);
    $user = User::factory()->create();
    Socialite::fake('line', lineUser());

    $this->actingAs($user)->withSession(['line.intent' => 'link'])
        ->get('/auth/line/callback')
        ->assertRedirect(route('account'))
        ->assertSessionHas('status_error', __('storefront.account.line_taken'));
    expect($user->fresh()->line_user_id)->toBeNull();

    $staff = User::factory()->staff()->create();
    Socialite::fake('line', lineUser(['id' => 'U-free']));

    $this->actingAs($staff)->withSession(['line.intent' => 'link'])
        ->get('/auth/line/callback')
        ->assertSessionHas('status_error', __('storefront.account.line_staff'));
    expect($staff->fresh()->line_user_id)->toBeNull();
});

test('a LINE error callback, a state error and a failed user fetch all end on auth.line_failed', function () {
    $this->get('/auth/line/callback?error=access_denied')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status_error', __('auth.line_failed'));

    foreach ([new InvalidStateException, new RuntimeException('boom')] as $exception) {
        Socialite::fake('line', fn () => throw $exception);

        $this->get('/auth/line/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status_error', __('auth.line_failed'));
        $this->assertGuest();
    }

    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['line.intent' => 'link'])
        ->get('/auth/line/callback?error=access_denied')
        ->assertRedirect(route('account'))
        ->assertSessionHas('status_error', __('auth.line_failed'));
});

test('unlinking is refused without a password and email, and otherwise clears the LINE columns and the opt-in', function () {
    $lineOnly = User::factory()->withLine()->create(['password' => null]);

    $this->actingAs($lineOnly)->post('/account/line/unlink')
        ->assertSessionHas('status_error', __('storefront.account.line_unlink_needs_password'));
    expect($lineOnly->fresh()->line_user_id)->not->toBeNull();

    $user = User::factory()->withLine()->create(['line_notify_opt_in' => true, 'line_avatar_url' => 'https://profile.line-scdn.net/a']);

    $this->actingAs($user)->post('/account/line/unlink')->assertRedirect(route('account'));

    $user->refresh();
    expect($user->line_user_id)->toBeNull()
        ->and($user->line_display_name)->toBeNull()
        ->and($user->line_avatar_url)->toBeNull()
        ->and($user->line_notify_opt_in)->toBeFalse();
});

test('logging in claims the session orders that have no owner and leaves another user\'s order alone', function () {
    $other = User::factory()->create();
    $guestOrder = Order::factory()->create();
    $takenOrder = Order::factory()->create(['user_id' => $other->id]);
    $user = User::factory()->create();

    $this->withSession(['orders_placed' => [$guestOrder->id, $takenOrder->id]])
        ->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($guestOrder->fresh()->user_id)->toBe($user->id)->and($takenOrder->fresh()->user_id)->toBe($other->id);
});

test('registering and a LINE login claim the session orders too', function () {
    $registerOrder = Order::factory()->create();
    $this->withSession(['orders_placed' => [$registerOrder->id]])->post('/register', [
        'name' => 'Somchai', 'email' => 'somchai@example.com', 'password' => 'password-123', 'password_confirmation' => 'password-123',
    ]);
    expect($registerOrder->fresh()->user_id)->toBe(User::query()->where('email', 'somchai@example.com')->value('id'));

    auth()->logout();
    $lineOrder = Order::factory()->create();
    Socialite::fake('line', lineUser());
    $this->withSession(['orders_placed' => [$lineOrder->id]])->get('/auth/line/callback');
    expect($lineOrder->fresh()->user_id)->toBe(User::query()->where('line_user_id', 'U1234567890')->value('id'));
});

test('the owner opens their order without a signature or the session, and another signed-in user gets a 404', function () {
    $owner = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)->get(route('order.show', $order->number))->assertOk()->assertSee($order->number);
    $this->actingAs(User::factory()->create())->get(route('order.show', $order->number))->assertNotFound();
});

test('the payment panel and the QR download open for the owner and not for another user', function () {
    $owner = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($owner);
    Livewire::test(PaymentPanel::class, ['orderId' => $order->id])->assertOk();

    $this->actingAs(User::factory()->create());
    Livewire::test(PaymentPanel::class, ['orderId' => $order->id])->assertNotFound();
    $this->get(route('order.qr', $order->number))->assertNotFound();
});

test('the account page lists only the user\'s own orders, newest first', function () {
    $user = User::factory()->create();
    $older = Order::factory()->create(['user_id' => $user->id, 'placed_at' => Carbon::parse('2026-11-01 10:00')]);
    $newer = Order::factory()->create(['user_id' => $user->id, 'placed_at' => Carbon::parse('2026-11-20 10:00')]);
    $foreign = Order::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($user)->get('/account')
        ->assertOk()
        ->assertSeeInOrder([$newer->number, $older->number])
        ->assertDontSee($foreign->number);
});

test('the account page shows the empty state, the verification notice and the LINE section', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/account')
        ->assertOk()
        ->assertSee(__('storefront.account.orders_empty'))
        ->assertSee(__('storefront.account.verify_text', ['email' => $user->email]))
        ->assertSee(__('storefront.account.line_link'));

    $linked = User::factory()->withLine()->create();
    $this->actingAs($linked)->get('/account')
        ->assertOk()
        ->assertDontSee(__('storefront.account.verify_title'))
        ->assertSee(__('storefront.account.line_linked_as', ['name' => $linked->line_display_name]));
});

test('no storefront login path signs a staff user in: the staff probe from the issue', function () {
    $staff = User::factory()->staff()->create(['line_user_id' => 'U-staff']);

    $this->post('/login', ['email' => $staff->email, 'password' => 'password']);
    assertStaffNotLoggedIn($this);

    Socialite::fake('line', lineUser(['id' => 'U-staff']));
    $this->get('/auth/line/callback');
    assertStaffNotLoggedIn($this);

    $token = Password::broker()->createToken($staff);
    $this->post('/reset-password', ['token' => $token, 'email' => $staff->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1']);
    assertStaffNotLoggedIn($this);
});

test('an order the session only opened through a signed link is not claimed by a login, but one it placed is', function () {
    $opened = Order::factory()->create();
    $placed = Order::factory()->create();
    $user = User::factory()->create();

    $this->get($opened->signedUrl())->assertOk();
    expect(session('orders'))->toBe([$opened->id])->and(session('orders_placed'))->toBeNull();

    $this->withSession(['orders' => [$opened->id, $placed->id], 'orders_placed' => [$placed->id]])
        ->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($opened->fresh()->user_id)->toBeNull()->and($placed->fresh()->user_id)->toBe($user->id);
});

test('a stale admin url left by an earlier guest visit is dropped by every storefront login path', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => url('/admin/variants')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('account'));

    auth()->logout();
    $this->withSession(['url.intended' => url('/admin')])->post('/register', [
        'name' => 'Somchai', 'email' => 'somchai@example.com', 'password' => 'password-123', 'password_confirmation' => 'password-123',
    ])->assertRedirect(route('account'));

    auth()->logout();
    Socialite::fake('line', lineUser());
    $this->withSession(['url.intended' => url('/admin/games')])->get('/auth/line/callback')->assertRedirect(route('account'));
});

test('a customer url that merely starts with admin is still followed', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => url('/administration')])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(url('/administration'));
});

test('a password reset ends the sessions that were signed in with the old password', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->get('/account')->assertOk();
    $otherSession = session()->all();

    $this->flushSession();
    auth()->forgetGuards();
    $this->post('/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertRedirect(route('login'));

    auth()->forgetGuards();
    $this->withSession($otherSession)->get('/account')->assertRedirect(route('login'));
});

test('a LINE callback while signed in without the link intent changes nothing', function () {
    $user = User::factory()->create();
    $other = User::factory()->withLine()->create(['line_user_id' => 'U1234567890']);
    Socialite::fake('line', lineUser());

    $this->actingAs($user)->get('/auth/line/callback')->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->line_user_id)->toBeNull()->and($other->fresh()->line_user_id)->toBe('U1234567890');
});

test('email addresses are one account whatever their casing', function () {
    $payload = ['name' => 'Somchai', 'password' => 'password-123', 'password_confirmation' => 'password-123'];

    $this->post('/register', [...$payload, 'email' => ' Mixed@Example.com '])->assertRedirect(route('account'));
    expect(User::query()->pluck('email')->all())->toBe(['mixed@example.com']);

    auth()->logout();
    $this->post('/register', [...$payload, 'email' => 'mixed@example.com'])->assertSessionHasErrors('email');
    $this->post('/register', [...$payload, 'email' => 'MIXED@EXAMPLE.COM'])->assertSessionHasErrors('email');
    expect(User::query()->count())->toBe(1);

    $this->post('/login', ['email' => 'MiXeD@example.com', 'password' => 'password-123'])->assertRedirect(route('account'));
    $this->assertAuthenticated();
});

test('forgot, reset and the LINE email match are case-insensitive too', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'mixed@example.com']);

    $this->post('/forgot-password', ['email' => 'Mixed@Example.com']);
    Notification::assertSentTo($user, ResetPassword::class);

    $this->post('/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => 'MIXED@example.com',
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertRedirect(route('login'));
    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeTrue();

    Socialite::fake('line', lineUser(['email' => 'Mixed@Example.COM']));
    $this->get('/auth/line/callback');
    expect($user->fresh()->line_user_id)->toBe('U1234567890')->and(User::query()->count())->toBe(1);
});

test('the login and register pages give the email button the secondary style only when the LINE button is there', function () {
    $this->get('/login')->assertOk()
        ->assertSee(__('storefront.auth.line_hint'))
        ->assertDontSee('bg-accent px-4 text-small font-medium text-accent-ink transition-transform duration-160 ease-ui active:scale-98 motion-reduce:transition-none"', false);

    config(['services.line.client_id' => '']);
    $this->get('/login')->assertOk()->assertDontSee(__('storefront.auth.line_hint'))
        ->assertSee('bg-accent px-4 text-small font-medium text-accent-ink', false);
});

test('a password reset deletes the stored sessions of an account that had no password', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->withLine()->create(['password' => null]);
    DB::table('sessions')->insert([
        'id' => 'line-session', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp,
    ]);

    $this->post('/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

test('an array email is a validation error, not a server error', function (string $uri) {
    $this->post($uri, ['email' => ['a@example.com'], 'password' => 'password'])
        ->assertSessionHasErrors('email');
})->with(['/login', '/register', '/forgot-password', '/reset-password']);

<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/** A database session row for the user, as the `database` session driver writes it. */
function sessionFor(?User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user?->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

/**
 * Runs the command and returns the password it printed, or null when it printed none.
 *
 * @param  array<string, mixed>  $arguments
 */
function printedPassword(array $arguments): ?string
{
    Artisan::call('shop:make-staff', $arguments);
    preg_match('/password \(shown once\): (\S{24})$/mi', Artisan::output(), $match);

    return $match[1] ?? null;
}

test('a new address gets a verified staff account whose printed password works', function () {
    $password = printedPassword(['email' => '  Owner@Shop.Example ']);

    $user = User::query()->where('email', 'owner@shop.example')->firstOrFail();

    expect($password)->not->toBeNull()
        ->and($user->is_staff)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('owner')
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(Hash::check($password, $user->password))->toBeTrue();
});

test('--name sets the display name of a new account', function () {
    $this->artisan('shop:make-staff', ['email' => 'second@shop.example', '--name' => 'Second'])->assertExitCode(0);

    expect(User::query()->where('email', 'second@shop.example')->value('name'))->toBe('Second');
});

test('promoting an existing customer makes them staff, rotates their token and ends their sessions', function () {
    $customer = User::factory()->create(['email' => 'customer@shop.example', 'remember_token' => 'old-token']);
    $other = User::factory()->create();
    $oldHash = $customer->password;
    sessionFor($customer, 'session-customer');
    sessionFor($other, 'session-other');
    sessionFor(null, 'session-guest');

    $this->artisan('shop:make-staff', ['email' => 'Customer@Shop.Example'])
        ->expectsOutputToContain('Promoted customer@shop.example to staff.')
        ->expectsOutputToContain('ended 1 session(s)')
        ->assertExitCode(0);

    $customer->refresh();

    expect($customer->is_staff)->toBeTrue()
        ->and($customer->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($customer->remember_token)->not->toBe('old-token')
        ->and($customer->remember_token)->toHaveLength(60)
        ->and($customer->password)->toBe($oldHash)
        ->and(DB::table('sessions')->pluck('id')->all())->toEqualCanonicalizing(['session-other', 'session-guest']);
});

test('running it again on a staff account leaves their token and sessions alone', function () {
    $staff = User::factory()->staff()->create(['remember_token' => 'kept-token']);
    sessionFor($staff, 'session-staff');

    $this->artisan('shop:make-staff', ['email' => $staff->email])
        ->expectsOutputToContain('is already staff.')
        ->assertExitCode(0);

    expect($staff->fresh()->remember_token)->toBe('kept-token')
        ->and(DB::table('sessions')->where('id', 'session-staff')->exists())->toBeTrue();
});

test('--reset-password gives an existing account a new password and prints it', function () {
    $customer = User::factory()->create(['email' => 'customer@shop.example']);

    $password = printedPassword(['email' => 'customer@shop.example', '--reset-password' => true]);

    $customer->refresh();

    expect($password)->not->toBeNull()
        ->and(Hash::check($password, $customer->password))->toBeTrue()
        ->and(Hash::check('password', $customer->password))->toBeFalse()
        ->and($customer->is_staff)->toBeTrue();
});

test('an invalid email is refused with exit 1 and creates nothing', function () {
    $this->artisan('shop:make-staff', ['email' => 'not-an-email'])
        ->expectsOutputToContain('is not a valid email address')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

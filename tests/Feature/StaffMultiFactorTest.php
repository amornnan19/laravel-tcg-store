<?php

use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

function staffUserWithAuthenticator(): User
{
    return User::factory()->staff()->withAppAuthentication()->create();
}

test('a staff user without multi-factor set up is sent to enrolment instead of the dashboard', function () {
    $this->actingAs(User::factory()->staff()->create())
        ->get('/admin')
        ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
});

test('the enrolment page opens for a staff user without multi-factor', function () {
    $this->actingAs(User::factory()->staff()->create())
        ->get(route('filament.admin.auth.multi-factor-authentication.set-up-required'))
        ->assertOk()
        ->assertSee('ตั้งค่าการยืนยันตัวตนสองขั้นตอน');
});

test('a staff user without multi-factor cannot reach a resource page either', function () {
    $this->actingAs(User::factory()->staff()->create())
        ->get('/admin/games')
        ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
});

test('a staff user with multi-factor set up is not sent to enrolment', function () {
    $this->actingAs(staffUserWithAuthenticator())
        ->get('/admin')
        ->assertOk();
});

test('signing in with the right password alone does not authenticate a staff user with multi-factor', function () {
    $user = staffUserWithAuthenticator();

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertNoRedirect();

    $this->assertGuest();
});

test('a wrong authenticator code fails the challenge', function () {
    $user = staffUserWithAuthenticator();
    $wrongCode = (new Google2FA)->oathTotp($user->app_authentication_secret, 1);

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->set('data.multiFactor.app.code', $wrongCode)
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.code'])
        ->assertNoRedirect();

    $this->assertGuest();
});

test('a correct authenticator code generated from the stored secret passes the challenge', function () {
    $user = staffUserWithAuthenticator();
    $code = (new Google2FA)->getCurrentOtp($user->app_authentication_secret);

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->set('data.multiFactor.app.code', $code)
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('the multi-factor secret and recovery codes are not exposed in serialization', function () {
    $user = staffUserWithAuthenticator();
    $secret = $user->app_authentication_secret;

    expect($user->toArray())->not->toHaveKeys(['app_authentication_secret', 'app_authentication_recovery_codes'])
        ->and($user->toJson())->not->toContain($secret);
});

test('the multi-factor secret is stored encrypted and is not mass assignable', function () {
    $user = staffUserWithAuthenticator();
    $stored = DB::table('users')->where('id', $user->id)->value('app_authentication_secret');

    expect($stored)->not->toBe($user->app_authentication_secret)
        ->and(User::make(['app_authentication_secret' => 'x', 'app_authentication_recovery_codes' => ['x']])->getAttributes())
        ->not->toHaveKeys(['app_authentication_secret', 'app_authentication_recovery_codes']);
});

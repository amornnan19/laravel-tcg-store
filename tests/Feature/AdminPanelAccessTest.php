<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest requesting the admin panel is redirected to the login page', function () {
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
});

test('the admin login page is reachable', function () {
    $this->get('/admin/login')->assertOk();
});

test('a staff user can open the admin panel', function () {
    $this->actingAs(User::factory()->staff()->withAppAuthentication()->create())
        ->get('/admin')
        ->assertOk();
});

test('is_staff cannot be set through mass assignment', function () {
    $user = User::create([
        'name' => 'Customer',
        'email' => 'customer@example.com',
        'password' => 'secret-password',
        'is_staff' => true,
    ]);

    expect($user->fresh()->is_staff)->toBeFalse();
});

test('a customer who is not staff cannot open the admin panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

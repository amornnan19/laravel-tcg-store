<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the first home visit of a session opens the demo notice and a second does not', function () {
    config(['shop.demo_notice' => true]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('role="dialog"', false)
        ->assertSee(__('storefront.demo_notice.title'))
        ->assertSee(config('shop.source_url'), false);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee(__('storefront.demo_notice.title'));
});

test('the demo notice never shows while it is switched off', function () {
    config(['shop.demo_notice' => false]);

    $this->get(route('home'))->assertOk()->assertDontSee(__('storefront.demo_notice.title'));
});

test('the demo notice is only for the home page', function () {
    config(['shop.demo_notice' => true]);

    $this->get(route('search'))->assertOk()->assertDontSee(__('storefront.demo_notice.title'));

    $this->get(route('home'))->assertSee(__('storefront.demo_notice.title'));
});

test('the demo notice drops its source link when the url is empty', function () {
    config(['shop.demo_notice' => true, 'shop.source_url' => '']);

    $this->get(route('home'))
        ->assertSee(__('storefront.demo_notice.title'))
        ->assertDontSee(__('storefront.demo_notice.source'));
});

test('the storefront and the login page link the favicon', function () {
    $this->get(route('home'))->assertSee('/favicon.svg', false);
    $this->get(route('login'))->assertSee('/favicon.svg', false);
});

test('the favicon svg is well-formed xml, so browsers do not render it as a broken image', function (): void {
    $previous = libxml_use_internal_errors(true);

    $document = simplexml_load_file(public_path('favicon.svg'));
    $errors = libxml_get_errors();

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($document)->not->toBeFalse()
        ->and($errors)->toBe([]);
});

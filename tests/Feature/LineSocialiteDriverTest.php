<?php

use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Line\Provider;

test('the line socialite driver is registered', function () {
    config(['services.line' => [
        'client_id' => 'dummy-channel-id',
        'client_secret' => 'dummy-channel-secret',
        'redirect' => 'http://localhost/auth/line/callback',
    ]]);

    expect(Socialite::driver('line'))->toBeInstanceOf(Provider::class);
});

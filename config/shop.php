<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sample price history
    |--------------------------------------------------------------------------
    |
    | While `price_points` is filled by PricePointSeeder and not by the nightly job that reads real orders,
    | the card page's price chart says so ("ข้อมูลตัวอย่าง"). Turn this off once the history is real.
    |
    */

    'sample_price_history' => (bool) env('SHOP_SAMPLE_PRICE_HISTORY', true),

    /*
    |--------------------------------------------------------------------------
    | Stock lock
    |--------------------------------------------------------------------------
    |
    | How long adding a card to the cart holds its stock for that cart. The reservation row, the cart's countdown
    | and the Thai copy all read this one number.
    |
    */

    'reservation_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    |
    | `payment_window_minutes` is how long an order waits for money before it is cancelled and its stock returned;
    | `qr_minutes` how long one PromptPay QR is valid inside that window. PromptPay and the bank account are the
    | shop's own: a method whose details are empty is not offered at checkout, and nothing here is ever printed from
    | a template literal.
    |
    */

    'payment_window_minutes' => 30,

    'qr_minutes' => 15,

    'promptpay_id' => env('SHOP_PROMPTPAY_ID'),

    'bank' => [
        'name' => env('SHOP_BANK_NAME'),
        'account_name' => env('SHOP_BANK_ACCOUNT_NAME'),
        'account_number' => env('SHOP_BANK_ACCOUNT_NUMBER'),
    ],

    'slip_max_kb' => 5120,

    /*
    |--------------------------------------------------------------------------
    | LINE Official Account
    |--------------------------------------------------------------------------
    |
    | The add-friend link of the shop's LINE Official Account. A customer must add it as a friend before LINE lets the
    | shop push messages to them, so the account page links to it. Empty: no link is shown.
    |
    */

    'line_oa_url' => env('SHOP_LINE_OA_URL'),

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    |
    | The application stores UTC; dates a customer reads (the order timeline, "paid at") are shown in the shop's own.
    |
    */

    'timezone' => env('SHOP_TIMEZONE', 'Asia/Bangkok'),

];

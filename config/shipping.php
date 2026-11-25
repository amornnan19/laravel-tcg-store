<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Couriers
    |--------------------------------------------------------------------------
    |
    | Flat rates in satang, keyed by the Courier enum value. `days_th` is a translation key, `tracking_url` the
    | courier's public tracking page with `{number}` standing for the parcel number. The defaults are the design's.
    |
    */

    'couriers' => [
        'flash' => [
            'rate_satang' => (int) env('SHIPPING_FLASH_SATANG', 4000),
            'days_th' => 'storefront.checkout.days_1_2',
            'tracking_url' => 'https://www.flashexpress.co.th/fle/tracking?se={number}',
        ],
        'kerry' => [
            'rate_satang' => (int) env('SHIPPING_KERRY_SATANG', 5000),
            'days_th' => 'storefront.checkout.days_1_2',
            'tracking_url' => 'https://th.kerryexpress.com/th/track/?track={number}',
        ],
        'jt' => [
            'rate_satang' => (int) env('SHIPPING_JT_SATANG', 4000),
            'days_th' => 'storefront.checkout.days_2',
            'tracking_url' => 'https://www.jtexpress.co.th/service/track?bills={number}',
        ],
        'thaipost' => [
            'rate_satang' => (int) env('SHIPPING_THAIPOST_SATANG', 3500),
            'days_th' => 'storefront.checkout.days_2_3',
            'tracking_url' => 'https://track.thailandpost.co.th/?trackNumber={number}',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Protective packing
    |--------------------------------------------------------------------------
    |
    | The optional packing line, and the unit price from which checkout turns it on by default.
    |
    */

    'protection_satang' => (int) env('SHIPPING_PROTECTION_SATANG', 3000),

    'protection_threshold_satang' => (int) env('SHIPPING_PROTECTION_THRESHOLD_SATANG', 50000),

    /*
    |--------------------------------------------------------------------------
    | Graded cards
    |--------------------------------------------------------------------------
    |
    | A graded card always ships in a hard case with insured parcel cover. This is the price of that, per graded item
    | in an order, added to the total and shown as its own line.
    |
    */

    'graded_insurance_satang' => (int) env('SHIPPING_GRADED_INSURANCE_SATANG', 20000),

];

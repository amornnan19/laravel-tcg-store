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

];

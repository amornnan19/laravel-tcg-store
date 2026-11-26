<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credit bonus
    |--------------------------------------------------------------------------
    |
    | How much more store credit pays than cash, in basis points of the cash price (1500 is 15%). It fills the credit
    | price when staff leave it blank and drives the "credit = cash + 15%" bulk action.
    |
    */

    'credit_bonus_bp' => (int) env('BUYLIST_CREDIT_BONUS_BP', 1500),

    /*
    |--------------------------------------------------------------------------
    | Quote
    |--------------------------------------------------------------------------
    |
    | How many days a submitted list's price is honoured, counted from the day it is sent.
    |
    */

    'quote_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Receiving address
    |--------------------------------------------------------------------------
    |
    | Where customers post their cards: the shop's own, possibly over several lines. Left empty, the page says the shop
    | will send the address by LINE or email; nothing is ever printed from a template literal.
    |
    */

    'receiving_address' => env('SHOP_BUYLIST_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'max_lines' => 100,

    'max_qty_per_line' => 50,

];

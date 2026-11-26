<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * What the customer saw is not what the shop would quote now, found under the price rows' lock: a price moved, a
 * printing stopped being bought, or the shop stopped buying it. Nothing is written; the form shows the list again.
 */
class BuylistQuoteChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The buylist quote changed since the customer saw it.');
    }
}

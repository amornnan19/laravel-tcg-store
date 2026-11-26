<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A buy price edit would leave a row with no cash price, or with a credit price below its cash price (credit is what
 * the shop pays more in, so it can never be less). Nothing is written; `$row` names the offending row for staff.
 */
class BuylistPriceInvalid extends RuntimeException
{
    public function __construct(public readonly string $row)
    {
        parent::__construct(__('admin.buylist.validation.credit_below_cash').' ('.$row.')');
    }
}

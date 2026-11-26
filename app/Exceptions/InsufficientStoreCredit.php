<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A ledger write would take a customer's store credit below zero. Read under the user's row lock, so it is the truth.
 */
class InsufficientStoreCredit extends RuntimeException
{
    public function __construct(public readonly int $balanceSatang, public readonly int $deltaSatang)
    {
        parent::__construct("Store credit of {$balanceSatang} satang cannot take a change of {$deltaSatang}.");
    }
}

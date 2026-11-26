<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A line asks for more than the weekly quota has left, found under the price row's lock. The action never caps
 * quietly: the form capped what it showed, so this means the quota was used up since.
 */
class BuylistQuotaFull extends RuntimeException
{
    public function __construct(public readonly string $cardName, public readonly int $remaining)
    {
        parent::__construct("The weekly buylist quota for {$cardName} has {$remaining} left.");
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A reservation asked for more than the shop can still give. Callers catch it and tell the customer how many are left.
 */
class InsufficientStock extends RuntimeException
{
    /**
     * @param  int  $available  How many can still be reserved right now, never below zero.
     */
    public function __construct(public readonly int $available)
    {
        parent::__construct("Only {$available} left to reserve.");
    }
}

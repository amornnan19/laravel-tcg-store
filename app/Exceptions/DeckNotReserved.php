<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Adding a deck list to the cart failed, and nothing was held: `$variantIds` are the variants that could not be
 * reserved at the quantity asked (sold out since the list was checked, no longer for sale, or not a valid pick).
 */
class DeckNotReserved extends RuntimeException
{
    /**
     * @param  list<int>  $variantIds
     */
    public function __construct(public readonly array $variantIds)
    {
        parent::__construct('Deck not reserved: variants '.implode(', ', $variantIds).'.');
    }
}

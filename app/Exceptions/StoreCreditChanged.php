<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The store credit a checkout would spend, read under the user's row lock, is not the amount the customer saw: the
 * balance moved between the page rendering and the confirm. Nothing is written; the form shows the new figures.
 */
class StoreCreditChanged extends RuntimeException
{
    public function __construct(public readonly int $shownSatang, public readonly int $actualSatang)
    {
        parent::__construct("Store credit would be {$actualSatang} satang, the customer saw {$shownSatang}.");
    }
}

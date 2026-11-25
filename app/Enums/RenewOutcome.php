<?php

namespace App\Enums;

/**
 * What re-locking an expired cart line came to.
 */
enum RenewOutcome: string
{
    /** The line holds stock again, with its quantity and price possibly adjusted. */
    case Renewed = 'renewed';

    /** Nothing was left to hold; a substitute of the same printing may be on offer. */
    case Taken = 'taken';

    /** The item can no longer be bought at all. */
    case Gone = 'gone';
}

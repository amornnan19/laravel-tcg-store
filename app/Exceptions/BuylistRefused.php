<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A buylist action found, under the submission's lock, that it cannot do what was asked: the status is not the one the
 * action belongs to, an assessment breaks a rule, or the page was stale. `$reason` is the suffix of an
 * `admin.buylist.refused.*` line staff are shown.
 */
class BuylistRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Buylist action refused: {$reason}.");
    }

    public function message(): string
    {
        return __('admin.buylist.refused.'.$this->reason);
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A pre-order could not be placed, found under the pre-order's lock. `$reason` is `closed`, `not_yet`, `released`,
 * `full` (not enough places left), `limit` (the account would pass its per-account limit) or `unavailable` (the product
 * is no longer shown); the suffix of a `storefront.preorder.refused.*` line. `$available` is how many the customer could
 * still have, for `full` and `limit`.
 */
class PreorderRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $available = 0)
    {
        parent::__construct("Pre-order refused: {$reason}.");
    }

    public function message(): string
    {
        return __('storefront.preorder.refused.'.self::key($this->reason, $this->available), ['n' => $this->available]);
    }

    /**
     * The `storefront.preorder.refused.*` line for a reason: `limit` with nothing left is "limit reached", and `full`
     * with some places left is "only n left", so the customer is told what they can still do.
     */
    public static function key(string $reason, int $available): string
    {
        return match (true) {
            $reason === 'limit' && $available < 1 => 'limit_reached',
            $reason === 'full' && $available > 0 => 'short',
            default => $reason,
        };
    }
}

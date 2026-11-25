<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment action found, under the order's lock, that it can no longer do what the customer asked: the order is
 * closed, the payment is not in the state the button belonged to, or the payment window has passed. `$reason` is the
 * suffix of a `storefront.payment.refused.*` line the panel shows.
 */
class PaymentRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Payment action refused: {$reason}.");
    }

    public function message(): string
    {
        return __('storefront.payment.refused.'.$this->reason);
    }
}

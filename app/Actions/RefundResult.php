<?php

namespace App\Actions;

use App\Models\Order;

/**
 * What a refund did: the order, and the money staff must return, which is what had been received when it was refunded.
 */
final readonly class RefundResult
{
    public function __construct(public Order $order, public int $refundedSatang) {}
}

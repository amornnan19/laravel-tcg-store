<?php

namespace App\Actions;

use App\Models\Order;

/**
 * What a refund did: the order, the money staff must return (what had been received when it was refunded), and the
 * store credit that went back to the customer's account instead.
 */
final readonly class RefundResult
{
    public function __construct(public Order $order, public int $refundedSatang, public int $creditReturnedSatang = 0) {}
}

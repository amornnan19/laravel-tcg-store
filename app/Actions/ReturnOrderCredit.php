<?php

namespace App\Actions;

use App\Enums\StoreCreditReason;
use App\Models\Order;
use App\Models\User;

/**
 * Gives the store credit an order spent back to its customer, once. The caller holds the order's row lock (and any
 * purchasable and pre-order locks) and calls this before it touches the order's payments: the user row is locked here,
 * and users come before payments in the lock order. `credit_returned_at` is what makes a second call do nothing.
 */
class ReturnOrderCredit
{
    /**
     * @param  Order  $order  The order as read under its row lock.
     * @return int The satang returned now; 0 when the order spent none or already returned it.
     */
    public function handle(Order $order): int
    {
        if ($order->credit_used_satang <= 0 || $order->credit_returned_at !== null || $order->user_id === null) {
            return 0;
        }

        $user = User::query()->findOrFail($order->user_id);

        app(RecordStoreCredit::class)->handle($user, $order->credit_used_satang, StoreCreditReason::Refund, $order);

        $order->update(['credit_returned_at' => now()]);

        return $order->credit_used_satang;
    }
}

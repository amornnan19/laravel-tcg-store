<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Notifications\PreorderBalanceDue;
use App\Support\OrderRecipient;
use App\Support\PaymentQr;
use Illuminate\Support\Facades\DB;

/**
 * Asks one deposit pre-order for its balance: a pending payment for `balance_due_satang` due at
 * `Preorder::balanceDueAt()`, and a message with the link back to the same order. Claimed under the order's row lock by
 * stamping `balance_requested_at`, so the daily job and a deposit confirmed inside the seven days can both call it and the
 * customer is still asked once.
 */
class RequestPreorderBalance
{
    /**
     * @return Order|null The order that was asked, or null when it was not waiting for a balance request (already asked,
     *                    paid, cancelled, or nothing is owed).
     */
    public function handle(Order $listed): ?Order
    {
        $order = DB::transaction(function () use ($listed): ?Order {
            // The caller's copy may be stale: the same conditions are checked again under the order's row lock.
            $order = Order::query()->lockForUpdate()->findOrFail($listed->getKey());

            if ($order->status !== OrderStatus::AwaitingBalance || $order->balance_requested_at !== null || $order->balance_due_satang <= 0) {
                return null;
            }

            $due = $order->preorder->balanceDueAt();

            $order->update(['payment_due_at' => $due, 'balance_requested_at' => now()]);

            $method = $order->payments()->latest('id')->firstOrFail()->method;

            $order->payments()->create([
                'method' => $method,
                'status' => PaymentStatus::Pending,
                'amount_satang' => $order->balance_due_satang,
                ...PaymentQr::attributes($method, $order->balance_due_satang, $due),
            ]);

            return $order;
        });

        if ($order !== null) {
            OrderRecipient::notify($order, new PreorderBalanceDue($order));
        }

        return $order;
    }
}

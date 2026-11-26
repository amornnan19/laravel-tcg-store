<?php

namespace App\Actions\Concerns;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRefused;
use App\Models\Order;
use App\Models\Payment;

/**
 * The first steps of every customer payment action: take the order row lock before anything else, then re-check, on
 * what the lock now shows, that the order still waits for money and the payment is still in the state the action
 * expects. `CancelOrder`'s guard depends on every writer taking this same lock first.
 */
trait LocksOrderForPayment
{
    /**
     * Call as the first statement of the transaction.
     *
     * @throws PaymentRefused
     */
    private function lockOpenOrder(Payment $payment): Order
    {
        $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);

        if ($order->status === OrderStatus::Cancelled) {
            throw new PaymentRefused('order_cancelled');
        }

        // A pre-order's balance is paid on the same panel once it was requested.
        if (! $order->isWaitingForMoney()) {
            throw new PaymentRefused('order_closed');
        }

        if ($order->payment_due_at->lte(now())) {
            throw new PaymentRefused($order->status === OrderStatus::AwaitingBalance ? 'balance_window_closed' : 'window_closed');
        }

        return $order;
    }

    /**
     * The payment as it is now, which must be in `$expected`.
     *
     * @throws PaymentRefused
     */
    private function lockPayment(Payment $payment, PaymentStatus $expected): Payment
    {
        $fresh = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

        if ($fresh->status !== $expected) {
            throw new PaymentRefused('changed');
        }

        return $fresh;
    }

    /**
     * Only the order's newest payment can be acted on: an older one has already been replaced.
     *
     * @throws PaymentRefused
     */
    private function requireLatest(Order $order, Payment $payment): void
    {
        if ((int) $order->payments()->max('id') !== $payment->getKey()) {
            throw new PaymentRefused('changed');
        }
    }
}

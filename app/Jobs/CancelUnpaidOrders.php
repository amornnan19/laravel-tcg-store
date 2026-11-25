<?php

namespace App\Jobs;

use App\Actions\CancelOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cancels the orders that ran out their payment window and returns their stock. An order with a slip waiting for
 * staff, or with money already in (a short slip, a partial confirmation), is left alone: the customer paid, and the
 * shop decides.
 */
class CancelUnpaidOrders implements ShouldQueue
{
    use Queueable;

    public function handle(CancelOrder $cancelOrder): void
    {
        $isOverdue = fn (Order $order): bool => $order->status === OrderStatus::AwaitingPayment
            && $order->payment_due_at->lte(now())
            && ! $order->payments()->where('status', PaymentStatus::Submitted->value)->exists()
            && $order->receivedSatang() === 0;

        Order::query()
            ->where('status', OrderStatus::AwaitingPayment->value)
            ->where('payment_due_at', '<=', now())
            ->whereDoesntHave('payments', fn ($payments) => $payments->where('status', PaymentStatus::Submitted->value))
            ->lazyById()
            ->each(function (Order $order) use ($cancelOrder, $isOverdue): void {
                try {
                    // The list above may be stale by now: the same condition is checked again under the order's row lock.
                    $cancelOrder->handle($order, __('storefront.order.cancel_unpaid'), $isOverdue);
                } catch (IllegalOrderTransition) {
                    // Cancelled or paid by someone else in the meantime.
                }
            });
    }
}

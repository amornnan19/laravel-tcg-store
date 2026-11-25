<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Exceptions\PaymentRefused;
use App\Models\Order;
use App\Models\Variant;
use App\Notifications\OrderCancelled;
use App\Support\OrderRecipient;
use App\Support\PurchasableTypes;
use App\Support\VariantAlerts;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an order that is waiting for money or has just been paid, and gives its stock back. By the time an order
 * exists its hold is already consumed and its quantity taken out of the variant, so the quantity goes back here
 * explicitly, to the variants that still exist.
 */
class CancelOrder
{
    /**
     * `$guard` is the caller's own condition for cancelling, evaluated here on the order as it is **under its row lock**:
     * a caller that filtered orders before calling has checked a state that may already have changed. When the guard
     * returns false nothing happens and null is returned.
     *
     * Every other writer that moves an order or its payments (SubmitSlip in Step B, RejectPayment and the other status
     * actions in Step C) must take the same order row lock first, or this guard cannot see what they write.
     *
     * @param  (Closure(Order): bool)|null  $guard
     *
     * @throws IllegalOrderTransition when the order is not `awaiting_payment` or `paid`.
     * @throws PaymentRefused when a slip is waiting for staff (`slip_submitted`): decide the slip first.
     */
    public function handle(Order $order, string $reason, ?Closure $guard = null): ?Order
    {
        // The rows to lock are read before the transaction opens so its first statement is the locking read
        // (project rule); the order row is locked right after and its status re-checked. Grouped by type: ids of
        // different types may coincide.
        $refs = PurchasableTypes::referencedBy($order->items()->get());
        $variantIds = $refs[(new Variant)->getMorphClass()] ?? collect();

        $cancelled = DB::transaction(function () use ($order, $reason, $guard, $refs): ?Order {
            $purchasables = PurchasableTypes::lockMany($refs);

            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($guard !== null && ! $guard($locked)) {
                return null;
            }

            if (! in_array($locked->status, [OrderStatus::AwaitingPayment, OrderStatus::Paid], true)) {
                throw new IllegalOrderTransition("An order that is {$locked->status->value} cannot be cancelled.");
            }

            // A slip waiting for staff may be money in: it is decided first, never swept away by a cancel. A locking
            // read: inside the panel's outer transaction a plain read would see the snapshot from before the lock.
            if ($locked->payments()->where('status', PaymentStatus::Submitted->value)->lockForUpdate()->exists()) {
                throw new PaymentRefused('slip_submitted');
            }

            $locked->payments()
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Rejected->value, 'rejected_reason' => $reason, 'updated_at' => now()]);

            $locked->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason]);

            // After the status moved: a one-of-a-kind item checks that no other live order holds its sale.
            foreach ($locked->items()->get() as $item) {
                $purchasable = $purchasables[$item->purchasable_type][$item->purchasable_id] ?? null;

                // A row that was deleted, or a line of an unknown type, has nothing to return.
                $purchasable?->onReturned($item->qty);
            }

            return $locked;
        });

        if ($cancelled === null) {
            return null;
        }

        VariantAlerts::dispatchFor($variantIds);

        OrderRecipient::notify($cancelled, new OrderCancelled($cancelled));

        return $cancelled;
    }
}

<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Exceptions\PaymentRefused;
use App\Models\Order;
use App\Models\Variant;
use App\Notifications\OrderCancelled;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
        // The variants to lock are read before the transaction opens so its first statement is the locking read
        // (project rule); the order row is locked right after and its status re-checked.
        $variantIds = $order->items()
            ->whereNotNull('purchasable_id')
            ->where('purchasable_type', (new Variant)->getMorphClass())
            ->orderBy('purchasable_id')
            ->pluck('purchasable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $cancelled = DB::transaction(function () use ($order, $reason, $guard, $variantIds): ?Order {
            $variants = Variant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

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

            foreach ($locked->items()->get() as $item) {
                $variant = $variants->get($item->purchasable_id);

                if ($variant !== null && $item->purchasable_type === $variant->getMorphClass()) {
                    $variant->qty += $item->qty;
                    $variant->save();
                }
            }

            $locked->payments()
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Rejected->value, 'rejected_reason' => $reason, 'updated_at' => now()]);

            $locked->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason]);

            return $locked;
        });

        if ($cancelled === null) {
            return null;
        }

        Notification::route('mail', $cancelled->contact_email)->notify(new OrderCancelled($cancelled));

        return $cancelled;
    }
}

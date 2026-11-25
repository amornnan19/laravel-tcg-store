<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Models\Order;
use App\Models\Variant;
use App\Notifications\OrderRefunded;
use App\Support\OrderRecipient;
use App\Support\PurchasableTypes;
use App\Support\VariantAlerts;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Refunds a paid order that has not shipped: its confirmed payments become `refunded`, its quantity goes back to the
 * variants that still exist and `refunded_at` is stamped. The amount to return is `Order::receivedSatang()` as it stood
 * before the payments moved. Lock order: variants, order, payments, as `CancelOrder` does.
 */
class RefundOrder
{
    /**
     * @throws IllegalOrderTransition when the order is not `paid` or `packing`.
     * @throws InvalidArgumentException when the reason is blank.
     */
    public function handle(Order $order, string $reason): RefundResult
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to refund an order.');
        }

        $refs = PurchasableTypes::referencedBy($order->items()->get());
        $variantIds = $refs[(new Variant)->getMorphClass()] ?? collect();

        $result = DB::transaction(function () use ($order, $reason, $refs): RefundResult {
            $purchasables = PurchasableTypes::lockMany($refs);

            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::Packing], true)) {
                throw new IllegalOrderTransition("An order that is {$locked->status->value} cannot be refunded.");
            }

            $refundedSatang = $locked->receivedSatang();

            $locked->payments()
                ->where('status', PaymentStatus::Confirmed->value)
                ->update(['status' => PaymentStatus::Refunded->value, 'updated_at' => now()]);

            $locked->update([
                'status' => OrderStatus::Refunded,
                'refunded_at' => now(),
                'refund_reason' => $reason,
                'refunded_satang' => $locked->refunded_satang + $refundedSatang,
            ]);

            // After the status moved: a one-of-a-kind item checks that no other live order holds its sale.
            foreach ($locked->items()->get() as $item) {
                $purchasable = $purchasables[$item->purchasable_type][$item->purchasable_id] ?? null;

                // A row that was deleted, or a line of an unknown type, has nothing to return.
                $purchasable?->onReturned($item->qty);
            }

            return new RefundResult($locked, $refundedSatang);
        });

        VariantAlerts::dispatchFor($variantIds);

        OrderRecipient::notify($result->order, new OrderRefunded($result->order, $result->refundedSatang));

        return $result;
    }
}

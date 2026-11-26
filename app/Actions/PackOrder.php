<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Exceptions\PreorderNotReleased;
use App\Models\Order;
use App\Notifications\OrderPacked;
use App\Support\OrderRecipient;
use Illuminate\Support\Facades\DB;

/**
 * `paid` to `packing`. The status is re-read under the order's row lock; the customer is told afterwards. A pre-order
 * is not packed before its release date.
 */
class PackOrder
{
    /**
     * @throws IllegalOrderTransition
     * @throws PreorderNotReleased when the order is a pre-order and its release date (shop time) has not begun.
     */
    public function handle(Order $order): Order
    {
        $order = DB::transaction(function () use ($order): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== OrderStatus::Paid) {
                throw new IllegalOrderTransition("An order that is {$locked->status->value} cannot be packed.");
            }

            if ($locked->isPreorder() && $locked->preorder !== null && $locked->preorder->releaseAt()->gt(now())) {
                throw new PreorderNotReleased("A pre-order cannot be packed before {$locked->preorder->release_on->format('Y-m-d')}.");
            }

            $locked->update(['status' => OrderStatus::Packing, 'packed_at' => now()]);

            return $locked;
        });

        OrderRecipient::notify($order, new OrderPacked($order));

        return $order;
    }
}

<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Models\Order;
use App\Notifications\OrderDelivered;
use App\Support\OrderRecipient;
use Illuminate\Support\Facades\DB;

/**
 * `shipped` to `delivered`. The status is re-read under the order's row lock; the customer is told afterwards.
 */
class DeliverOrder
{
    /**
     * @throws IllegalOrderTransition
     */
    public function handle(Order $order): Order
    {
        $order = DB::transaction(function () use ($order): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== OrderStatus::Shipped) {
                throw new IllegalOrderTransition("An order that is {$locked->status->value} cannot be marked delivered.");
            }

            $locked->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

            return $locked;
        });

        OrderRecipient::notify($order, new OrderDelivered($order));

        return $order;
    }
}

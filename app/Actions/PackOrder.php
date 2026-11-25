<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Models\Order;
use App\Notifications\OrderPacked;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * `paid` to `packing`. The status is re-read under the order's row lock; the customer is told afterwards.
 */
class PackOrder
{
    /**
     * @throws IllegalOrderTransition
     */
    public function handle(Order $order): Order
    {
        $order = DB::transaction(function () use ($order): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== OrderStatus::Paid) {
                throw new IllegalOrderTransition("An order that is {$locked->status->value} cannot be packed.");
            }

            $locked->update(['status' => OrderStatus::Packing, 'packed_at' => now()]);

            return $locked;
        });

        Notification::route('mail', $order->contact_email)->notify(new OrderPacked($order));

        return $order;
    }
}

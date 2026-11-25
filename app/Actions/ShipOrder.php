<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransition;
use App\Models\Order;
use App\Notifications\OrderShipped;
use App\Support\OrderRecipient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * `packing` to `shipped`: writes the tracking number, moves the status, stamps `shipped_at` in one transaction and then
 * tells the customer, tracking link included.
 */
class ShipOrder
{
    /**
     * @throws IllegalOrderTransition
     * @throws InvalidArgumentException when the tracking number is blank.
     */
    public function handle(Order $order, string $tracking): Order
    {
        $tracking = trim($tracking);

        if ($tracking === '') {
            throw new InvalidArgumentException('A tracking number is required to ship an order.');
        }

        $shipped = DB::transaction(function () use ($order, $tracking): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== OrderStatus::Packing) {
                throw new IllegalOrderTransition("An order that is {$locked->status->value} cannot be shipped.");
            }

            $locked->update([
                'status' => OrderStatus::Shipped,
                'tracking_number' => $tracking,
                'shipped_at' => now(),
            ]);

            return $locked;
        });

        OrderRecipient::notify($shipped, new OrderShipped($shipped));

        return $shipped;
    }
}

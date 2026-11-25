<?php

namespace Database\Seeders;

use App\Enums\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Variant;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * A few orders in different statuses, so the admin desk has something to show. Each one snapshots a seeded variant
     * through the factory. An order that is still open (awaiting payment, paid, packing) took its stock out of the
     * variant as PlaceOrder would, so cancelling it gives back exactly what it took; the others took nothing, and
     * cannot reach an action that returns stock. No slip file exists.
     */
    public function run(): void
    {
        $variants = Variant::query()
            ->purchasable()
            ->with(['printing.card', 'printing.set'])
            ->where('qty', '>=', 2)
            ->orderBy('id')
            ->limit(7)
            ->get();

        if ($variants->count() < 7) {
            return;
        }

        // [factory state, courier, protection on, a slip waiting]
        $orders = [
            ['awaitingPayment', Courier::Kerry, true, false],
            ['awaitingPayment', Courier::Flash, false, true],
            ['paid', Courier::Flash, false, false],
            ['packing', Courier::Jt, true, false],
            ['shipped', Courier::Thaipost, false, false],
            ['delivered', Courier::Flash, false, false],
            ['cancelled', Courier::Kerry, false, false],
        ];

        foreach ($orders as $index => [$state, $courier, $protected, $slipWaiting]) {
            $variant = $variants[$index];
            $printing = $variant->printing;
            $qty = $index % 3 === 0 ? 2 : 1;

            $factory = Order::factory()->{$state}()->has(OrderItem::factory()->state([
                'purchasable_type' => $variant->getMorphClass(),
                'purchasable_id' => $variant->getKey(),
                'card_name' => $printing->display_name,
                'set_code' => $printing->set->code,
                'number' => $printing->number,
                'language' => $printing->language->value,
                'condition' => $variant->condition->value,
                'finish' => $variant->finish->value,
                'unit_price_satang' => $variant->price_satang,
                'qty' => $qty,
                'line_total_satang' => $variant->price_satang * $qty,
            ]), 'items');

            // Never below zero: the variants above all have at least the two an order may take.
            if (in_array($state, ['awaitingPayment', 'paid', 'packing'], true)) {
                $variant->qty -= $qty;
                $variant->save();
            }

            if ($slipWaiting) {
                $factory = $factory->submitted();
            }

            $factory->create([
                'courier' => $courier,
                'shipping_satang' => $courier->rateSatang(),
                'protection_satang' => $protected ? (int) config('shipping.protection_satang') : 0,
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Variant;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class OrderSeeder extends Seeder
{
    use SeedsDemoData;

    /**
     * A few orders in different statuses, so the admin desk has something to show. Each one snapshots a seeded variant
     * through the factory. An order that is still open (awaiting payment, paid, packing) took its stock out of the
     * variant as PlaceOrder would, so cancelling it gives back exactly what it took; the others took nothing, and
     * cannot reach an action that returns stock. Outside a local environment only orders no scheduled job acts on are
     * seeded (paid, packing, shipped, delivered, cancelled, refunded: none awaiting payment, none with a slip waiting), and
     * their contact addresses are on the reserved `example.invalid` domain, so nothing can mail a real person. Seeded once:
     * it does nothing when any order exists. A waiting slip is a small placeholder PNG drawn here, never a real photo.
     */
    public function run(): void
    {
        if (! $this->shouldSeedDemoData() || Order::query()->exists()) {
            return;
        }

        $isLocal = app()->isLocal();

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

        if (! $isLocal) {
            $orders = array_values(array_filter($orders, fn (array $order): bool => $order[0] !== 'awaitingPayment'));
            $orders[] = ['refunded', Courier::Flash, false, false];
        }

        foreach ($orders as $index => [$state, $courier, $protected, $slipWaiting]) {
            $variant = $variants[$index];
            $qty = $index % 3 === 0 ? 2 : 1;

            $base = $state === 'refunded'
                ? Order::factory()->paid()->state(['status' => OrderStatus::Refunded, 'refunded_at' => now()])
                : Order::factory()->{$state}();

            $factory = $base->has(OrderItem::factory()->state([
                'purchasable_type' => $variant->getMorphClass(),
                'purchasable_id' => $variant->getKey(),
                ...$variant->snapshot(),
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

            $order = $factory->create([
                ...($isLocal ? [] : ['contact_email' => 'demo-order-'.($index + 1).'@example.invalid']),
                'courier' => $courier,
                'shipping_satang' => $courier->rateSatang(),
                'protection_satang' => $protected ? (int) config('shipping.protection_satang') : 0,
            ]);

            if ($slipWaiting) {
                $this->storePlaceholderSlip($order);
            }
        }
    }

    /**
     * Draws a small grey-and-amber PNG on the private disk and points the order's submitted payment at it, so the
     * payment desk shows an image. Skipped (the factory path stays, the desk shows its missing-file note) without GD.
     */
    private function storePlaceholderSlip(Order $order): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }

        $image = imagecreatetruecolor(240, 360);
        imagefill($image, 0, 0, imagecolorallocate($image, 238, 238, 240));
        imagefilledrectangle($image, 0, 0, 239, 60, imagecolorallocate($image, 231, 182, 78));

        foreach ([100, 130, 160, 190, 220] as $y) {
            imagefilledrectangle($image, 24, $y, 24 + 120 + ($y % 60), $y + 8, imagecolorallocate($image, 190, 192, 198));
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $path = 'slips/'.$order->number.'/slip.png';
        Storage::disk('local')->put($path, $png);

        $order->payments()->where('status', PaymentStatus::Submitted->value)->update(['slip_path' => $path]);
    }
}

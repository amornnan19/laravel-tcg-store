<?php

namespace Database\Seeders;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreorderPlan;
use App\Enums\SealedKind;
use App\Models\CardSet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Preorder;
use App\Models\SealedProduct;
use Illuminate\Database\Seeder;

class PreorderSeeder extends Seeder
{
    /**
     * Two open pre-orders, local only: a booster box releasing in 30 days and one releasing in 5 days. The 5-day one
     * already has a deposit order waiting for its balance, so the daily balance job has something to ask for. Prices are
     * plausible demo values, never a real quote; the products have no image (the storefront draws a neutral block).
     */
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $sets = CardSet::query()->searchVisible()->orderBy('id')->limit(2)->get();

        if ($sets->isEmpty()) {
            return;
        }

        // [release in days, cancel until in days, name, deposit baht, full baht, quota, taken]
        $specs = [
            [30, 14, 'Pre-order Booster Box', 500, 2800, 40, 0],
            [5, 2, 'Pre-order Booster Box (Wave 2)', 600, 3200, 20, 1],
        ];

        foreach ($specs as $index => [$release, $cancel, $name, $deposit, $full, $quota, $taken]) {
            $set = $sets[$index] ?? $sets->first();

            $product = SealedProduct::query()->create([
                'game_id' => $set->game_id,
                'set_id' => $set->id,
                'kind' => SealedKind::BoosterBox,
                'name_en' => $set->name_en.' '.$name,
                'sku' => 'DEMO-PREORDER-'.($index + 1),
                'price_satang' => $full * 100,
                'qty' => 0,
                'description_th' => 'สินค้าตัวอย่างสำหรับทดสอบพรีออเดอร์',
                'is_active' => true,
                'is_preorder' => true,
            ]);

            $preorder = Preorder::query()->create([
                'sealed_product_id' => $product->id,
                'is_open' => true,
                'release_on' => now()->addDays($release)->toDateString(),
                'cancel_until' => now()->addDays($cancel)->toDateString(),
                'deposit_satang' => $deposit * 100,
                'full_satang' => $full * 100,
                'full_discount_bp' => 200,
                'quota' => $quota,
                'reserved_count' => $taken,
                'per_account_limit' => 2,
            ]);

            if ($taken > 0) {
                $this->depositOrder($preorder, $product, $taken);
            }
        }
    }

    /**
     * A deposit order whose deposit is confirmed and whose balance has not been asked for, as PlacePreorder and
     * ConfirmPayment leave it. It takes no stock, so cancelling it only returns its places.
     */
    private function depositOrder(Preorder $preorder, SealedProduct $product, int $qty): void
    {
        $subtotal = $preorder->full_satang * $qty;
        $deposit = $preorder->deposit_satang * $qty;
        $shipping = 4000;

        Order::factory()
            ->has(OrderItem::factory()->state([
                'purchasable_type' => $product->getMorphClass(),
                'purchasable_id' => $product->getKey(),
                ...$product->snapshot(),
                'unit_price_satang' => $preorder->full_satang,
                'qty' => $qty,
                'line_total_satang' => $subtotal,
            ]), 'items')
            ->has(Payment::factory()->state(['status' => PaymentStatus::Confirmed, 'amount_satang' => $deposit, 'confirmed_at' => now()]), 'payments')
            ->create([
                'status' => OrderStatus::AwaitingBalance,
                'kind' => OrderKind::Preorder,
                'preorder_id' => $preorder->id,
                'preorder_plan' => PreorderPlan::Deposit,
                'cancel_until' => $preorder->cancel_until,
                'deposit_satang' => $deposit,
                'balance_due_satang' => $subtotal + $shipping - $deposit,
                'shipping_satang' => $shipping,
                'payment_due_at' => now()->addMinutes(30),
            ]);
    }
}

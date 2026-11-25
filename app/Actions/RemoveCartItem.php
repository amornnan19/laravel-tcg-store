<?php

namespace App\Actions;

use App\Models\CartItem;
use App\Models\StockReservation;
use App\Models\Variant;
use App\Support\PurchasableTypes;
use App\Support\VariantAlerts;
use Illuminate\Support\Facades\DB;

/**
 * Takes a line out of its cart and gives its stock back.
 */
class RemoveCartItem
{
    public function handle(CartItem $item): void
    {
        DB::transaction(function () use ($item): void {
            // Lock the purchasable first (project rule), so the release below cannot interleave with another cart's
            // hold on a one-of-a-kind item. A row that no longer exists has nothing to release.
            $class = PurchasableTypes::classFor($item->purchasable_type);
            $purchasable = $class === null ? null : $class::lockForHold((int) $item->purchasable_id);

            StockReservation::query()
                ->where('cart_id', $item->cart_id)
                ->where('purchasable_type', $item->purchasable_type)
                ->where('purchasable_id', $item->purchasable_id)
                ->delete();

            $item->delete();

            $purchasable?->onReleased();
        });

        if ($item->purchasable_type === (new Variant)->getMorphClass()) {
            VariantAlerts::dispatchFor([$item->purchasable_id]);
        }
    }
}

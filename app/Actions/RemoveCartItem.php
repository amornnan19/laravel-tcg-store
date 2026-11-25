<?php

namespace App\Actions;

use App\Models\CartItem;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

/**
 * Takes a line out of its cart and gives its stock back.
 */
class RemoveCartItem
{
    public function handle(CartItem $item): void
    {
        DB::transaction(function () use ($item): void {
            StockReservation::query()
                ->where('cart_id', $item->cart_id)
                ->where('purchasable_type', $item->purchasable_type)
                ->where('purchasable_id', $item->purchasable_id)
                ->delete();

            $item->delete();
        });
    }
}

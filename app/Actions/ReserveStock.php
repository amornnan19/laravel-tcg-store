<?php

namespace App\Actions;

use App\Exceptions\InsufficientStock;
use App\Models\Cart;
use App\Models\StockReservation;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Holds more of a variant for a cart, or refuses with how many are left. The one place stock is locked.
 */
class ReserveStock
{
    /**
     * Add `$additional` to what the cart already holds of the variant and restart the lock time for the whole line.
     * A hold that has expired counts as zero. It does not write `cart_items`.
     *
     * @throws InsufficientStock when `$additional` is more than `qty` minus the live holds of every cart.
     */
    public function handle(Cart $cart, Variant $variant, int $additional): StockReservation
    {
        if ($additional < 1) {
            throw new InvalidArgumentException('A reservation adds at least one.');
        }

        return DB::transaction(function () use ($cart, $variant, $additional): StockReservation {
            // A no-op on SQLite, which serialises writers; a row lock on MySQL and Postgres.
            $locked = Variant::query()->lockForUpdate()->findOrFail($variant->getKey());

            $held = (int) StockReservation::query()
                ->live()
                ->where('purchasable_type', $locked->getMorphClass())
                ->where('purchasable_id', $locked->getKey())
                ->sum('qty');

            $available = max(0, $locked->qty - $held);

            if ($additional > $available) {
                throw new InsufficientStock($available);
            }

            $current = StockReservation::query()
                ->where('cart_id', $cart->getKey())
                ->where('purchasable_type', $locked->getMorphClass())
                ->where('purchasable_id', $locked->getKey())
                ->first();

            return StockReservation::query()->updateOrCreate(
                [
                    'cart_id' => $cart->getKey(),
                    'purchasable_type' => $locked->getMorphClass(),
                    'purchasable_id' => $locked->getKey(),
                ],
                [
                    'qty' => ($current?->isLive() ? $current->qty : 0) + $additional,
                    'expires_at' => now()->addMinutes(config('shop.reservation_minutes')),
                ],
            );
        });
    }
}

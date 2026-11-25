<?php

namespace App\Actions;

use App\Contracts\Purchasable;
use App\Exceptions\InsufficientStock;
use App\Models\Cart;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Holds more of a purchasable for a cart, or refuses with how many are left. The one place stock is locked.
 */
class ReserveStock
{
    /**
     * Add `$additional` to what the cart already holds of the purchasable and restart the lock time for the whole line.
     * A hold that has expired counts as zero. It does not write `cart_items`.
     *
     * @throws InsufficientStock when `$additional` is more than the purchasable can still give: its holdable quantity after the live
     *                           holds of every cart, and never above what one line may hold (a graded item: 1).
     */
    public function handle(Cart $cart, Model&Purchasable $purchasable, int $additional): StockReservation
    {
        if ($additional < 1) {
            throw new InvalidArgumentException('A reservation adds at least one.');
        }

        return DB::transaction(function () use ($cart, $purchasable, $additional): StockReservation {
            // The locking read comes first. A no-op on SQLite, which serialises writers; a row lock on MySQL and Postgres.
            $locked = $purchasable::lockForHold($purchasable->getKey())
                ?? throw (new ModelNotFoundException)->setModel($purchasable::class, [$purchasable->getKey()]);

            $current = StockReservation::query()
                ->where('cart_id', $cart->getKey())
                ->where('purchasable_type', $locked->getMorphClass())
                ->where('purchasable_id', $locked->getKey())
                ->first();

            $alreadyHeld = $current?->isLive() ? $current->qty : 0;

            $available = max(0, min($locked->holdableQty(), $locked->maxPerLine() - $alreadyHeld));

            if ($additional > $available) {
                throw new InsufficientStock($available);
            }

            // One upsert on the unique key, never a delete or update that may miss followed by an insert: on MySQL the
            // miss takes a gap lock and two carts adding different variants deadlock. A row the release job deleted
            // between the read above and this write is simply inserted again.
            $now = now();

            StockReservation::query()->upsert(
                [[
                    'cart_id' => $cart->getKey(),
                    'purchasable_type' => $locked->getMorphClass(),
                    'purchasable_id' => $locked->getKey(),
                    'qty' => $alreadyHeld + $additional,
                    'expires_at' => $now->copy()->addMinutes(config('shop.reservation_minutes')),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                uniqueBy: ['cart_id', 'purchasable_type', 'purchasable_id'],
                update: ['qty', 'expires_at', 'updated_at'],
            );

            $locked->onHeld();

            return StockReservation::query()
                ->where('cart_id', $cart->getKey())
                ->where('purchasable_type', $locked->getMorphClass())
                ->where('purchasable_id', $locked->getKey())
                ->firstOrFail();
        });
    }
}

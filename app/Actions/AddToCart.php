<?php

namespace App\Actions;

use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\StockReservation;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Puts copies of a variant in a cart and locks their stock for the configured lock time.
 */
class AddToCart
{
    public function __construct(private ReserveStock $reserveStock) {}

    /**
     * One line per variant: adding again sums the quantity. The line's price is the variant's price when the
     * line is created, and again when the line's hold had expired before this add; while a hold is live it never
     * changes, so a price edit cannot silently alter a held cart.
     *
     * @throws NotPurchasable when the variant has no price, no longer exists or its card is hidden.
     * @throws InsufficientStock when fewer than `$qty` can be reserved.
     */
    public function handle(Cart $cart, Variant $variant, int $qty): CartItem
    {
        if ($qty < 1) {
            throw new InvalidArgumentException('Add at least one.');
        }

        return DB::transaction(function () use ($cart, $variant, $qty): CartItem {
            // The locking read comes first: on MySQL's REPEATABLE READ any earlier plain read fixes the snapshot
            // before the lock is taken, and the reservation sum would miss holds committed while waiting.
            $current = Variant::query()->lockForUpdate()->find($variant->getKey());

            if ($current === null || ! $current->isPurchasable()) {
                throw new NotPurchasable;
            }

            $wasLive = StockReservation::query()
                ->live()
                ->where('cart_id', $cart->getKey())
                ->where('purchasable_type', $current->getMorphClass())
                ->where('purchasable_id', $current->getKey())
                ->exists();

            $this->reserveStock->handle($cart, $current, $qty);

            $item = CartItem::query()
                ->where('cart_id', $cart->getKey())
                ->where('purchasable_type', $current->getMorphClass())
                ->where('purchasable_id', $current->getKey())
                ->first();

            if ($item === null) {
                return CartItem::query()->create([
                    'cart_id' => $cart->getKey(),
                    'purchasable_type' => $current->getMorphClass(),
                    'purchasable_id' => $current->getKey(),
                    'qty' => $qty,
                    'unit_price_satang' => $current->price_satang,
                ]);
            }

            // A line whose hold had expired starts over, so its quantity matches the new reservation.
            $item->update($wasLive
                ? ['qty' => $item->qty + $qty]
                : ['qty' => $qty, 'unit_price_satang' => $current->price_satang]);

            return $item;
        });
    }
}

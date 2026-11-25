<?php

namespace App\Actions;

use App\Contracts\Purchasable;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Puts copies of a purchasable in a cart and locks their stock for the configured lock time.
 */
class AddToCart
{
    public function __construct(private ReserveStock $reserveStock) {}

    /**
     * One line per purchasable: adding again sums the quantity. The line's price is its price when the
     * line is created, and again when the line's hold had expired before this add; while a hold is live it never
     * changes, so a price edit cannot silently alter a held cart.
     *
     * @throws NotPurchasable when it has no price, no longer exists, is not for sale or its card is hidden.
     * @throws InsufficientStock when fewer than `$qty` can be reserved, or `$qty` is more than one line may hold.
     */
    public function handle(Cart $cart, Model&Purchasable $purchasable, int $qty): CartItem
    {
        if ($qty < 1) {
            throw new InvalidArgumentException('Add at least one.');
        }

        return DB::transaction(function () use ($cart, $purchasable, $qty): CartItem {
            // The locking read comes first: on MySQL's REPEATABLE READ any earlier plain read fixes the snapshot
            // before the lock is taken, and the reservation sum would miss holds committed while waiting.
            $current = $purchasable::lockForHold($purchasable->getKey());

            if ($current === null || ! $current->isPurchasable()) {
                throw new NotPurchasable;
            }

            // What ReserveStock actually wrote decides whether the line adds up or starts over: a hold that expired
            // between a separate liveness read and the write would otherwise leave the line and its hold apart.
            $reservation = $this->reserveStock->handle($cart, $current, $qty);
            $startedOver = $reservation->qty === $qty;

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
                    'unit_price_satang' => $current->unitPriceSatang(),
                ]);
            }

            // A line whose hold had expired starts over, so its quantity matches the new reservation.
            $item->update($startedOver
                ? ['qty' => $qty, 'unit_price_satang' => $current->unitPriceSatang()]
                : ['qty' => $reservation->qty]);

            return $item;
        });
    }
}

<?php

namespace App\Actions;

use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\CartItem;
use App\Models\Variant;
use App\Support\VariantAlerts;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Sets the quantity of a cart line whose hold is still live.
 */
class ChangeCartQuantity
{
    public function __construct(private ReserveStock $reserveStock) {}

    /**
     * Raising reserves the difference and restarts the lock time; lowering shrinks the line and its hold and keeps
     * the lock time. Removing a line is RemoveCartItem; an expired line goes through RenewCartItem.
     *
     * @throws InsufficientStock when the difference is more than can be reserved.
     * @throws NotPurchasable when raising a line whose item can no longer be bought.
     * @throws LogicException when the line's hold has expired.
     */
    public function handle(CartItem $item, int $qty): CartItem
    {
        if ($qty < 1) {
            throw new InvalidArgumentException('A line keeps at least one; remove it instead.');
        }

        return DB::transaction(function () use ($item, $qty): CartItem {
            // Lock the variant before any other read (see AddToCart), then re-read the line it was loaded with.
            $variant = Variant::query()->lockForUpdate()->findOrFail($item->purchasable_id);
            $item->refresh();

            $reservation = $item->reservation()->first();

            if ($reservation === null || ! $reservation->isLive()) {
                throw new LogicException('The hold on this line has expired.');
            }

            if ($qty > $item->qty) {
                if (! $variant->isPurchasable()) {
                    throw new NotPurchasable;
                }

                $this->reserveStock->handle($item->cart, $variant, $qty - $item->qty);
            } elseif ($qty < $item->qty) {
                $reservation->update(['qty' => $qty]);

                // The difference is available to others again.
                VariantAlerts::dispatchFor([$variant->id]);
            }

            $item->update(['qty' => $qty]);

            return $item;
        });
    }
}

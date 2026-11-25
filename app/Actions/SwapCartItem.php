<?php

namespace App\Actions;

use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\CartItem;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a line whose stock was taken with the substitute the cart page offered.
 */
class SwapCartItem
{
    public function __construct(
        private RenewCartItem $renewCartItem,
        private RemoveCartItem $removeCartItem,
        private AddToCart $addToCart,
    ) {}

    /**
     * Removes the line and adds the substitute with the line's quantity, capped at what is available. Returns null and
     * changes nothing unless `$variantId` is exactly the substitute RenewCartItem offers for this line right now.
     *
     * @throws NotPurchasable when the substitute stopped being buyable.
     * @throws InsufficientStock when none could be reserved after all; the line is then left as it was.
     */
    public function handle(CartItem $item, int $variantId): ?CartItem
    {
        // A swap trades one variant for another of the same printing; any other kind of line has no substitute.
        if ($item->purchasable_type !== (new Variant)->getMorphClass()) {
            return null;
        }

        return DB::transaction(function () use ($item, $variantId): ?CartItem {
            // The locking read comes first (see AddToCart), and it takes both variants this swap touches, the line's
            // own and the candidate, in ascending id order: RemoveCartItem and AddToCart lock them again below, and
            // two swaps crossing in opposite directions must not wait on each other.
            $locked = Variant::query()
                ->whereIn('id', array_unique([(int) $item->purchasable_id, $variantId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $candidate = $locked->get($variantId);
            $current = CartItem::query()->find($item->getKey());

            if ($candidate === null || $current === null) {
                return null;
            }

            $offered = $this->renewCartItem->substituteFor($current);

            if ($offered === null || ! $offered->is($candidate)) {
                return null;
            }

            $qty = min($current->qty, $candidate->availableQty());
            $cart = $current->cart;

            $this->removeCartItem->handle($current);

            return $this->addToCart->handle($cart, $candidate, $qty);
        });
    }
}

<?php

namespace App\Actions;

use App\Contracts\Purchasable;
use App\Enums\RenewOutcome;
use App\Models\CartItem;
use App\Models\Variant;
use App\Support\PurchasableTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Locks a cart line again after its hold expired, with whatever is still on the shelf.
 */
class RenewCartItem
{
    public function __construct(private ReserveStock $reserveStock) {}

    /**
     * Holds `min(line qty, holdable)`, shrinks the line to it and re-prices the line at the item's current price.
     * With nothing available, nothing is held and the result carries the substitute on offer, if any (only a variant
     * has substitutes). A line whose hold has run out first gives the item's release a chance, so a one-of-a-kind item
     * that can no longer be bought does not stay `reserved`. An item that
     * can no longer be bought comes back `Gone` and the line is left as it was. A line that is already held is left alone.
     */
    public function handle(CartItem $item): RenewResult
    {
        return DB::transaction(function () use ($item): RenewResult {
            // The locking read comes first (see AddToCart); every read after it sees the holds committed while waiting.
            // Found by the line's own type, never by id alone.
            $class = PurchasableTypes::classFor($item->purchasable_type);
            $purchasable = $class === null ? null : $class::lockForHold((int) $item->purchasable_id);
            $item->refresh();

            if ($purchasable !== null && ! $this->isHeld($item)) {
                $purchasable->onReleased();
            }

            if (! $this->isPurchasable($purchasable)) {
                return new RenewResult(RenewOutcome::Gone);
            }

            if ($this->isHeld($item)) {
                return new RenewResult(RenewOutcome::Renewed);
            }

            $take = $this->takeable($item, $purchasable);

            if ($take < 1) {
                return new RenewResult(RenewOutcome::Taken, substitute: $this->substituteFor($item));
            }

            $this->reserveStock->handle($item->cart, $purchasable, $take);

            $qtyReduced = $take < $item->qty ? ['from' => $item->qty, 'to' => $take] : null;
            $priceChanged = $purchasable->unitPriceSatang() !== $item->unit_price_satang
                ? ['from' => $item->unit_price_satang, 'to' => $purchasable->unitPriceSatang()]
                : null;

            $item->update(['qty' => $take, 'unit_price_satang' => $purchasable->unitPriceSatang()]);

            return new RenewResult(RenewOutcome::Renewed, $qtyReduced, $priceChanged);
        });
    }

    /**
     * The cheapest other variant of the same printing that can be bought now and is not already a line of this cart, offered only when renewing this line
     * would find nothing to hold. Never trust a client-sent variant id: compare it with this. Reads the stock sums,
     * so call it inside a transaction that has already locked a variant (SwapCartItem does).
     */
    public function substituteFor(CartItem $item): ?Variant
    {
        // Only a variant has a substitute: another condition of the same printing. Never read the id against `variants`
        // for a line of another type.
        if ($item->purchasable_type !== (new Variant)->getMorphClass()) {
            return null;
        }

        $variant = Variant::query()->find($item->purchasable_id);

        if (! $this->isPurchasable($variant) || $this->isHeld($item) || $this->takeable($item, $variant) > 0) {
            return null;
        }

        return Variant::query()
            ->available()
            ->purchasable()
            ->where('printing_id', $variant->printing_id)
            ->whereKeyNot($variant->getKey())
            // Never one the cart already has a line for, live or expired: swapping into it would merge the two lines.
            ->whereNotIn('id', CartItem::query()
                ->select('purchasable_id')
                ->where('cart_id', $item->cart_id)
                ->where('purchasable_type', $variant->getMorphClass()))
            ->orderBy('price_satang')
            ->orderBy('id')
            ->first();
    }

    private function isPurchasable((Model&Purchasable)|null $purchasable): bool
    {
        return $purchasable !== null && $purchasable->isPurchasable();
    }

    private function isHeld(CartItem $item): bool
    {
        return (bool) $item->reservation()->first()?->isLive();
    }

    /**
     * How many of the line's copies can be held now, counting the live holds of every cart.
     */
    private function takeable(CartItem $item, Model&Purchasable $purchasable): int
    {
        return min($item->qty, $purchasable->maxPerLine(), $purchasable->holdableQty());
    }
}

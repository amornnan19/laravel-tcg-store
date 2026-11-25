<?php

namespace App\Actions;

use App\Enums\RenewOutcome;
use App\Models\CartItem;
use App\Models\StockReservation;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * Locks a cart line again after its hold expired, with whatever is still on the shelf.
 */
class RenewCartItem
{
    public function __construct(private ReserveStock $reserveStock) {}

    /**
     * Holds `min(line qty, available)`, shrinks the line to it and re-prices the line at the variant's current price.
     * With nothing available, nothing is held and the result carries the substitute on offer, if any. An item that
     * can no longer be bought comes back `Gone` and the line is left as it was. A line that is already held is left alone.
     */
    public function handle(CartItem $item): RenewResult
    {
        return DB::transaction(function () use ($item): RenewResult {
            // The locking read comes first (see AddToCart); every read after it sees the holds committed while waiting.
            $variant = Variant::query()->lockForUpdate()->find($item->purchasable_id);
            $item->refresh();

            if (! $this->isPurchasable($variant)) {
                return new RenewResult(RenewOutcome::Gone);
            }

            if ($this->isHeld($item)) {
                return new RenewResult(RenewOutcome::Renewed);
            }

            $take = $this->takeable($item, $variant);

            if ($take < 1) {
                return new RenewResult(RenewOutcome::Taken, substitute: $this->substituteFor($item));
            }

            $this->reserveStock->handle($item->cart, $variant, $take);

            $qtyReduced = $take < $item->qty ? ['from' => $item->qty, 'to' => $take] : null;
            $priceChanged = $variant->price_satang !== $item->unit_price_satang
                ? ['from' => $item->unit_price_satang, 'to' => $variant->price_satang]
                : null;

            $item->update(['qty' => $take, 'unit_price_satang' => $variant->price_satang]);

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

    private function isPurchasable(?Variant $variant): bool
    {
        return $variant !== null && $variant->isPurchasable();
    }

    private function isHeld(CartItem $item): bool
    {
        return (bool) $item->reservation()->first()?->isLive();
    }

    /**
     * How many of the line's copies can be held now, counting the live holds of every cart.
     */
    private function takeable(CartItem $item, Variant $variant): int
    {
        $held = (int) StockReservation::query()
            ->live()
            ->where('purchasable_type', $variant->getMorphClass())
            ->where('purchasable_id', $variant->getKey())
            ->sum('qty');

        return min($item->qty, max(0, $variant->qty - $held));
    }
}

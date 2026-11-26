<?php

namespace App\Livewire\Storefront;

use App\Actions\ChangeCartQuantity;
use App\Actions\RemoveCartItem;
use App\Actions\RenewCartItem;
use App\Actions\SwapCartItem;
use App\Enums\RenewOutcome;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Variant;
use App\Support\CartLines;
use App\Support\CurrentCart;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LogicException;

/**
 * The cart page: the visitor's lines with a quantity stepper, a remove button, the lock countdown and the summary.
 * A line whose hold ran out stays, marked, with a button to lock it again; it is out of the subtotal until then.
 *
 * The only state is what the last renew or failed change said about a line. The cart itself is read from the
 * session on every request, and every item id a client sends is resolved inside that cart.
 */
class CartPanel extends Component
{
    /**
     * What the page says about a line after an action, `item id => result`. Only ids, ints and enum values.
     *
     * @var array<int, array{outcome: string, qty?: array{from: int, to: int}|null, price?: array{from: int, to: int}|null, substitute?: int|null}>
     */
    #[Locked]
    public array $results = [];

    public function increment(int $itemId, ChangeCartQuantity $changeCartQuantity): void
    {
        $item = $this->findItem($itemId);
        unset($this->results[$itemId]);

        if ($this->isLive($item)) {
            try {
                $changeCartQuantity->handle($item, $item->qty + 1);
            } catch (InsufficientStock) {
                $this->results[$itemId] = ['outcome' => 'at_cap'];
            } catch (LogicException|NotPurchasable|ModelNotFoundException) {
                // The hold ran out, or the item was withdrawn, between the render and this click: the refreshed line shows it.
            }
        }

        $this->changed();
    }

    public function decrement(int $itemId, ChangeCartQuantity $changeCartQuantity): void
    {
        $item = $this->findItem($itemId);
        unset($this->results[$itemId]);

        if ($item->qty > 1 && $this->isLive($item)) {
            try {
                $changeCartQuantity->handle($item, $item->qty - 1);
            } catch (LogicException|ModelNotFoundException) {
                // Expired, or the product was trashed, between the render and this click, as in increment().
            }
        }

        $this->changed();
    }

    public function remove(int $itemId, RemoveCartItem $removeCartItem): void
    {
        $removeCartItem->handle($this->findItem($itemId));
        unset($this->results[$itemId]);

        $this->changed();
    }

    public function renew(int $itemId, RenewCartItem $renewCartItem): void
    {
        $this->renewLine($this->findItem($itemId), $renewCartItem);

        $this->changed();
    }

    /**
     * Locks a line again and keeps what that came to, replacing whatever the page said about the line before.
     */
    private function renewLine(CartItem $item, RenewCartItem $renewCartItem): void
    {
        $result = $renewCartItem->handle($item);

        unset($this->results[$item->id]);

        if ($result->outcome === RenewOutcome::Renewed && ($result->qtyReduced !== null || $result->priceChanged !== null)) {
            $this->results[$item->id] = [
                'outcome' => RenewOutcome::Renewed->value,
                'qty' => $result->qtyReduced,
                'price' => $result->priceChanged,
            ];
        } elseif ($result->outcome === RenewOutcome::Taken) {
            $this->results[$item->id] = ['outcome' => RenewOutcome::Taken->value, 'substitute' => $result->substitute?->id];
        } elseif ($result->outcome === RenewOutcome::Gone) {
            $this->results[$item->id] = ['outcome' => RenewOutcome::Gone->value];
        }
    }

    /**
     * Takes the offered substitute in place of the line. The variant id is the client's claim: SwapCartItem only acts
     * when it is what RenewCartItem would offer right now.
     */
    public function swap(int $itemId, int $variantId, SwapCartItem $swapCartItem, RenewCartItem $renewCartItem): void
    {
        $item = $this->findItem($itemId);

        try {
            $swapped = $swapCartItem->handle($item, $variantId);

            unset($this->results[$itemId]);

            if ($swapped !== null) {
                $this->results[$swapped->id] = ['outcome' => 'swapped'];
            } else {
                // The offer was stale (the original came back, or another substitute appeared): show what is true now.
                $this->renewLine($item, $renewCartItem);
            }
        } catch (InsufficientStock|NotPurchasable) {
            $this->results[$itemId] = ['outcome' => 'failed'];
        }

        $this->changed();
    }

    private function findItem(int $itemId): CartItem
    {
        $cart = $this->cart;

        abort_if($cart === null, 404);

        return $cart->items()->findOrFail($itemId);
    }

    /**
     * Whether the line still holds stock, read from the database so a hold that ran out since the render counts.
     */
    private function isLive(CartItem $item): bool
    {
        return (bool) $item->reservation()->first()?->isLive();
    }

    /**
     * Every action drops what the page derived from the cart, and tells the cart links to recount.
     */
    private function changed(): void
    {
        unset($this->cart, $this->cartLines, $this->lines, $this->purchasableIds, $this->heldLines, $this->expiresAt, $this->subtotal, $this->substitutes);

        $this->dispatch('cart-updated');
    }

    #[Computed]
    public function cart(): ?Cart
    {
        return app(CurrentCart::class)->find();
    }

    /**
     * The cart's lines and the rules about them, shared with checkout and PlaceOrder.
     */
    #[Computed]
    public function cartLines(): CartLines
    {
        return new CartLines($this->cart);
    }

    /**
     * @return Collection<int, CartItem>
     */
    #[Computed]
    public function lines(): Collection
    {
        return $this->cartLines->lines();
    }

    /**
     * The cart's purchasables that still pass the purchasability rule, as `type:id` keys.
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function purchasableIds(): Collection
    {
        return $this->cartLines->purchasableIds();
    }

    /**
     * The lines that hold stock and can still be bought: the ones the countdown and the subtotal are about.
     *
     * @return Collection<int, CartItem>
     */
    #[Computed]
    public function heldLines(): Collection
    {
        return $this->lines->filter(fn (CartItem $line): bool => ! $this->isGone($line) && $this->cartLines->isHeld($line))->values();
    }

    /**
     * The soonest `expires_at` of a held line, or null when nothing is held.
     */
    #[Computed]
    public function expiresAt(): ?CarbonInterface
    {
        return $this->heldLines->map(fn (CartItem $line): CarbonInterface => $line->reservation->expires_at)->min();
    }

    /**
     * Satang: unit price times quantity over the held lines. An expired or gone line is not part of it.
     */
    #[Computed]
    public function subtotal(): int
    {
        return $this->heldLines->sum(fn (CartItem $line): int => $line->unit_price_satang * $line->qty);
    }

    /**
     * Whether every line can be ordered, so the page offers checkout.
     */
    public function canCheckout(): bool
    {
        return $this->lines->isNotEmpty() && $this->lines->every(fn (CartItem $line): bool => ! $this->isGone($line) && $this->cartLines->isOrderable($line));
    }

    /**
     * A held line whose item has less stock than the line asks for.
     */
    public function isStockShort(CartItem $line): bool
    {
        return ! $this->isGone($line) && $this->cartLines->isStockShort($line);
    }

    /**
     * The variants offered in place of a line whose stock was taken, `variant id => variant`, still available now.
     *
     * @return Collection<int, Variant>
     */
    #[Computed]
    public function substitutes(): Collection
    {
        $ids = collect($this->results)->pluck('substitute')->filter()->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return Variant::query()->available()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * A line that fails the purchasability rule (no price, card or printing trashed, game inactive, variant deleted),
     * or that a renew just found unbuyable. It holds nothing the customer can use, so it has no stepper and no price.
     */
    public function isGone(CartItem $line): bool
    {
        return ($this->results[$line->id]['outcome'] ?? null) === RenewOutcome::Gone->value
            || $this->cartLines->isGone($line);
    }

    public function render(): View
    {
        return view('livewire.storefront.cart-panel');
    }
}

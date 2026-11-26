<?php

namespace App\Livewire\Storefront;

use App\Actions\AddToCart;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\SealedProduct;
use App\Models\StockReservation;
use App\Support\CurrentCart;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The price, the stock, the quantity stepper and the add button of a sealed product's page. Stock is re-read on every
 * request, so a stale quantity falls back to what can be held now. The cart row is created on the first add, never on
 * a page view. A pre-order product has no button here: it is not bought from stock.
 */
class SealedBuyBox extends Component
{
    #[Locked]
    public int $sealedProductId;

    public int $qty = 1;

    /** What the last add said, shown next to the button. */
    public ?string $notice = null;

    public function mount(int $sealedProductId): void
    {
        $this->sealedProductId = $sealedProductId;
    }

    public function increment(): void
    {
        $this->notice = null;
        $this->qty = min($this->quantity() + 1, $this->cap);
    }

    public function decrement(): void
    {
        $this->notice = null;
        $this->qty = max($this->quantity() - 1, 1);
    }

    public function add(AddToCart $addToCart, CurrentCart $currentCart): void
    {
        $this->notice = null;

        $product = $this->product;

        if ($product === null || $this->state !== 'available') {
            $this->notice = __('storefront.sealed.add_failed');
            $this->refreshStock();

            return;
        }

        try {
            $addToCart->handle($currentCart->findOrCreate(), $product, $this->quantity());

            $this->qty = 1;
            $this->dispatch('cart-updated');
        } catch (InsufficientStock $stock) {
            $this->notice = $stock->available > 0
                ? __('storefront.sealed.add_failed_some', ['n' => $stock->available])
                : __('storefront.sealed.add_failed_none');
        } catch (NotPurchasable) {
            $this->notice = __('storefront.sealed.add_failed');
        }

        $this->refreshStock();
    }

    #[Computed]
    public function product(): ?SealedProduct
    {
        return SealedProduct::query()->withReservedQty()->find($this->sealedProductId);
    }

    /**
     * The live hold of the visitor's own cart on this product, if any.
     */
    #[Computed]
    public function myHold(): ?StockReservation
    {
        $cart = app(CurrentCart::class)->find();

        return $cart === null ? null : StockReservation::query()
            ->live()
            ->where('cart_id', $cart->getKey())
            ->where('purchasable_type', (new SealedProduct)->getMorphClass())
            ->where('purchasable_id', $this->sealedProductId)
            ->first();
    }

    /**
     * What can still be held: stock minus every cart's live holds, which the stepper never goes above.
     */
    #[Computed]
    public function cap(): int
    {
        return $this->product?->availableQty() ?? 0;
    }

    /**
     * `preorder` (bought through the pre-order flow), `unavailable` (inactive, trashed, unpriced or its game is off),
     * `sold_out`, or `available`.
     */
    #[Computed]
    public function state(): string
    {
        $product = $this->product;

        return match (true) {
            $product === null || ! $product->is_active => 'unavailable',
            $product->is_preorder => 'preorder',
            ! $product->isPurchasable() => 'unavailable',
            $this->cap < 1 => 'sold_out',
            default => 'available',
        };
    }

    /**
     * The quantity to show: what was asked for, held between 1 and the cap. A method and not a computed property,
     * because the stepper changes `qty` in the same request that renders it.
     */
    public function quantity(): int
    {
        return max(1, min($this->qty, max($this->cap, 1)));
    }

    /**
     * Availability changed either way, so every stock-derived computed is dropped and the next render reads the new figures.
     */
    private function refreshStock(): void
    {
        unset($this->product, $this->myHold, $this->cap, $this->state);
    }

    public function render(): View
    {
        return view('livewire.storefront.sealed-buy-box');
    }
}

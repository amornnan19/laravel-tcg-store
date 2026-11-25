<?php

namespace App\Livewire\Storefront;

use App\Actions\AddToCart;
use App\Enums\GradedStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\NotPurchasable;
use App\Models\GradedItem;
use App\Models\StockReservation;
use App\Support\CurrentCart;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The price and the add button of the graded page. A graded card is one slab, so the box has no quantity: it shows
 * whether the visitor can take it, already has it locked in their cart, or cannot because it was sold or someone else
 * holds it. The cart row is created on the first add, never on a page view.
 */
class GradedBuyBox extends Component
{
    #[Locked]
    public int $gradedItemId;

    /** What the last add said, shown next to the button. */
    public ?string $notice = null;

    public function mount(int $gradedItemId): void
    {
        $this->gradedItemId = $gradedItemId;
    }

    public function add(AddToCart $addToCart, CurrentCart $currentCart): void
    {
        $this->notice = null;

        $item = $this->item;

        if ($item === null) {
            $this->notice = __('storefront.graded.add_failed');

            return;
        }

        try {
            $addToCart->handle($currentCart->findOrCreate(), $item, 1);

            $this->dispatch('cart-updated');
        } catch (InsufficientStock|NotPurchasable) {
            // Taken between the render and this click: the refreshed box says why.
            $this->notice = null;
        }

        unset($this->item, $this->myHold, $this->state);
    }

    #[Computed]
    public function item(): ?GradedItem
    {
        return GradedItem::query()->find($this->gradedItemId);
    }

    /**
     * The live hold of the visitor's own cart on this item, if any.
     */
    #[Computed]
    public function myHold(): ?StockReservation
    {
        $cart = app(CurrentCart::class)->find();

        return $cart === null ? null : StockReservation::query()
            ->live()
            ->where('cart_id', $cart->getKey())
            ->where('purchasable_type', (new GradedItem)->getMorphClass())
            ->where('purchasable_id', $this->gradedItemId)
            ->first();
    }

    /**
     * Whether another graded copy of the same card is for sale, for the exit link when this one is taken or sold.
     */
    #[Computed]
    public function hasOtherGraded(): bool
    {
        $cardId = $this->item?->printing?->card_id;

        return $cardId !== null && GradedItem::query()
            ->where('status', GradedStatus::Listed->value)
            ->whereKeyNot($this->gradedItemId)
            ->whereHas('printing', fn ($printing) => $printing->where('card_id', $cardId))
            ->exists();
    }

    /**
     * `sold`, `mine` (in this cart), `taken` (another cart holds it), `available`, or `unavailable` (withdrawn or
     * unbuyable now).
     */
    #[Computed]
    public function state(): string
    {
        $item = $this->item;

        return match (true) {
            $item === null || $item->status === GradedStatus::Withdrawn => 'unavailable',
            $item->status === GradedStatus::Sold => 'sold',
            $this->myHold !== null => 'mine',
            $item->holdableQty() < 1 => 'taken',
            $item->isPurchasable() => 'available',
            default => 'unavailable',
        };
    }

    public function render(): View
    {
        return view('livewire.storefront.graded-buy-box');
    }
}

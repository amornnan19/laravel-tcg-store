<?php

namespace App\Livewire\Storefront;

use App\Actions\ToggleWishlist;
use App\Models\Printing;
use App\Models\WishlistItem;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The wishlist heart in the card page's header. The restock bell and the price watch live in `VariantTable`, which
 * owns the variant rows they belong to.
 */
class AlertToggle extends Component
{
    #[Locked]
    public int $printingId;

    public function mount(int $printingId): void
    {
        $this->printingId = $printingId;
    }

    /**
     * Whether the signed-in visitor has this printing on their wishlist. Always false for a guest.
     */
    #[Computed]
    public function wishlisted(): bool
    {
        $userId = auth()->id();

        return $userId !== null
            && WishlistItem::query()->where('user_id', $userId)->where('printing_id', $this->printingId)->exists();
    }

    /**
     * A guest is sent to log in and brought back to this card afterwards.
     */
    public function toggle(ToggleWishlist $toggleWishlist): void
    {
        $printing = Printing::query()->searchVisible()->with('card')->find($this->printingId);

        if ($printing === null) {
            return;
        }

        $user = auth()->user();

        if ($user === null) {
            session()->put('url.intended', route('card.show', ['card' => $printing->card, 'p' => $printing->id]));
            session()->put('wishlist.pending', $printing->id);

            $this->redirectRoute('login');

            return;
        }

        $toggleWishlist->handle($user, $printing);

        unset($this->wishlisted);
    }

    public function render(): View
    {
        return view('livewire.storefront.alert-toggle');
    }
}

<?php

namespace App\Livewire\Storefront;

use App\Models\Cart;
use App\Models\CartItem;
use App\Support\CurrentCart;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The cart link with its badge: in the desktop header (`header`) and in the phone's bottom navigation (`bottom`).
 * The badge is the number of lines in the visitor's cart, read with one count and no query at all before the first add.
 */
class CartLink extends Component
{
    #[Locked]
    public string $variant = 'header';

    /** Whether the page being viewed is the cart; decided on the first request, since a refresh is a Livewire request. */
    #[Locked]
    public bool $current = false;

    public function mount(string $variant = 'header'): void
    {
        $this->variant = $variant === 'bottom' ? 'bottom' : 'header';
        $this->current = request()->routeIs('cart');
    }

    #[On('cart-updated')]
    public function refreshCount(): void
    {
        unset($this->count);
    }

    #[Computed]
    public function count(): int
    {
        $token = app(CurrentCart::class)->token();

        if ($token === null) {
            return 0;
        }

        return CartItem::query()
            ->whereIn('cart_id', Cart::query()->select('id')->where('token', $token))
            ->count();
    }

    public function render(): View
    {
        return view('livewire.storefront.cart-link');
    }
}

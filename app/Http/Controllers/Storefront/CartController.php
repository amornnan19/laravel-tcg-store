<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Support\CurrentCart;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * `/cart`: the visitor's cart. Step A of M4 only lists what it holds, read-only; the cart panel with the
 * stepper, the countdown and renewing an expired hold replaces the list in step B.
 */
class CartController extends Controller
{
    public function __invoke(CurrentCart $currentCart): View
    {
        /** @var Collection<int, CartItem> $lines */
        $lines = $currentCart->find()
            ?->items()
            ->with('purchasable.printing.card')
            ->orderBy('id')
            ->get()
            ->filter(fn (CartItem $line): bool => $line->purchasable?->printing?->card !== null)
            ->values() ?? new Collection;

        return view('cart', ['lines' => $lines]);
    }
}

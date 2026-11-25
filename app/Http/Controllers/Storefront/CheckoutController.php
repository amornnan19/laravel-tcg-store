<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Support\CurrentCart;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * `/checkout`: the page around the checkout form. Without a cart, or with nothing held in it, there is nothing to
 * pay for, so the visitor goes back to the cart.
 */
class CheckoutController extends Controller
{
    public function __invoke(CurrentCart $currentCart): View|RedirectResponse
    {
        $cart = $currentCart->find();

        if ($cart === null || ! $cart->reservations()->live()->exists()) {
            return redirect()->route('cart');
        }

        return view('checkout');
    }
}

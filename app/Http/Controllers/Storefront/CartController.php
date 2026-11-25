<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * `/cart`: the page around the cart panel, which reads the visitor's cart itself.
 */
class CartController extends Controller
{
    public function __invoke(): View
    {
        return view('cart');
    }
}

<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * `/buylist`: the page around the buylist builder, open to guests.
 */
class BuylistController extends Controller
{
    public function __invoke(): View
    {
        return view('buylist');
    }
}

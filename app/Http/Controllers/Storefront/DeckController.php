<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * `/deck`: the page around the deck-list importer, open to guests.
 */
class DeckController extends Controller
{
    public function __invoke(): View
    {
        return view('deck');
    }
}

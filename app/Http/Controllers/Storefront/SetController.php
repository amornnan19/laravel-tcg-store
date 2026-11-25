<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CardSet;
use Illuminate\Contracts\View\View;

/**
 * `/s/{set:code}`: the set browser (D04). The browsing itself is the `SetBrowser` component.
 */
class SetController extends Controller
{
    public function __invoke(CardSet $set): View
    {
        return view('set', ['set' => $set, 'title' => $set->display_code.' '.$set->name_en]);
    }
}

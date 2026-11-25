<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\SavedDeckList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Deleting one of the signed-in customer's saved deck lists. Someone else's id is a 404, as if it did not exist.
 */
class AccountDeckController extends Controller
{
    public function destroy(Request $request, SavedDeckList $savedDeckList): RedirectResponse
    {
        abort_unless($savedDeckList->user_id === $request->user()->id, 404);

        $savedDeckList->delete();

        return redirect()->route('account')->with('status', __('storefront.account.decks.removed'));
    }
}

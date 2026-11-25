<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\RemovePriceAlert;
use App\Actions\RemoveRestockAlert;
use App\Http\Controllers\Controller;
use App\Models\PriceAlert;
use App\Models\RestockAlert;
use App\Models\WishlistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Removing the signed-in customer's own wishlist entries and alerts from the account page. Someone else's id is a 404,
 * as if it did not exist.
 */
class AccountAlertController extends Controller
{
    public function destroyWishlistItem(Request $request, WishlistItem $wishlistItem): RedirectResponse
    {
        abort_unless($wishlistItem->user_id === $request->user()->id, 404);

        $wishlistItem->delete();

        return redirect()->route('account')->with('status', __('storefront.account.wishlist_removed'));
    }

    public function destroyPriceAlert(Request $request, PriceAlert $priceAlert, RemovePriceAlert $removePriceAlert): RedirectResponse
    {
        abort_unless($priceAlert->user_id === $request->user()->id, 404);

        $removePriceAlert->handle($request->user(), $priceAlert);

        return redirect()->route('account')->with('status', __('storefront.account.alert_removed'));
    }

    public function destroyRestockAlert(Request $request, RestockAlert $restockAlert, RemoveRestockAlert $removeRestockAlert): RedirectResponse
    {
        abort_unless($restockAlert->user_id === $request->user()->id, 404);

        $removeRestockAlert->handle($request->user(), $restockAlert);

        return redirect()->route('account')->with('status', __('storefront.account.alert_removed'));
    }
}

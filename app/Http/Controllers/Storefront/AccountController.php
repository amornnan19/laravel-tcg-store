<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/account`: the signed-in customer's orders, LINE link, notification choice and email verification notice.
 */
class AccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('account', [
            'user' => $user,
            'orders' => $user->orders()->latest('placed_at')->paginate(10),
            // Only what the storefront still shows: a card that left the shop or an inactive game drops off the list.
            'wishlist' => $user->wishlistItems()
                ->whereHas('printing', fn (Builder $printing) => $printing->withoutTrashed()->searchVisible())
                ->with(['printing' => fn (Relation $printing) => $printing->withCheapestVariant()])
                ->orderByDesc('id')
                ->get(),
            'priceAlerts' => $user->priceAlerts()
                ->whereHas('variant.printing', fn (Builder $printing) => $printing->searchVisible())
                ->with(['variant.printing.card', 'variant.printing.set'])
                ->orderByDesc('id')
                ->get(),
            'restockAlerts' => $user->restockAlerts()
                ->whereHas('variant.printing', fn (Builder $printing) => $printing->searchVisible())
                ->with(['variant.printing.card', 'variant.printing.set'])
                ->orderByDesc('id')
                ->get(),
            'savedDecks' => $user->savedDeckLists()->select(['id', 'name', 'game_id', 'requested_cards', 'created_at'])->with('game:id,name_en')->orderByDesc('id')->get(),
            'lineConfigured' => filled(config('services.line.client_id')),
            'lineMessagingConfigured' => filled(config('services.line.messaging_token')),
        ]);
    }

    /**
     * Saves whether the shop messages the customer on LINE. It needs a linked LINE account and a Messaging API token:
     * without either there is no way to push, so the choice is refused rather than stored and silently ignored.
     */
    public function notifications(Request $request): RedirectResponse
    {
        $validated = $request->validate(['line_notify_opt_in' => ['nullable', 'boolean']]);

        $user = $request->user();

        if (! $user->hasLine() || blank(config('services.line.messaging_token'))) {
            return redirect()->route('account')->with('status_error', __('storefront.account.line_required'));
        }

        $user->forceFill(['line_notify_opt_in' => (bool) ($validated['line_notify_opt_in'] ?? false)])->save();

        return redirect()->route('account')->with('status', __('storefront.account.notifications_saved'));
    }
}

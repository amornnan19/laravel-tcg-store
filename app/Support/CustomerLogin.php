<?php

namespace App\Support;

use App\Exceptions\StaffLoginRefused;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The only way the storefront signs a user in. Staff are refused here because the panel's second factor lives on
 * Filament's login page alone; nothing else in the storefront calls `Auth::login`.
 */
final class CustomerLogin
{
    /**
     * @throws StaffLoginRefused when the user is staff.
     */
    public static function login(User $user, bool $remember = false): void
    {
        if ($user->is_staff) {
            throw new StaffLoginRefused;
        }

        Auth::guard('web')->login($user, $remember);

        session()->regenerate();

        // Only orders this session placed move into the account: `orders` also lists orders it merely opened through a
        // signed link, which prove access and not ownership. An order that already has an owner is never taken.
        Order::query()
            ->whereKey((array) session()->get('orders_placed', []))
            ->whereNull('user_id')
            ->update(['user_id' => $user->getKey()]);
    }

    /**
     * Where a storefront login goes next: the page the visitor was sent away from, else the account page. A guest who
     * once hit the panel leaves an `/admin` URL behind, which would be a 403 for a customer, so it is discarded.
     */
    public static function redirectIntended(): RedirectResponse
    {
        $intended = session()->get('url.intended');

        if (is_string($intended)) {
            $path = trim((string) parse_url($intended, PHP_URL_PATH), '/');

            if ($path === 'admin' || str_starts_with($path, 'admin/')) {
                session()->forget('url.intended');
            }
        }

        return redirect()->intended(route('account'));
    }
}

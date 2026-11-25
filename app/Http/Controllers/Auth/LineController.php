<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\StaffLoginRefused;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CustomerLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * LINE Login: sign in, create an account, or link LINE to the signed-in account. LINE's email is trusted for linking
 * to an existing account only when that account's own address is verified and it is not staff; otherwise anyone could
 * register with a victim's address and inherit the victim's LINE login.
 */
class LineController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_if(blank(config('services.line.client_id')), 404);

        if (Auth::check()) {
            $request->session()->put('line.intent', 'link');
        } else {
            $request->session()->forget('line.intent');
        }

        return Socialite::driver('line')->scopes(['profile', 'openid', 'email'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $linking = Auth::check() && $request->session()->pull('line.intent') === 'link';

        // A signed-in visitor who did not start from "link LINE" is never switched to another account.
        if (Auth::check() && ! $linking) {
            return redirect()->route('account');
        }

        $failedRoute = $linking ? 'account' : 'login';

        if ($request->query->has('error')) {
            return redirect()->route($failedRoute)->with('status_error', __('auth.line_failed'));
        }

        try {
            $line = Socialite::driver('line')->user();
        } catch (Throwable) {
            return redirect()->route($failedRoute)->with('status_error', __('auth.line_failed'));
        }

        if ($linking) {
            return $this->link($request->user(), $line);
        }

        $lineId = (string) $line->getId();

        $user = User::query()->where('line_user_id', $lineId)->first();

        if ($user !== null) {
            $user->forceFill([
                'line_display_name' => $line->getName(),
                'line_avatar_url' => $line->getAvatar(),
            ])->save();
        } else {
            $user = $this->userForNewLine($line, $lineId);
        }

        try {
            CustomerLogin::login($user);
        } catch (StaffLoginRefused) {
            return redirect()->route('login')->with('status_error', __('auth.line_staff'));
        }

        return CustomerLogin::redirectIntended();
    }

    public function unlink(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPassword() || $user->email === null) {
            return redirect()->route('account')->with('status_error', __('storefront.account.line_unlink_needs_password'));
        }

        $user->forceFill([
            'line_user_id' => null,
            'line_display_name' => null,
            'line_avatar_url' => null,
            'line_notify_opt_in' => false,
        ])->save();

        return redirect()->route('account')->with('status', __('storefront.account.line_unlinked'));
    }

    private function link(User $user, SocialiteUser $line): RedirectResponse
    {
        if ($user->is_staff) {
            return redirect()->route('account')->with('status_error', __('storefront.account.line_staff'));
        }

        $lineId = (string) $line->getId();

        if (User::query()->where('line_user_id', $lineId)->whereKeyNot($user->getKey())->exists()) {
            return redirect()->route('account')->with('status_error', __('storefront.account.line_taken'));
        }

        $this->fillLine($user, $line, $lineId)->save();

        return redirect()->route('account')->with('status', __('storefront.account.line_linked'));
    }

    /**
     * A verified, non-staff account with LINE's email takes the LINE identity; otherwise a new account is made.
     */
    private function userForNewLine(SocialiteUser $line, string $lineId): User
    {
        $email = filled($line->getEmail()) ? User::normalizeEmail((string) $line->getEmail()) : null;

        $existing = $email === null ? null : User::query()->where('email', $email)->first();

        if ($existing !== null && $existing->email_verified_at !== null && ! $existing->is_staff) {
            $this->fillLine($existing, $line, $lineId)->save();

            return $existing;
        }

        $user = new User;
        $user->forceFill([
            'name' => filled($line->getName()) ? $line->getName() : 'LINE user',
            'email' => $existing === null ? $email : null,
            'email_verified_at' => null,
            'password' => null,
        ]);
        $this->fillLine($user, $line, $lineId)->save();

        return $user;
    }

    private function fillLine(User $user, SocialiteUser $line, string $lineId): User
    {
        return $user->forceFill([
            'line_user_id' => $lineId,
            'line_display_name' => $line->getName(),
            'line_avatar_url' => $line->getAvatar(),
        ]);
    }
}

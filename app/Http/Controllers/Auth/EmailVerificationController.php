<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Email verification. The link is signed (relative, so the proxy's host does not matter) and also names the signed-in
 * user and the address it was sent to; a link for another account or an old address is a 403.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user->email !== null
                && hash_equals((string) $user->getKey(), $id)
                && hash_equals(sha1($user->email), $hash),
            403,
        );

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->route('account')->with('status', __('storefront.account.verified'));
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->email !== null && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return redirect()->route('account')->with('status', __('storefront.account.verification_sent'));
    }
}

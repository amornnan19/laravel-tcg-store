<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\StaffLoginRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Support\CustomerLogin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * `/login`: email and password for customers. A staff account fails exactly like a wrong password: the panel's second
 * factor is enforced only by the panel's own login page.
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $key = $request->string('email')->value().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = User::query()->where('email', $request->string('email')->value())->first();

        $passwordMatches = $user !== null
            && $user->hasPassword()
            && Hash::check($request->string('password')->value(), $user->password);

        $signedIn = false;

        if ($passwordMatches) {
            try {
                CustomerLogin::login($user, $request->boolean('remember'));
                $signedIn = true;
            } catch (StaffLoginRefused) {
                // Falls through to the same failure as a wrong password.
            }
        }

        if (! $signedIn) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        return CustomerLogin::redirectIntended();
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}

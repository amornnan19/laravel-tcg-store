<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forgotten password for customers. Staff use the panel: their addresses get the same response and no mail, and a
 * reset never signs anyone in.
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => User::normalizeEmail($request->input('email'))]);
        }

        $request->validate(
            ['email' => ['required', 'string', 'email', 'max:255']],
            ['email.email' => __('storefront.auth.errors.email')],
            ['email' => __('storefront.auth.fields.email')],
        );

        $user = User::query()->where('email', $request->string('email')->value())->first();

        if ($user !== null && ! $user->is_staff) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', __('passwords.sent_generic'));
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $user = User::query()->where('email', $request->string('email')->value())->first();

        // Refused the same way as an invalid token, so the form does not say that the address belongs to staff.
        if ($user?->is_staff) {
            throw ValidationException::withMessages(['email' => __('passwords.token')]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                // AuthenticateSession cannot end a session made while the account had no password (a LINE-only sign-in): it
                // never stored a hash to compare. Deleting the stored sessions ends every one of them.
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
                }

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // An unknown address reads as an invalid token too, so the form does not confirm which addresses have accounts.
            throw ValidationException::withMessages([
                'email' => $status === Password::RESET_THROTTLED ? __('passwords.throttled') : __('passwords.token'),
            ]);
        }

        return redirect()->route('login')->with('status', __('passwords.reset'));
    }
}

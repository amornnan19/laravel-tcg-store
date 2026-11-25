<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/account`: the signed-in customer's orders, LINE link and email verification notice.
 */
class AccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('account', [
            'user' => $user,
            'orders' => $user->orders()->latest('placed_at')->paginate(10),
            'lineConfigured' => filled(config('services.line.client_id')),
        ]);
    }
}

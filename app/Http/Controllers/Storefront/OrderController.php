<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/orders/{number}`: one order. Opens with a valid signature (the link in every email) when the visitor's own
 * session placed it, or when the signed-in customer owns it. Anything else is a 404, never a 403, so the page does not confirm that a number exists.
 */
class OrderController extends Controller
{
    public function __invoke(Request $request, Order $order): View
    {
        $ownedBySession = OrderAccess::ownedBySession($order);

        $signed = $request->hasValidSignature();

        abort_unless($signed || OrderAccess::canView($order), 404);

        // The payment panel's Livewire updates carry no signature: a visitor who arrived from the email is remembered
        // by the session the same way as the one who placed the order here.
        if ($signed && ! $ownedBySession) {
            $request->session()->push('orders', $order->getKey());
        }

        return view('order', [
            'order' => $order->load(['items', 'latestPayment']),
        ]);
    }
}

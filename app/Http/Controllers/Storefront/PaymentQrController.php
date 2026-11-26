<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderAccess;
use App\Support\QrImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/orders/{number}/qr.png`: the PromptPay QR of the order's open payment as a download. Opens by the same rule as the
 * order page (a valid signature or the visitor's own session) and is a 404 otherwise, and for an order with no QR to pay.
 */
class PaymentQrController extends Controller
{
    public function __invoke(Request $request, Order $order): Response
    {
        abort_unless($request->hasValidSignature() || OrderAccess::canView($order), 404);

        $payment = $order->isWaitingForMoney()
            ? $order->payments()->where('status', PaymentStatus::Pending->value)->whereNotNull('qr_payload')->latest('id')->first()
            : null;

        abort_if($payment === null, 404);

        return response(QrImage::png($payment->qr_payload), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="promptpay-'.$order->number.'.png"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

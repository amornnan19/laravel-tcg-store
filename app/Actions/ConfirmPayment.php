<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRefused;
use App\Exceptions\SlipRefAlreadyCredited;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\PaymentConfirmed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Staff confirm a submitted slip: the payment is confirmed and, once the money received reaches the order total, the
 * order is paid. Lock order: the order row, then the payment (project-wide: variants, order, payment).
 */
class ConfirmPayment
{
    /**
     * @param  ?string  $slipRef  The reference staff read off the slip; refused when it is already credited on another
     *                            payment of the order.
     *
     * @throws PaymentRefused when the payment is no longer submitted or the order no longer waits for money.
     * @throws SlipRefAlreadyCredited
     */
    public function handle(Payment $payment, ?string $slipRef = null): Payment
    {
        $slipRef = Payment::normaliseSlipRef($slipRef);

        [$confirmed, $order] = DB::transaction(function () use ($payment, $slipRef): array {
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($locked->status !== PaymentStatus::Submitted) {
                throw new PaymentRefused('changed');
            }

            if ($order->status !== OrderStatus::AwaitingPayment) {
                throw new PaymentRefused('order_closed');
            }

            if ($slipRef !== null && $order->slipRefCredited($slipRef, $locked->getKey())) {
                throw new SlipRefAlreadyCredited;
            }

            $locked->update([
                'status' => PaymentStatus::Confirmed,
                'confirmed_at' => now(),
                'slip_ref' => $slipRef,
            ]);

            if ($order->receivedSatang() >= $order->total_satang) {
                $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
            }

            return [$locked, $order];
        });

        Notification::route('mail', $order->contact_email)->notify(new PaymentConfirmed($order));

        return $confirmed;
    }
}

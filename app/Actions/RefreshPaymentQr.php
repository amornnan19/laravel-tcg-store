<?php

namespace App\Actions;

use App\Actions\Concerns\LocksOrderForPayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRefused;
use App\Models\Payment;
use App\Support\PaymentQr;
use Illuminate\Support\Facades\DB;

/**
 * Gives an expired PromptPay QR a new lifetime: same payload, a new expiry that never runs past the payment window.
 * A QR that is still valid is left alone, so a double click changes nothing.
 */
class RefreshPaymentQr
{
    use LocksOrderForPayment;

    /**
     * @throws PaymentRefused when the order is no longer waiting for money, the window has closed, or the payment is
     *                        not a pending PromptPay one.
     */
    public function handle(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment): Payment {
            $order = $this->lockOpenOrder($payment);
            $locked = $this->lockPayment($payment, PaymentStatus::Pending);

            if ($locked->method !== PaymentMethod::Promptpay || $locked->qr_payload === null) {
                throw new PaymentRefused('changed');
            }

            if ($locked->qr_expires_at === null || $locked->qr_expires_at->lte(now())) {
                $locked->update(['qr_expires_at' => PaymentQr::expiry($order->payment_due_at)]);
            }

            return $locked;
        });
    }
}

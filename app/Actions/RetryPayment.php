<?php

namespace App\Actions;

use App\Actions\Concerns\LocksOrderForPayment;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRefused;
use App\Models\Payment;
use App\Support\PaymentQr;
use Illuminate\Support\Facades\DB;

/**
 * After staff reject a slip, opens a fresh pending payment to attach a new slip to: same method, for what the order still
 * owes (the full total unless an earlier short slip arrived), linked to the rejected payment when that one was short.
 */
class RetryPayment
{
    use LocksOrderForPayment;

    /**
     * @throws PaymentRefused when the order is no longer waiting for money, the window has closed, the payment is not
     *                        rejected, or it already has a successor.
     */
    public function handle(Payment $rejected): Payment
    {
        return DB::transaction(function () use ($rejected): Payment {
            $order = $this->lockOpenOrder($rejected);
            $locked = $this->lockPayment($rejected, PaymentStatus::Rejected);
            $this->requireLatest($order, $locked);

            $remaining = $order->remainingSatang();

            if ($remaining <= 0) {
                throw new PaymentRefused('changed');
            }

            $wasShort = $locked->slip_amount_satang !== null && $locked->slip_amount_satang < $locked->amount_satang;

            return $order->payments()->create([
                'method' => $locked->method,
                'status' => PaymentStatus::Pending,
                'amount_satang' => $remaining,
                'shortfall_of_payment_id' => $wasShort ? $locked->getKey() : null,
                ...PaymentQr::attributes($locked->method, $remaining, $order->payment_due_at),
            ]);
        });
    }
}

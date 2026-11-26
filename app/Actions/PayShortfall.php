<?php

namespace App\Actions;

use App\Actions\Concerns\LocksOrderForPayment;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRefused;
use App\Models\Payment;
use App\Support\PaymentQr;
use Illuminate\Support\Facades\DB;

/**
 * After staff reject a slip that was short, opens a new pending payment for what the order still owes (total less what
 * staff have seen arrive), pointing back at the short one. A gap payment that comes up short is paid the same way.
 */
class PayShortfall
{
    use LocksOrderForPayment;

    /**
     * @throws PaymentRefused when the order is no longer waiting for money, the window has closed, the payment was not
     *                        rejected as short, nothing is owed, or it already has a successor.
     */
    public function handle(Payment $rejected): Payment
    {
        return DB::transaction(function () use ($rejected): Payment {
            $order = $this->lockOpenOrder($rejected);
            $locked = $this->lockPayment($rejected, PaymentStatus::Rejected);
            $this->requireLatest($order, $locked);

            $slipAmount = $locked->slip_amount_satang;

            $remaining = $order->amountDueNowSatang();

            if ($slipAmount === null || $slipAmount <= 0 || $slipAmount >= $locked->amount_satang || $remaining <= 0) {
                throw new PaymentRefused('changed');
            }

            $gap = $remaining;

            return $order->payments()->create([
                'method' => $locked->method,
                'status' => PaymentStatus::Pending,
                'amount_satang' => $gap,
                'shortfall_of_payment_id' => $locked->getKey(),
                ...PaymentQr::attributes($locked->method, $gap, $order->payment_due_at),
            ]);
        });
    }
}

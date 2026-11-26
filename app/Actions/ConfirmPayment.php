<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreorderPlan;
use App\Exceptions\PaymentRefused;
use App\Exceptions\SlipRefAlreadyCredited;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\PaymentConfirmed;
use App\Support\OrderRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Staff confirm a submitted slip: the payment is confirmed and, once the money received reaches the order total, the
 * order is paid. A pre-order on the deposit plan moves to `awaiting_balance` when the money received reaches its deposit,
 * with `paid_at` still empty. Lock order: the order row, then the payment (project-wide: variants, order, payment).
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

            // `awaiting_balance` counts only once its balance was requested: that is when a payment of it exists to confirm.
            if (! $order->isWaitingForMoney()) {
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

            $received = $order->receivedSatang();

            if ($received >= $order->total_satang) {
                $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
            } elseif ($order->status === OrderStatus::AwaitingPayment
                && $order->preorder_plan === PreorderPlan::Deposit
                && $received >= $order->deposit_satang) {
                $order->update(['status' => OrderStatus::AwaitingBalance]);
            }

            return [$locked, $order];
        });

        OrderRecipient::notify($order, new PaymentConfirmed($order));

        // A deposit that arrives inside the seven days is asked for its balance now, not at the next 09:00 run.
        if ($order->status === OrderStatus::AwaitingBalance && $order->balance_requested_at === null && $order->preorder?->isWithinBalanceWindow()) {
            app(RequestPreorderBalance::class)->handle($order);
        }

        return $confirmed;
    }
}

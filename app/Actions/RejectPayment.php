<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RejectReason;
use App\Exceptions\PaymentRefused;
use App\Exceptions\SlipRefAlreadyCredited;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\PaymentRejected;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Staff reject a submitted slip with one of the fixed reasons. The order stays `awaiting_payment` and its deadline moves to
 * a full payment window from now so the customer has time to act. A slip that was short (`amount_mismatch`) keeps what it
 * showed in `slip_amount_satang`, which `Order::receivedSatang()` counts as money received. Lock order: order, payment.
 */
class RejectPayment
{
    /**
     * @param  ?string  $note  Required for `other`; ignored otherwise.
     * @param  ?int  $slipAmountSatang  Required for `amount_mismatch` (above zero, below the payment's amount); ignored
     *                                  for the other reasons so a slip that is not credited never counts as received.
     * @param  ?string  $slipRef  Stored with a short slip only, and refused when already credited on another payment.
     *
     * @throws PaymentRefused when the payment is no longer submitted or the order no longer waits for money.
     * @throws SlipRefAlreadyCredited
     * @throws InvalidArgumentException when the reason's own input is missing or out of range.
     */
    public function handle(Payment $payment, RejectReason $reason, ?string $note = null, ?int $slipAmountSatang = null, ?string $slipRef = null): Payment
    {
        $slipRef = Payment::normaliseSlipRef($slipRef);

        [$rejected, $order] = DB::transaction(function () use ($payment, $reason, $note, $slipAmountSatang, $slipRef): array {
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($locked->status !== PaymentStatus::Submitted) {
                throw new PaymentRefused('changed');
            }

            if ($order->status !== OrderStatus::AwaitingPayment) {
                throw new PaymentRefused('order_closed');
            }

            $text = $this->reasonText($locked, $reason, $note, $slipAmountSatang);
            $isShort = $reason === RejectReason::AmountMismatch;

            if ($isShort && $slipRef !== null && $order->slipRefCredited($slipRef, $locked->getKey())) {
                throw new SlipRefAlreadyCredited;
            }

            $locked->update([
                'status' => PaymentStatus::Rejected,
                'rejected_reason' => $text,
                'slip_amount_satang' => $isShort ? $slipAmountSatang : null,
                'slip_ref' => $isShort ? $slipRef : null,
            ]);

            $order->update(['payment_due_at' => now()->addMinutes((int) config('shop.payment_window_minutes'))]);

            return [$locked, $order];
        });

        Notification::route('mail', $order->contact_email)->notify(new PaymentRejected($order, $rejected));

        return $rejected;
    }

    /**
     * The sentence stored and shown to the customer verbatim. `amount_mismatch` is two lines: the heading, then what the
     * slip showed against what was due.
     */
    private function reasonText(Payment $payment, RejectReason $reason, ?string $note, ?int $slipAmountSatang): string
    {
        if ($reason === RejectReason::Other) {
            $note = trim((string) $note);

            if ($note === '') {
                throw new InvalidArgumentException('A reason is required when the reason is "other".');
            }

            return $note;
        }

        if ($reason !== RejectReason::AmountMismatch) {
            return __('admin.payment.reasons.'.$reason->value);
        }

        if ($slipAmountSatang === null || $slipAmountSatang <= 0 || $slipAmountSatang >= $payment->amount_satang) {
            throw new InvalidArgumentException('The slip amount must be above zero and below the amount due.');
        }

        return __('admin.payment.reasons.amount_mismatch')."\n".self::shortDetail($payment->amount_satang, $slipAmountSatang);
    }

    /**
     * "สลิปโอน ฿ 19,300 · ยอดที่ต้องชำระ ฿ 19,350 (ขาด ฿ 50)": also what the reject modal previews as staff type.
     */
    public static function shortDetail(int $dueSatang, int $slipSatang): string
    {
        return __('admin.payment.short_detail', [
            'slip' => Money::fromSatang($slipSatang)->format(),
            'due' => Money::fromSatang($dueSatang)->format(),
            'gap' => Money::fromSatang(max(0, $dueSatang - $slipSatang))->format(),
        ]);
    }
}

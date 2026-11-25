<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;

/**
 * The QR columns of a payment: the PromptPay payload for its amount and when that QR stops being offered. A QR never
 * outlives the order's payment window, and only PromptPay has one.
 */
final class PaymentQr
{
    /**
     * @return array{qr_payload: ?string, qr_expires_at: ?CarbonInterface}
     */
    public static function attributes(PaymentMethod $method, int $amountSatang, CarbonInterface $paymentDueAt): array
    {
        $promptPayId = (string) config('shop.promptpay_id');

        if ($method !== PaymentMethod::Promptpay || ! PromptPay::isValidId($promptPayId)) {
            return ['qr_payload' => null, 'qr_expires_at' => null];
        }

        return [
            'qr_payload' => PromptPay::payload($promptPayId, $amountSatang),
            'qr_expires_at' => self::expiry($paymentDueAt),
        ];
    }

    /**
     * `min(now + qr_minutes, payment_due_at)`.
     */
    public static function expiry(CarbonInterface $paymentDueAt): CarbonInterface
    {
        $expires = now()->addMinutes((int) config('shop.qr_minutes'));

        return $expires->greaterThan($paymentDueAt) ? $paymentDueAt->copy() : $expires;
    }
}

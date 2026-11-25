<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Builds the EMVCo merchant-presented payload a Thai banking app scans for PromptPay: a dynamic QR (the amount is
 * inside it) that pays one mobile number, national or tax id, or e-wallet id.
 */
final class PromptPay
{
    /** The PromptPay application id, tag 29's first sub-tag. */
    private const AID = 'A000000677010111';

    /**
     * @throws InvalidArgumentException when the id is not a mobile number, a 13-digit id or a 15-digit e-wallet id, or
     *                                  the amount is not above zero.
     */
    public static function payload(string $promptPayId, int $amountSatang): string
    {
        if ($amountSatang <= 0) {
            throw new InvalidArgumentException('A PromptPay amount must be above zero.');
        }

        $account = self::account(self::normalize($promptPayId));

        $body = self::field('00', '01')
            .self::field('01', '12')
            .self::field('29', self::field('00', self::AID).$account)
            .self::field('53', '764')
            .self::field('54', Money::fromSatang($amountSatang)->toDecimalString())
            .self::field('58', 'TH')
            .'6304';

        return $body.self::crc16($body);
    }

    /**
     * CRC-16/CCITT-FALSE (polynomial 0x1021, initial value 0xFFFF) as four uppercase hex digits.
     */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        foreach (str_split($data) as $character) {
            $crc ^= ord($character) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Digits only, with a Thai mobile number written `+66 81 234 5678`, `66812345678` or the wire form `0066812345678` turned into its 0-form.
     */
    public static function normalize(string $promptPayId): string
    {
        $digits = preg_replace('/\D/', '', $promptPayId) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '66')) {
            return '0'.substr($digits, 2);
        }

        // The wire form, `0066` and nine digits: a real 13-digit id never starts with `00`.
        if (strlen($digits) === 13 && str_starts_with($digits, '0066')) {
            return '0'.substr($digits, 4);
        }

        return $digits;
    }

    /**
     * Whether `payload()` would accept the id: a mobile number, a 13-digit id or a 15-digit e-wallet id.
     */
    public static function isValidId(string $promptPayId): bool
    {
        $digits = self::normalize($promptPayId);

        return (strlen($digits) === 10 && $digits[0] === '0') || in_array(strlen($digits), [13, 15], true);
    }

    /**
     * The id as people write it: `081-234-5678`, `1-2345-67890-12-3`; anything else as it was configured.
     */
    public static function format(string $promptPayId): string
    {
        $digits = self::normalize($promptPayId);

        return match (true) {
            strlen($digits) === 10 && $digits[0] === '0' => substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6),
            strlen($digits) === 13 => substr($digits, 0, 1).'-'.substr($digits, 1, 4).'-'.substr($digits, 5, 5).'-'.substr($digits, 10, 2).'-'.substr($digits, 12),
            default => $promptPayId,
        };
    }

    private static function account(string $digits): string
    {
        return match (true) {
            strlen($digits) === 10 && $digits[0] === '0' => self::field('01', '0066'.substr($digits, 1)),
            strlen($digits) === 13 => self::field('02', $digits),
            strlen($digits) === 15 => self::field('03', $digits),
            default => throw new InvalidArgumentException('That is not a PromptPay id.'),
        };
    }

    private static function field(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }
}

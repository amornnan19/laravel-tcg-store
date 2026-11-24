<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class Money
{
    /**
     * 9,999,999.99 baht, so a price always fits a 32-bit integer column.
     */
    public const MAX_SATANG = 999_999_999;

    private function __construct(private int $satang) {}

    public static function fromSatang(int $satang): self
    {
        return new self($satang);
    }

    /**
     * Parse a baht amount such as `4290` or `4290.5`. String math only, never a float.
     *
     * @throws InvalidArgumentException
     */
    public static function fromBaht(string $baht): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?\z/', $baht, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid baht amount [{$baht}].");
        }

        // Check the digit count first so an absurd value never overflows the int cast.
        if (strlen(ltrim($matches[1], '0')) > 7) {
            throw new InvalidArgumentException("Baht amount [{$baht}] is above the maximum of 9999999.99.");
        }

        $satang = ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');

        if ($satang > self::MAX_SATANG) {
            throw new InvalidArgumentException("Baht amount [{$baht}] is above the maximum of 9999999.99.");
        }

        return new self($satang);
    }

    public function satang(): int
    {
        return $this->satang;
    }

    /**
     * `4290` or `4290.50`, without a currency symbol.
     */
    public function toBahtString(): string
    {
        $sign = $this->satang < 0 ? '-' : '';
        $absolute = abs($this->satang);
        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;

        return $sign.$whole.($fraction === 0 ? '' : '.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT));
    }

    /**
     * `฿ 4,290` or `฿ 4,290.50`.
     */
    public function format(): string
    {
        $sign = $this->satang < 0 ? '-' : '';
        $absolute = abs($this->satang);
        $fraction = $absolute % 100;

        return '฿ '.$sign.number_format(intdiv($absolute, 100))
            .($fraction === 0 ? '' : '.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT));
    }
}

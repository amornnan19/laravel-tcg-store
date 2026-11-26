<?php

namespace App\Support;

use App\Models\BuylistPrice;

/**
 * The fingerprint of a buylist the customer was shown, so the submit can refuse when anything under it moved.
 */
final class BuylistQuote
{
    /**
     * A hash of every price row with what it is (printing, condition, finish), the unit prices (cash and credit) and the
     * quantity the customer saw. It is the whole `price id => (what, unit, qty)` map, never a total: two changes can
     * cancel out in a sum, and a row re-pointed at another card keeps its id and its prices.
     *
     * @param  iterable<array{price: BuylistPrice, qty: int}>  $lines
     */
    public static function signature(iterable $lines): string
    {
        return self::hash($lines, withQty: true);
    }

    /**
     * The same map without the quantities, so a refusal can tell a moved price from a quantity the quota cut.
     *
     * @param  iterable<array{price: BuylistPrice, qty: int}>  $lines
     */
    public static function pricesSignature(iterable $lines): string
    {
        return self::hash($lines, withQty: false);
    }

    /**
     * @param  iterable<array{price: BuylistPrice, qty: int}>  $lines
     */
    private static function hash(iterable $lines, bool $withQty): string
    {
        $parts = [];

        foreach ($lines as $line) {
            $price = $line['price'];

            $parts[$price->getKey()] = implode(':', [
                $price->getKey(),
                $price->printing_id,
                $price->condition->value,
                $price->finish->value,
                $price->cash_satang,
                $price->credit_satang,
                $withQty ? $line['qty'] : '-',
            ]);
        }

        ksort($parts);

        return hash('sha256', implode('|', $parts));
    }
}

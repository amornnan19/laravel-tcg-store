<?php

namespace App\Actions;

use App\Models\Variant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The mass edits behind the Variants spreadsheet. Every method saves through Eloquent in one
 * transaction and returns how many variants changed hands.
 */
class BulkEditVariants
{
    /**
     * The largest value of the integer `qty` column.
     */
    public const MAX_QTY = 2147483647;

    /**
     * @param  Collection<int, Variant>  $variants
     */
    public function setPrice(Collection $variants, Money $price): int
    {
        return $this->each($variants, fn (Variant $variant) => $variant->price_satang = $price->satang());
    }

    /**
     * Move every priced variant by a percentage given as a string such as `7.5` or `-10`, in basis
     * points with integer math: `new = intdiv(price * (10000 + bps) + 5000, 10000)`, never below 0
     * and never above the largest price. Unpriced variants are left alone.
     *
     * @param  Collection<int, Variant>  $variants
     */
    public function adjustPriceByPercent(Collection $variants, string $percent): int
    {
        $basisPoints = self::basisPoints($percent);

        return $this->each($variants, function (Variant $variant) use ($basisPoints): void {
            $adjusted = intdiv($variant->price_satang * (10000 + $basisPoints) + 5000, 10000);

            $variant->price_satang = min(max(0, $adjusted), Money::MAX_SATANG);
        }, only: fn (Variant $variant): bool => $variant->price_satang !== null);
    }

    /**
     * @param  Collection<int, Variant>  $variants
     */
    public function setQty(Collection $variants, int $qty): int
    {
        return $this->each($variants, fn (Variant $variant) => $variant->qty = min(max(0, $qty), self::MAX_QTY));
    }

    /**
     * Add a whole number that may be negative; the result stays between 0 and the column maximum.
     *
     * @param  Collection<int, Variant>  $variants
     */
    public function addQty(Collection $variants, int $delta): int
    {
        return $this->each($variants, fn (Variant $variant) => $variant->qty = min(max(0, $variant->qty + $delta), self::MAX_QTY));
    }

    /**
     * @param  Collection<int, Variant>  $variants
     */
    public function markRealPhotoTaken(Collection $variants): int
    {
        return $this->each($variants, fn (Variant $variant) => $variant->has_real_photo = true);
    }

    /**
     * `7.5` becomes `750`, `-10` becomes `-1000`. String math only, never a float.
     *
     * @throws InvalidArgumentException
     */
    public static function basisPoints(string $percent): int
    {
        if (preg_match('/^(-?)(\d{1,5})(?:\.(\d{1,2}))?\z/', trim($percent), $matches) !== 1) {
            throw new InvalidArgumentException("Invalid percentage [{$percent}].");
        }

        $basisPoints = ((int) $matches[2]) * 100 + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$basisPoints : $basisPoints;
    }

    /**
     * @param  Collection<int, Variant>  $variants
     * @param  callable(Variant): mixed  $change
     * @param  (callable(Variant): bool)|null  $only
     */
    private function each(Collection $variants, callable $change, ?callable $only = null): int
    {
        return DB::transaction(function () use ($variants, $change, $only): int {
            $changed = 0;

            foreach ($variants as $variant) {
                if ($only !== null && ! $only($variant)) {
                    continue;
                }

                $change($variant);
                $variant->save();
                $changed++;
            }

            return $changed;
        });
    }
}

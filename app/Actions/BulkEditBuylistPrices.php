<?php

namespace App\Actions;

use App\Exceptions\BuylistPriceInvalid;
use App\Models\BuylistPrice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The mass edits behind the Buy prices list. Every method locks the selected rows in ascending id order, saves them in
 * that order in one transaction and returns how many rows changed hands: the table lists newest first, and `SubmitBuylist`
 * locks the same rows ascending, so any other order can deadlock against it. `accepted_this_week` is never touched here:
 * only submissions and the weekly reset write it.
 */
class BulkEditBuylistPrices
{
    /**
     * Sets the given fields on every row; a null leaves that field alone. `$clearQuota` removes the cap and wins over `$quota`.
     *
     * @param  Collection<int, BuylistPrice>  $prices
     *
     * @throws BuylistPriceInvalid when a row would end with no cash price or a credit price below its cash price; nothing is saved.
     */
    public function edit(Collection $prices, ?int $cashSatang, ?int $creditSatang, ?int $weeklyQuota, bool $clearQuota, ?bool $isActive): int
    {
        return $this->each($prices, function (BuylistPrice $price) use ($cashSatang, $creditSatang, $weeklyQuota, $clearQuota, $isActive): void {
            if ($cashSatang !== null) {
                $price->cash_satang = $cashSatang;
            }

            if ($creditSatang !== null) {
                $price->credit_satang = $creditSatang;
            }

            if ($clearQuota) {
                $price->weekly_quota = null;
            } elseif ($weeklyQuota !== null) {
                $price->weekly_quota = $weeklyQuota;
            }

            if ($isActive !== null) {
                $price->is_active = $isActive;
            }
        });
    }

    /**
     * Credit = cash + the configured bonus, in whole baht, on every row.
     *
     * @param  Collection<int, BuylistPrice>  $prices
     *
     * @throws BuylistPriceInvalid when a row would end with no cash price or a credit price below its cash price.
     */
    public function setCreditFromCash(Collection $prices): int
    {
        return $this->each($prices, fn (BuylistPrice $price) => $price->credit_satang = BuylistPrice::creditFor($price->cash_satang));
    }

    /**
     * @param  Collection<int, BuylistPrice>  $prices
     * @param  callable(BuylistPrice): mixed  $change
     */
    private function each(Collection $prices, callable $change): int
    {
        return DB::transaction(function () use ($prices, $change): int {
            // Locked fresh, ascending, before anything is written: the rows handed in were read before the lock.
            $locked = BuylistPrice::query()
                ->whereKey($prices->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->with(['printing.set', 'printing.cardWithTrashed'])
                ->get();

            foreach ($locked as $price) {
                $change($price);

                if ($price->cash_satang < 1 || $price->credit_satang < $price->cash_satang) {
                    throw new BuylistPriceInvalid($price->label());
                }

                $price->save();
            }

            return $locked->count();
        });
    }
}

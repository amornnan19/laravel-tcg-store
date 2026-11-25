<?php

namespace App\Support;

use App\Jobs\CheckVariantAlerts;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Asks for the alerts of a variant to be checked whenever its available stock or price may have changed. The job is
 * queued after the surrounding transaction commits, so it never reads a state that is rolled back.
 */
final class VariantAlerts
{
    /**
     * @param  iterable<int|string>  $variantIds
     */
    public static function dispatchFor(iterable $variantIds): void
    {
        collect($variantIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            // Not `->afterCommit()`: that takes the unique lock now, inside the open transaction, so a check already running
            // would swallow this change. The dispatch itself waits for the commit and runs at once outside a transaction.
            ->each(fn (int $id) => DB::afterCommit(fn () => CheckVariantAlerts::dispatch($id)));
    }

    /**
     * For a bulk change that can make many variants buyable again (a game switched on, a printing or card restored):
     * checks only the variants somebody is waiting on, reading ids in chunks and never loading the models.
     *
     * @param  Builder<Variant>  $variants
     */
    public static function dispatchForWaiting(Builder $variants): void
    {
        $variants
            ->where(fn (Builder $waiting) => $waiting
                ->whereHas('restockAlerts', fn (Builder $alerts) => $alerts->where('is_active', true))
                ->orWhereHas('priceAlerts', fn (Builder $alerts) => $alerts->where('is_active', true)))
            ->select('variants.id')
            ->chunkById(500, fn ($chunk) => self::dispatchFor($chunk->pluck('id')), 'variants.id', 'id');
    }
}

<?php

namespace App\Jobs;

use App\Models\StockReservation;
use App\Models\Variant;
use App\Support\VariantAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes the stock holds whose time ran out. Housekeeping only: availability filters on `expires_at`, so it is
 * correct whether or not this has run. It also tells the alerts that the variants those holds covered may be
 * available again, since nothing else notices a hold expiring.
 */
class ReleaseExpiredReservations implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $now = now();

        $expired = StockReservation::query()
            ->where('expires_at', '<=', $now)
            ->get(['id', 'purchasable_type', 'purchasable_id']);

        if ($expired->isEmpty()) {
            return;
        }

        // The expiry stays on the DELETE: ReserveStock renews an expired row in place with the same id, and a hold renewed since
        // the read above must survive.
        StockReservation::query()->whereKey($expired->modelKeys())->where('expires_at', '<=', $now)->delete();

        VariantAlerts::dispatchFor(
            $expired->where('purchasable_type', (new Variant)->getMorphClass())->pluck('purchasable_id')
        );
    }
}

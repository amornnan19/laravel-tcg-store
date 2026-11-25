<?php

namespace App\Jobs;

use App\Models\StockReservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes the stock holds whose time ran out. Housekeeping only: availability filters on `expires_at`, so it is
 * correct whether or not this has run.
 */
class ReleaseExpiredReservations implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        StockReservation::query()->where('expires_at', '<=', now())->delete();
    }
}

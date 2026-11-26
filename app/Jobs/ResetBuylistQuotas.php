<?php

namespace App\Jobs;

use App\Models\BuylistPrice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts the buylist's week: every weekly quota is whole again. Scheduled for Monday 00:00 shop time.
 */
class ResetBuylistQuotas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        BuylistPrice::query()->where('accepted_this_week', '>', 0)->update(['accepted_this_week' => 0]);
    }
}

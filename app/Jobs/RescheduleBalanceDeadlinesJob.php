<?php

namespace App\Jobs;

use App\Actions\ReschedulePreorderBalanceDeadlines;
use App\Models\Preorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gives the balances already asked for on a pre-order their new deadline after staff moved its release date, and tells the
 * customers. Queued from the panel's `afterCommit`, so a failure part way cannot break the save that already committed; a
 * retry is safe because the action skips every order already at its target and sends no second message.
 */
class RescheduleBalanceDeadlinesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $preorderId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ReschedulePreorderBalanceDeadlines $reschedule): void
    {
        $preorder = Preorder::query()->find($this->preorderId);

        if ($preorder !== null) {
            $reschedule->handle($preorder);
        }
    }
}

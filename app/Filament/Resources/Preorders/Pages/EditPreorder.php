<?php

namespace App\Filament\Resources\Preorders\Pages;

use App\Filament\Resources\Preorders\PreorderResource;
use App\Jobs\RescheduleBalanceDeadlinesJob;
use App\Models\Preorder;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditPreorder extends EditRecord
{
    protected static string $resource = PreorderResource::class;

    /**
     * A moved release date moves the deadline of every balance already asked for. This hook runs inside the panel's save
     * transaction with the pre-order row already locked, and the action locks orders: after the commit, so the locks
     * are never taken in the reverse order of `CancelOrder`, and queued, so a failure part way cannot unwind the hook that
     * runs inside `DB::commit()`.
     */
    protected function afterSave(): void
    {
        /** @var Preorder $preorder */
        $preorder = $this->getRecord();

        if ($preorder->wasChanged('release_on')) {
            DB::afterCommit(fn () => RescheduleBalanceDeadlinesJob::dispatch($preorder->getKey()));
        }
    }
}

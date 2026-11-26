<?php

namespace App\Actions;

use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use App\Exceptions\BuylistRefused;
use App\Models\BuylistSubmission;
use App\Models\User;
use App\Notifications\BuylistPaid;
use Illuminate\Support\Facades\DB;

/**
 * Staff have sent the cash: an `accepted` cash submission becomes `paid`, with the transfer's reference.
 */
class MarkBuylistPaid
{
    /**
     * @throws BuylistRefused when the actor is not staff, the reference is missing, or the submission is not an accepted cash one.
     */
    public function handle(BuylistSubmission $submission, string $reference, User $staff): BuylistSubmission
    {
        $reference = trim($reference);

        if ($staff->is_staff !== true) {
            throw new BuylistRefused('not_staff');
        }

        if ($reference === '' || mb_strlen($reference) > 255) {
            throw new BuylistRefused('reference_required');
        }

        $paid = DB::transaction(function () use ($submission, $reference): BuylistSubmission {
            $locked = BuylistSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());

            if ($locked->status !== BuylistStatus::Accepted || $locked->payout !== BuylistPayout::Cash) {
                throw new BuylistRefused('illegal_status');
            }

            $locked->update(['status' => BuylistStatus::Paid, 'payout_reference' => $reference, 'paid_at' => now()]);

            return $locked;
        });

        $paid->user->notify(new BuylistPaid($paid));

        return $paid;
    }
}

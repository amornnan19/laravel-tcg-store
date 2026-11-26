<?php

namespace App\Actions;

use App\Enums\BuylistStatus;
use App\Exceptions\BuylistRefused;
use App\Models\BuylistSubmission;
use Illuminate\Support\Facades\DB;

/**
 * The parcel has arrived: `submitted` becomes `received`. A parcel that comes after the quote ran out is still paid at
 * the quoted price, so staff must say why (`late_note`).
 */
class ReceiveBuylistSubmission
{
    /**
     * @throws BuylistRefused when the submission is not `submitted`, or arrived late and has no reason.
     */
    public function handle(BuylistSubmission $submission, ?string $lateNote = null): BuylistSubmission
    {
        $lateNote = filled($lateNote) ? trim($lateNote) : null;

        return DB::transaction(function () use ($submission, $lateNote): BuylistSubmission {
            $locked = BuylistSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());

            if ($locked->status !== BuylistStatus::Submitted) {
                throw new BuylistRefused('illegal_status');
            }

            $isLate = now()->gt($locked->quote_expires_at);

            if ($isLate && $lateNote === null) {
                throw new BuylistRefused('late_note_required');
            }

            $locked->update([
                'status' => BuylistStatus::Received,
                'received_at' => now(),
                'late_note' => $isLate ? $lateNote : null,
            ]);

            return $locked;
        });
    }
}

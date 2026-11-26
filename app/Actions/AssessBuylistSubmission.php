<?php

namespace App\Actions;

use App\Enums\BuylistStatus;
use App\Enums\CardCondition;
use App\Exceptions\BuylistRefused;
use App\Models\BuylistPrice;
use App\Models\BuylistSubmission;
use App\Models\BuylistSubmissionItem;
use App\Notifications\BuylistRevised;
use App\Support\BuylistAssessment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records what the shop found when it opened the parcel, line by line, and works out the new total. When any line is
 * worth less than it was quoted the submission goes to `revised` and the customer is asked; otherwise it stays
 * `received`, ready to accept. Lock order: the buy price rows (a lower condition is priced from them), then the
 * submission.
 */
class AssessBuylistSubmission
{
    /**
     * @param  list<array{item_id: int, assessed_condition: CardCondition|string, assessed_note?: ?string, photo_path?: ?string}>  $assessments  One entry per line.
     * @param  ?string  $expectedFingerprint  `BuylistAssessment::fingerprint()` of what the form showed; a different one is a stale save.
     *
     * @throws BuylistRefused when the status is not `received`, the form is stale, a line is missing, a condition is above the claimed one, or a lowered line has no photo.
     */
    public function handle(BuylistSubmission $submission, array $assessments, ?string $expectedFingerprint = null): BuylistSubmission
    {
        // Only to decide which price rows to lock: the lines themselves are read again under the lock.
        $printingIds = $submission->items()->pluck('printing_id')->unique()->sort()->values()->all();

        $locked = DB::transaction(function () use ($submission, $assessments, $expectedFingerprint, $printingIds): BuylistSubmission {
            BuylistPrice::query()->whereIn('printing_id', $printingIds)->orderBy('id')->lockForUpdate()->get(['id']);

            $locked = BuylistSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());

            if ($locked->status !== BuylistStatus::Received) {
                throw new BuylistRefused('illegal_status');
            }

            // A current read of the lines, after the submission's lock: the fingerprint and the completeness check judge
            // what is there now, not a snapshot from before the lock (MySQL repeatable read).
            $items = $locked->items()->lockForUpdate()->get();
            $locked->setRelation('items', $items);

            if ($expectedFingerprint !== null && ! hash_equals(BuylistAssessment::fingerprint($locked), $expectedFingerprint)) {
                throw new BuylistRefused('stale');
            }

            $byItem = collect($assessments)->keyBy(fn (array $row): int => (int) $row['item_id']);

            if ($byItem->count() !== $items->count() || $items->contains(fn (BuylistSubmissionItem $item): bool => ! $byItem->has($item->getKey()))) {
                throw new BuylistRefused('incomplete');
            }

            foreach ($items as $item) {
                $item->setRelation('submission', $locked);
                $this->assessLine($item, $byItem[$item->getKey()]);
            }

            $locked->load('items');

            $reduced = BuylistAssessment::isReduced($locked);

            $locked->update([
                'final_total_satang' => BuylistAssessment::finalTotal($locked),
                'status' => $reduced ? BuylistStatus::Revised : BuylistStatus::Received,
                'revised_at' => $reduced ? now() : null,
            ]);

            return $locked;
        });

        if ($locked->status === BuylistStatus::Revised) {
            $locked->user->notify(new BuylistRevised($locked));
        }

        return $locked;
    }

    /**
     * @param  array{item_id: int, assessed_condition: CardCondition|string, assessed_note?: ?string, photo_path?: ?string}  $row
     */
    private function assessLine(BuylistSubmissionItem $item, array $row): void
    {
        $condition = $row['assessed_condition'] instanceof CardCondition ? $row['assessed_condition'] : CardCondition::tryFrom((string) $row['assessed_condition']);

        if ($condition === null) {
            throw new BuylistRefused('incomplete');
        }

        try {
            $unit = BuylistAssessment::unitFor($item, $condition);
        } catch (InvalidArgumentException) {
            throw new BuylistRefused('condition_above_claimed');
        }

        $isLower = BuylistAssessment::isLower($item->claimed_condition, $condition);
        $photo = filled($row['photo_path'] ?? null) ? (string) $row['photo_path'] : null;

        if ($isLower && $photo === null) {
            throw new BuylistRefused('photo_required');
        }

        // The path is written to a row, so the file must be there, and in the folder this feature writes to.
        if ($photo !== null && (! str_starts_with($photo, 'buylist-photos/') || ! Storage::disk('public')->exists($photo))) {
            throw new BuylistRefused('photo_missing');
        }

        $note = filled($row['assessed_note'] ?? null) ? trim((string) $row['assessed_note']) : null;

        if ($isLower && $unit === 0) {
            $note = $note === null ? __('admin.buylist.not_bought') : $note.' · '.__('admin.buylist.not_bought');
        }

        $item->update([
            'assessed_condition' => $condition,
            'assessed_unit_satang' => $unit,
            'assessed_line_satang' => $unit * $item->qty,
            'assessed_note' => $note === null ? null : Str::limit($note, 255, ''),
            // A card that is not below its claim needs no evidence.
            'photo_path' => $isLower ? $photo : null,
        ]);
    }
}

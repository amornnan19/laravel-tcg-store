<?php

namespace App\Support;

use App\Enums\CardCondition;
use App\Models\BuylistPrice;
use App\Models\BuylistSubmission;
use App\Models\BuylistSubmissionItem;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What a card is worth once the shop has graded it. The claimed condition keeps the price that was quoted; a lower one
 * gets today's buy price for that condition, never above the quote; a condition the shop does not buy is worth nothing.
 */
final class BuylistAssessment
{
    /**
     * The unit the shop pays for one card of `$item` if it is graded `$assessed`.
     *
     * @throws InvalidArgumentException when `$assessed` is better than the condition the customer claimed.
     */
    public static function unitFor(BuylistSubmissionItem $item, CardCondition $assessed): int
    {
        if ($assessed === $item->claimed_condition) {
            return $item->quoted_unit_satang;
        }

        if (! self::isLower($item->claimed_condition, $assessed)) {
            throw new InvalidArgumentException('Staff cannot assess a card above the condition the customer claimed.');
        }

        $price = BuylistPrice::query()
            ->buyable()
            ->where('printing_id', $item->printing_id)
            ->where('condition', $assessed->value)
            ->where('finish', $item->finish->value)
            ->first();

        return $price === null ? 0 : min($price->unitFor($item->submission->payout), $item->quoted_unit_satang);
    }

    /**
     * Whether `$assessed` ranks below `$claimed` (`CardCondition::sort()`: 1 is the best).
     */
    public static function isLower(CardCondition $claimed, CardCondition $assessed): bool
    {
        return $assessed->sort() > $claimed->sort();
    }

    /**
     * The conditions staff may pick for a card claimed as `$claimed`: that one and the ones below it, never above.
     *
     * @return list<CardCondition>
     */
    public static function optionsFor(CardCondition $claimed): array
    {
        return array_values(array_filter(
            CardCondition::cases(),
            fn (CardCondition $condition): bool => $condition->sort() >= $claimed->sort(),
        ));
    }

    /**
     * Whether any line is worth less than it was quoted: the test that sends a submission to the customer.
     */
    public static function isReduced(BuylistSubmission $submission): bool
    {
        return $submission->items->contains(
            fn (BuylistSubmissionItem $item): bool => $item->assessed_line_satang !== null && $item->assessed_line_satang < $item->quoted_line_satang
        );
    }

    /**
     * The lines the shop found worth nothing: they are not taken into stock and go back with the parcel.
     *
     * @return Collection<int, BuylistSubmissionItem>
     */
    public static function unboughtLines(BuylistSubmission $submission): Collection
    {
        return $submission->items->filter(fn (BuylistSubmissionItem $item): bool => $item->assessed_line_satang === 0)->values();
    }

    /**
     * The total the shop pays: assessed lines where there is an assessment, quoted ones where there is not.
     */
    public static function finalTotal(BuylistSubmission $submission): int
    {
        return (int) $submission->items->sum(fn (BuylistSubmissionItem $item): int => $item->assessed_line_satang ?? $item->quoted_line_satang);
    }

    /**
     * What the assessment form last showed, so a save made from an older page is refused.
     */
    public static function fingerprint(BuylistSubmission $submission): string
    {
        return hash('sha256', json_encode([
            $submission->status->value,
            $submission->final_total_satang,
            $submission->items->map(fn (BuylistSubmissionItem $item): array => [
                $item->getKey(), $item->assessed_condition?->value, $item->assessed_unit_satang, $item->assessed_note, $item->photo_path,
            ])->all(),
        ], JSON_THROW_ON_ERROR));
    }
}

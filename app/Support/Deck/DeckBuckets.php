<?php

namespace App\Support\Deck;

use App\Enums\DeckBucket;
use App\Enums\DeckLineKind;

/**
 * Sorts a matched line into a bucket by the variant it is filled from. The one rule, used for the defaults after a
 * parse and again when the customer picks another variant.
 *
 * Full: the variant covers the quantity. Partial: it has some, not enough. Missing: nothing available, or the line
 * did not match a card at all.
 */
final class DeckBuckets
{
    /**
     * `$chosenVariantId` is the customer's pick; null, an id that is not a candidate or one that is sold out means the default: the
     * cheapest variant that covers the quantity, else the one with the most available (cheapest on a tie).
     */
    public static function assign(DeckLine $line, ?int $chosenVariantId): DeckLine
    {
        if ($line->kind === DeckLineKind::Unmatched) {
            return $line->withChoice(null, DeckBucket::Missing);
        }

        if ($line->kind !== DeckLineKind::Matched) {
            return $line;
        }

        $chosen = $chosenVariantId === null ? null : ($line->variants[$chosenVariantId] ?? null);

        // A pick that sold out since it was made is no pick: the line falls back to the default.
        if ($chosen === null || $chosen->availableQty < 1) {
            $chosen = self::defaultVariant($line);
        }

        if ($chosen === null) {
            return $line->withChoice(null, DeckBucket::Missing);
        }

        $bucket = $chosen->availableQty >= $line->qty ? DeckBucket::Full : DeckBucket::Partial;

        return $line->withChoice($chosen->id, $bucket);
    }

    private static function defaultVariant(DeckLine $line): ?DeckVariant
    {
        $available = array_filter($line->variants, fn (DeckVariant $variant): bool => $variant->availableQty > 0);

        $covering = array_filter($available, fn (DeckVariant $variant): bool => $variant->availableQty >= $line->qty);

        if ($covering !== []) {
            usort($covering, fn (DeckVariant $a, DeckVariant $b): int => [$a->priceSatang, $a->id] <=> [$b->priceSatang, $b->id]);

            return $covering[0];
        }

        usort($available, fn (DeckVariant $a, DeckVariant $b): int => [$b->availableQty, $a->priceSatang, $a->id] <=> [$a->availableQty, $b->priceSatang, $b->id]);

        return $available[0] ?? null;
    }
}

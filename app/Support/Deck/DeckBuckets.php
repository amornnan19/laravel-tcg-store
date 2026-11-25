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

    /**
     * Assigns every line of a list at once, in paste order, so lines that land on one variant share its stock: each takes
     * its copies from what the lines before it left, and a line that no longer fits moves to another variant that
     * covers it, or becomes partial at the remainder. A variant also gives at most `deck.max_qty` copies over all lines.
     * A partial line the customer switched off takes nothing, so it leaves its stock for the lines after it.
     *
     * `$choices` is the customer's picks, `line number => ['variant' => ?int, 'partial' => bool]`. A pick with nothing
     * left (sold out, or taken by an earlier line) is no pick: the line falls back to the default.
     *
     * @param  list<DeckLine>  $lines
     * @param  array<int, array{variant?: ?int, partial?: bool}>  $choices
     * @return list<DeckLine>
     */
    public static function allocate(array $lines, array $choices = []): array
    {
        $max = (int) config('deck.max_qty');
        $used = [];
        $assigned = [];

        foreach ($lines as $line) {
            if ($line->kind !== DeckLineKind::Matched) {
                $assigned[] = self::assign($line, null);

                continue;
            }

            $choice = $choices[$line->number] ?? [];
            $left = fn (DeckVariant $variant): int => max(0, min($variant->availableQty, $max) - ($used[$variant->id] ?? 0));

            $picked = isset($choice['variant']) ? ($line->variants[$choice['variant']] ?? null) : null;
            $chosen = $picked !== null && $left($picked) >= 1 ? $picked : self::defaultAllocated($line, $left);
            $takePartial = $choice['partial'] ?? true;

            $remaining = [];

            foreach ($line->variants as $variant) {
                $remaining[$variant->id] = $left($variant);
            }

            if ($chosen === null) {
                // Missing, but is it the shop's stock or the lines before this one? Something in stock that these lines
                // already took (or that the per-variant cap now closes) is not a sold-out card.
                $inStock = array_filter($line->variants, fn (DeckVariant $variant): bool => $variant->availableQty > 0);
                $capped = array_filter($inStock, fn (DeckVariant $variant): bool => $variant->availableQty - ($used[$variant->id] ?? 0) > 0 && $left($variant) < 1);

                $assigned[] = $line->withChoice(null, DeckBucket::Missing, 0, $capped !== [], $inStock !== [], $remaining)->withTakePartial($takePartial);

                continue;
            }

            $room = $left($chosen);
            $allocated = min($line->qty, $room);
            $bucket = $allocated >= $line->qty ? DeckBucket::Full : DeckBucket::Partial;
            $stockLeft = max(0, $chosen->availableQty - ($used[$chosen->id] ?? 0));

            if ($bucket === DeckBucket::Full || $takePartial) {
                $used[$chosen->id] = ($used[$chosen->id] ?? 0) + $allocated;
            }

            $assigned[] = $line->withChoice($chosen->id, $bucket, $allocated, $room < min($line->qty, $stockLeft), false, $remaining)->withTakePartial($takePartial);
        }

        return $assigned;
    }

    /**
     * @param  callable(DeckVariant): int  $left
     */
    private static function defaultAllocated(DeckLine $line, callable $left): ?DeckVariant
    {
        $available = array_filter($line->variants, fn (DeckVariant $variant): bool => $left($variant) > 0);

        $covering = array_filter($available, fn (DeckVariant $variant): bool => $left($variant) >= $line->qty);

        if ($covering !== []) {
            usort($covering, fn (DeckVariant $a, DeckVariant $b): int => [$a->priceSatang, $a->id] <=> [$b->priceSatang, $b->id]);

            return $covering[0];
        }

        usort($available, fn (DeckVariant $a, DeckVariant $b): int => [$left($b), $a->priceSatang, $a->id] <=> [$left($a), $b->priceSatang, $b->id]);

        return $available[0] ?? null;
    }
}

<?php

namespace App\Support\Deck;

use App\Enums\DeckBucket;
use App\Enums\DeckLineKind;

/**
 * The result of reading a deck list: every line, in paste order.
 */
final readonly class DeckParse
{
    /**
     * @param  list<DeckLine>  $lines
     */
    public function __construct(public array $lines) {}

    /**
     * The lines of one bucket. An unmatched line belongs to `Missing`.
     *
     * @return list<DeckLine>
     */
    public function inBucket(DeckBucket $bucket): array
    {
        return array_values(array_filter($this->lines, fn (DeckLine $line): bool => $line->bucket === $bucket));
    }

    /**
     * Lines the importer chose not to read (section headers, comments, `Set:` lines), shown quietly at the end.
     *
     * @return list<DeckLine>
     */
    public function unread(): array
    {
        return array_values(array_filter($this->lines, fn (DeckLine $line): bool => in_array($line->kind, [DeckLineKind::Ignored, DeckLineKind::Directive], true)));
    }

    /**
     * Lines per bucket.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (DeckBucket::cases() as $bucket) {
            $counts[$bucket->value] = count($this->inBucket($bucket));
        }

        return $counts;
    }

    /**
     * The copies the card lines ask for, readable or not.
     */
    public function totalRequested(): int
    {
        return array_sum(array_map(fn (DeckLine $line): int => in_array($line->kind, [DeckLineKind::Matched, DeckLineKind::Unmatched], true) ? $line->qty : 0, $this->lines));
    }

    /**
     * The copies adding the list would put in the cart.
     */
    public function totalCards(): int
    {
        return array_sum(array_map(fn (DeckLine $line): int => $line->addQty(), $this->lines));
    }

    /**
     * Satang: what those copies cost at the current prices.
     */
    public function totalSatang(): int
    {
        return array_sum(array_map(fn (DeckLine $line): int => $line->addQty() * ($line->chosenVariant()?->priceSatang ?? 0), $this->lines));
    }
}

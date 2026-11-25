<?php

namespace App\Support\Deck;

/**
 * Which card each line of a deck list names, and nothing about stock: the outcome of the expensive half of parsing
 * (reading, code and name matching, the search engine). Plain arrays, so a component can keep it in locked state and
 * price it again on every request without matching again.
 *
 * @phpstan-type Record array{number: int, text: string, body: string, kind: string, qty: int, qtyCapped: bool, qtyZero: bool, matchedBy: ?string, printingIds: list<int>, ambiguous: bool, matchedName: ?string, matchedCode: ?string}
 */
final readonly class DeckMatch
{
    /**
     * @param  list<Record>  $lines  In paste order.
     */
    public function __construct(public array $lines) {}

    /**
     * @return list<Record>
     */
    public function toArray(): array
    {
        return $this->lines;
    }

    /**
     * @param  list<Record>  $lines
     */
    public static function fromArray(array $lines): self
    {
        return new self($lines);
    }
}

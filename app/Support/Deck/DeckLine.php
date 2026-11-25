<?php

namespace App\Support\Deck;

use App\Enums\DeckBucket;
use App\Enums\DeckLineKind;
use App\Enums\DeckMatchedBy;

/**
 * One line of a pasted deck list and what became of it. Immutable: choosing another variant or flipping the
 * "take what is there" toggle returns a changed copy.
 */
final readonly class DeckLine
{
    /**
     * @param  int  $number  The line's number in the paste, counting blank lines.
     * @param  string  $text  The line as pasted, trimmed.
     * @param  list<int>  $printingIds  The visible printings the match found, before the language preference.
     * @param  array<int, DeckVariant>  $variants  Every purchasable candidate variant, `variant id => variant`, sold out ones included.
     * @param  ?int  $defaultVariantId  The variant the line is filled from; null when nothing is available.
     * @param  bool  $takePartial  Whether a partial line is added with what is available.
     * @param  bool  $ambiguous  Whether the name matched more than one distinct card.
     * @param  int  $otherLanguagesAvailable  Available variants the language preference left out.
     * @param  string  $body  The line without its quantity.
     * @param  bool  $qtyZero  Whether the line asked for 0 copies, which is not a card line.
     */
    public function __construct(
        public int $number,
        public string $text,
        public DeckLineKind $kind,
        public int $qty = 0,
        public bool $qtyCapped = false,
        public ?DeckMatchedBy $matchedBy = null,
        public array $printingIds = [],
        public ?string $matchedName = null,
        public ?string $matchedCode = null,
        public array $variants = [],
        public ?int $defaultVariantId = null,
        public ?DeckBucket $bucket = null,
        public bool $takePartial = true,
        public bool $ambiguous = false,
        public int $otherLanguagesAvailable = 0,
        public string $body = '',
        public bool $qtyZero = false,
    ) {}

    /**
     * Whether the row should show the pasted text: always for a name or fuzzy match, and for a code match only when
     * the line says more than the code and a quantity.
     */
    public function showsPastedText(): bool
    {
        if ($this->matchedBy === DeckMatchedBy::Name || $this->matchedBy === DeckMatchedBy::Fuzzy) {
            return true;
        }

        $squash = fn (string $text): string => preg_replace('/[^a-z0-9]/', '', mb_strtolower($text)) ?? '';
        $code = $squash((string) $this->matchedCode);
        $text = $squash($this->text);

        return ! in_array($text, [$code, $this->qty.$code, $this->qty.'x'.$code, $code.'x'.$this->qty], true);
    }

    public function chosenVariant(): ?DeckVariant
    {
        return $this->defaultVariantId === null ? null : ($this->variants[$this->defaultVariantId] ?? null);
    }

    public function isFuzzy(): bool
    {
        return $this->matchedBy === DeckMatchedBy::Fuzzy;
    }

    /**
     * How many copies adding the list would put in the cart for this line.
     */
    public function addQty(): int
    {
        $available = $this->chosenVariant()?->availableQty ?? 0;

        return match ($this->bucket) {
            DeckBucket::Full => $this->qty,
            DeckBucket::Partial => $this->takePartial ? min($this->qty, $available) : 0,
            default => 0,
        };
    }

    public function withChoice(?int $variantId, ?DeckBucket $bucket): self
    {
        return $this->copy(['defaultVariantId' => $variantId, 'bucket' => $bucket]);
    }

    public function withTakePartial(bool $takePartial): self
    {
        return $this->copy(['takePartial' => $takePartial]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function copy(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}

<?php

namespace App\Support\Deck;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;

/**
 * One purchasable variant a deck line could be filled from, with what the page needs to name it and to decide how
 * much of the line it covers. Plain values only, so a parse result carries no models.
 */
final readonly class DeckVariant
{
    public function __construct(
        public int $id,
        public int $printingId,
        public int $availableQty,
        public int $priceSatang,
        public CardCondition $condition,
        public Finish $finish,
        public Language $language,
        public string $name,
        public string $code,
    ) {}
}

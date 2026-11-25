<?php

namespace App\Search;

use App\Exceptions\FuzzySearchUnavailable;
use App\Models\Game;

/**
 * Finds the card a misspelt or abbreviated name most likely means. Behind an interface so tests bind a fake and the
 * deck-list importer never depends on a running search engine.
 */
interface FuzzyCardMatcher
{
    /**
     * The best candidate for a name within one game, or null when there is none. The score is the engine's own 0 to 1
     * ranking score; the caller still applies its floor.
     *
     * @return ?array{card_id: int, score: float}
     *
     * @throws FuzzySearchUnavailable when the engine could not answer.
     */
    public function match(Game $game, string $name): ?array;
}

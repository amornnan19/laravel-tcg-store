<?php

namespace App\Search;

use App\Exceptions\FuzzySearchUnavailable;
use App\Models\Game;
use App\Models\Printing;
use App\Support\Deck\NameKey;
use Throwable;

/**
 * The printings index, searched with `showRankingScore`, then checked against the card's own names.
 *
 * A ranking score alone is not a name match: a query with no word tokens is a placeholder search that scores 1.0, and a
 * one-word prefix (`ex`, `Zoro`) scores about 0.98. So a hit counts only when the query is close to one of the hit's
 * names (`deck.fuzzy_min_similarity`, a normalised Levenshtein over code points), and the ranking score is just a
 * floor (`deck.fuzzy_min_score`) that spares the comparison on poor hits. The best of the first few hits wins.
 */
class MeilisearchFuzzyCardMatcher implements FuzzyCardMatcher
{
    private const HITS = 5;

    public function match(Game $game, string $name): ?array
    {
        if (! NameKey::hasWordCharacters($name)) {
            return null;
        }

        try {
            $raw = Printing::search($name)
                ->options(['showRankingScore' => true])
                ->where('game', $game->slug)
                ->take(self::HITS)
                ->raw();
        } catch (Throwable $exception) {
            report($exception);

            throw new FuzzySearchUnavailable($exception->getMessage(), previous: $exception);
        }

        $query = NameKey::of($name);
        $best = null;
        $bar = (float) config('deck.fuzzy_min_similarity');
        $bestSimilarity = $bar;

        foreach (is_array($raw) ? ($raw['hits'] ?? []) : [] as $hit) {
            if (! is_array($hit) || ! isset($hit['card_id'], $hit['_rankingScore']) || (float) $hit['_rankingScore'] < (float) config('deck.fuzzy_min_score')) {
                continue;
            }

            foreach (['name_en', 'name_ja', 'name_th'] as $column) {
                $candidate = $hit[$column] ?? null;
                $similarity = is_string($candidate) && $candidate !== '' ? NameKey::similarity($query, NameKey::of($candidate), $bar) : 0.0;

                if ($similarity >= $bestSimilarity && ($best === null || $similarity > $best['similarity'])) {
                    $best = ['card_id' => (int) $hit['card_id'], 'score' => (float) $hit['_rankingScore'], 'similarity' => $similarity];
                }
            }
        }

        return $best === null ? null : ['card_id' => $best['card_id'], 'score' => $best['score']];
    }
}

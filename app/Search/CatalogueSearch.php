<?php

namespace App\Search;

use App\Enums\QueryKind;
use App\Models\CardSet;
use App\Models\Printing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The autocomplete's one entry point. Sealed products join in M8.
 *
 * A jump (set or printing) is an extra row: the results always come from a text search of the whole raw
 * query. Only a matched printing is also placed first in the list.
 */
class CatalogueSearch
{
    private const PRINTING_LIMIT = 6;

    private const SET_LIMIT = 2;

    public function __construct(private readonly QueryParser $parser) {}

    public function autocomplete(string $query): AutocompleteResult
    {
        $parsed = $this->parser->parse($query);
        $jump = $parsed->kind === QueryKind::Text ? null : $parsed;

        try {
            [$printings, $sets, $total] = $this->fromEngine($parsed);
            $limited = false;
        } catch (Throwable $exception) {
            report($exception);

            [$printings, $sets, $total] = $this->fromDatabase($parsed);
            $limited = true;
        }

        $printings = $this->jumpPrintings($parsed)->concat($printings)->unique('id')->take(self::PRINTING_LIMIT)->values();

        return new AutocompleteResult($jump, $printings, $sets, $limited, max($total, $printings->count()));
    }

    /**
     * Hits come back from the engine without the visibility rules, so they are hydrated through `searchVisible()`:
     * a trashed card or an inactive game never reaches a view before the queued re-index runs.
     *
     * @return array{Collection<int, Printing>, Collection<int, CardSet>, int}
     */
    private function fromEngine(ParsedQuery $parsed): array
    {
        // Nothing typed means the SearchBox shows recent searches, not results.
        if ($parsed->text === '') {
            return [new Collection, new Collection, 0];
        }

        $page = Printing::search($parsed->text)
            ->query(fn (Builder $builder) => $builder->searchVisible()->withCheapestVariant())
            ->paginate(self::PRINTING_LIMIT, 'page', 1);

        $sets = CardSet::search($parsed->text)
            ->query(fn (Builder $builder) => $builder->searchVisible())
            ->take(self::SET_LIMIT)
            ->get();

        return [new Collection($page->items()), $sets, $page->total()];
    }

    /**
     * @return array{Collection<int, Printing>, Collection<int, CardSet>, int}
     */
    private function fromDatabase(ParsedQuery $parsed): array
    {
        if ($parsed->text === '') {
            return [new Collection, new Collection, 0];
        }

        $term = '%'.$parsed->text.'%';

        $matching = Printing::query()->searchVisible()->matchingText($parsed->text);

        $total = $matching->count();
        $printings = $matching->withCheapestVariant()->orderBy('id')->limit(self::PRINTING_LIMIT)->get();

        $sets = CardSet::query()
            ->searchVisible()
            ->where(fn (Builder $builder) => $builder->whereLike('code', $term)->orWhereLike('name_en', $term)->orWhereLike('name_th', $term))
            ->orderBy('sort')
            ->limit(self::SET_LIMIT)
            ->get();

        return [$printings, $sets, $total];
    }

    /**
     * The printing a card code names (`OP05-119`), in stock first and then cheapest: where Enter goes. Null for
     * anything that is not a card code.
     */
    public function printingJump(string $query): ?Printing
    {
        return $this->jumpPrintings($this->parser->parse($query))->first();
    }

    /**
     * The printings a card code names, in stock first and then cheapest, like the index's ranking rules.
     *
     * @return Collection<int, Printing>
     */
    private function jumpPrintings(ParsedQuery $parsed): Collection
    {
        if ($parsed->kind !== QueryKind::Printing) {
            return new Collection;
        }

        return Printing::query()
            ->searchVisible()
            ->withCheapestVariant()
            ->whereKey($parsed->printingIds)
            ->get()
            ->sortBy(fn (Printing $printing): array => [
                $printing->stockSummary()['in_stock'] ? 0 : 1,
                $printing->stockSummary()['from_satang'] ?? PHP_INT_MAX,
            ])
            ->values();
    }
}

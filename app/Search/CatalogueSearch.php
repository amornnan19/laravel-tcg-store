<?php

namespace App\Search;

use App\Enums\QueryKind;
use App\Models\CardSet;
use App\Models\Printing;
use App\Models\SealedProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The autocomplete's one entry point: printings, sets and sealed products.
 *
 * A jump (set or printing) is an extra row: the results always come from a text search of the whole raw
 * query. Only a matched printing is also placed first in the list.
 */
class CatalogueSearch
{
    private const PRINTING_LIMIT = 6;

    private const SET_LIMIT = 2;

    private const SEALED_LIMIT = 2;

    public function __construct(private readonly QueryParser $parser) {}

    public function autocomplete(string $query): AutocompleteResult
    {
        $parsed = $this->parser->parse($query);
        $jump = $parsed->kind === QueryKind::Text ? null : $parsed;

        try {
            [$printings, $sets, $sealed, $total] = $this->fromEngine($parsed);
            $limited = false;
        } catch (Throwable $exception) {
            report($exception);

            [$printings, $sets, $sealed, $total] = $this->fromDatabase($parsed);
            $limited = true;
        }

        $printings = $this->jumpPrintings($parsed)->concat($printings)->unique('id')->take(self::PRINTING_LIMIT)->values();

        return new AutocompleteResult($jump, $printings, $sets, $sealed, $limited, max($total, $printings->count()));
    }

    /**
     * Hits come back from the engine without the visibility rules, so they are hydrated through `searchVisible()`:
     * a trashed card or an inactive game never reaches a view before the queued re-index runs.
     *
     * @return array{Collection<int, Printing>, Collection<int, CardSet>, Collection<int, SealedProduct>, int}
     */
    private function fromEngine(ParsedQuery $parsed): array
    {
        // Nothing typed means the SearchBox shows recent searches, not results.
        if ($parsed->text === '') {
            return [new Collection, new Collection, new Collection, 0];
        }

        $page = Printing::search($parsed->text)
            ->query(fn (Builder $builder) => $builder->searchVisible()->withCheapestVariant())
            ->paginate(self::PRINTING_LIMIT, 'page', 1);

        $sets = CardSet::search($parsed->text)
            ->query(fn (Builder $builder) => $builder->searchVisible())
            ->take(self::SET_LIMIT)
            ->get();

        return [new Collection($page->items()), $sets, $this->sealedFromEngine($parsed), $page->total()];
    }

    /**
     * @return array{Collection<int, Printing>, Collection<int, CardSet>, Collection<int, SealedProduct>, int}
     */
    private function fromDatabase(ParsedQuery $parsed): array
    {
        if ($parsed->text === '') {
            return [new Collection, new Collection, new Collection, 0];
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

        return [$printings, $sets, $this->sealedFromDatabase($term), $total];
    }

    /**
     * Sealed products have an index of their own, so a failure of it (a missing index, say) falls back to the database
     * for them alone: printings and sets keep the engine, and the result is not marked limited.
     *
     * @return Collection<int, SealedProduct>
     */
    private function sealedFromEngine(ParsedQuery $parsed): Collection
    {
        try {
            return SealedProduct::search($parsed->text)
                ->query(fn (Builder $builder) => $builder->searchVisible()->withReservedQty())
                ->take(self::SEALED_LIMIT)
                ->get();
        } catch (Throwable $exception) {
            // A missing index is an expected state right after a deploy; report it once per ten minutes, not on every keystroke.
            if (Cache::add('search:sealed-engine-failed', true, 600)) {
                report($exception);
            }

            return $this->sealedFromDatabase('%'.$parsed->text.'%');
        }
    }

    /**
     * @return Collection<int, SealedProduct>
     */
    private function sealedFromDatabase(string $term): Collection
    {
        return SealedProduct::query()
            ->searchVisible()
            ->withReservedQty()
            ->where(fn (Builder $builder) => $builder->whereLike('name_en', $term)->orWhereLike('name_th', $term)->orWhereLike('sku', $term))
            ->orderBy('id')
            ->limit(self::SEALED_LIMIT)
            ->get();
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

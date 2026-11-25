<?php

namespace App\Search;

use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The results pages' one entry point: filtered, sorted printings with the facet counts that drive the filter panel.
 *
 * Hits come back from the engine without the visibility rules, so they are hydrated through `searchVisible()`
 * like every other search. When the engine fails the database answers with the text, game, set, language and
 * stock filters only and no facet counts.
 */
class CatalogueResults
{
    public const SORTS = ['relevance', 'price_asc', 'price_desc', 'number', 'newest'];

    /**
     * Sort => Meilisearch `sort` rule. Relevance sends none, so the index's own ranking rules decide.
     */
    private const ENGINE_SORTS = [
        'price_asc' => ['from_satang', 'asc'],
        'price_desc' => ['from_satang', 'desc'],
        'number' => ['number_plain', 'asc'],
        'newest' => ['released_on', 'desc'],
    ];

    /**
     * @param  string  $sort  One of SORTS.
     * @param  list<string>  $facetGroups  The groups to count; each is counted against every other filter.
     */
    public function search(ResultFilters $filters, string $sort, int $limit, array $facetGroups = []): ResultPage
    {
        try {
            return $this->fromEngine($filters, $sort, $limit, $facetGroups);
        } catch (Throwable $exception) {
            report($exception);

            return $this->fromDatabase($filters, $sort, $limit);
        }
    }

    /**
     * How many printings the filters match, without listing them.
     *
     * @param  bool  $databaseOnly  Skip the engine, which a page that has just found it down must not ask again.
     */
    public function count(ResultFilters $filters, bool $databaseOnly = false): int
    {
        if ($databaseOnly) {
            return $this->databaseQuery($filters)->count();
        }

        try {
            return (int) $this->engineQuery($filters, [])->take(1)->raw()['totalHits'];
        } catch (Throwable $exception) {
            report($exception);

            return $this->databaseQuery($filters)->count();
        }
    }

    /**
     * @param  list<string>  $facetGroups
     */
    private function fromEngine(ResultFilters $filters, string $sort, int $limit, array $facetGroups): ResultPage
    {
        $inactive = array_values(array_filter($facetGroups, fn (string $group): bool => ! $filters->isActive($group)));
        $raw = [];

        $builder = $this->engineQuery($filters, $inactive)
            ->take($limit)
            ->query(fn (Builder $query) => $query->searchVisible()->withCheapestVariant())
            ->withRawResults(function (array $results) use (&$raw): array {
                $raw = $results;

                return $results;
            });

        if (isset(self::ENGINE_SORTS[$sort])) {
            $builder->orderBy(...self::ENGINE_SORTS[$sort]);
        }

        $printings = $builder->get();
        $facets = [];

        foreach ($inactive as $group) {
            $facets[$group] = $this->options($raw['facetDistribution'][ResultFilters::FACETS[$group]] ?? []);
        }

        // A group with a filter on counts its options against every other filter but its own, or picking one
        // option would make every other option of the group read zero.
        foreach (array_diff($facetGroups, $inactive) as $group) {
            $attribute = ResultFilters::FACETS[$group];
            $facets[$group] = $this->options($this->engineQuery($filters, [$group], $group)->take(1)->raw()['facetDistribution'][$attribute] ?? []);
        }

        return new ResultPage($printings, (int) ($raw['totalHits'] ?? $printings->count()), $facets);
    }

    /**
     * A facet distribution without Meilisearch's `NULL` bucket, which counts printings that have no value at all.
     *
     * @param  array<string, int>  $distribution
     * @return array<string, int>
     */
    private function options(array $distribution): array
    {
        unset($distribution['NULL']);

        return $distribution;
    }

    /**
     * @param  list<string>  $facetGroups  The groups the engine counts.
     * @param  ?string  $except  A group whose own filter is left out.
     * @return \Laravel\Scout\Builder<Printing>
     */
    private function engineQuery(ResultFilters $filters, array $facetGroups, ?string $except = null)
    {
        $options = [];
        $filter = $filters->meilisearchFilter($except);

        if ($filter !== '') {
            $options['filter'] = $filter;
        }

        if ($facetGroups !== []) {
            $options['facets'] = array_map(fn (string $group): string => ResultFilters::FACETS[$group], $facetGroups);
        }

        return Printing::search($filters->text)->options($options);
    }

    private function fromDatabase(ResultFilters $filters, string $sort, int $limit): ResultPage
    {
        $query = $this->databaseQuery($filters);
        $total = (clone $query)->count();

        $this->applyDatabaseSort($query, $sort);

        $printings = $query->withCheapestVariant()->orderBy('printings.id')->limit($limit)->get();

        return new ResultPage($printings, $total, [], true);
    }

    /**
     * @return Builder<Printing>
     */
    private function databaseQuery(ResultFilters $filters): Builder
    {
        $query = Printing::query()->searchVisible();

        if ($filters->text !== '') {
            $query->matchingText($filters->text);
        }

        if ($filters->games !== []) {
            $query->whereHas('set.game', fn (Builder $game) => $game->whereIn('slug', $filters->games));
        }

        if ($filters->sets !== []) {
            $query->whereHas('set', fn (Builder $set) => $set->whereIn('code', $filters->sets));
        }

        if ($filters->languages !== []) {
            $query->whereIn('printings.language', $filters->languages);
        }

        if ($filters->inStockOnly) {
            $query->whereHas('variants', fn (Builder $variants) => $variants->available());
        }

        return $query;
    }

    /**
     * @param  Builder<Printing>  $query
     */
    private function applyDatabaseSort(Builder $query, string $sort): void
    {
        $price = Variant::query()->available()->selectRaw('min(price_satang)')->whereColumn('variants.printing_id', 'printings.id');

        match ($sort) {
            'number' => $query->orderBy('printings.number'),
            'newest' => $query->orderByRaw('coalesce(printings.released_on, (select sets.released_on from sets where sets.id = printings.set_id)) is null')
                ->orderByRaw('coalesce(printings.released_on, (select sets.released_on from sets where sets.id = printings.set_id)) desc'),
            default => $query
                ->orderByRaw('('.$price->toSql().') is null', $price->getBindings())
                ->orderBy($price, $sort === 'price_desc' ? 'desc' : 'asc'),
        };
    }
}

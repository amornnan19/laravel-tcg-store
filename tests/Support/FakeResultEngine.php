<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\NullEngine;

/**
 * A Scout engine for the results page tests: it answers every search with the printings in the database (up to the
 * page limit), a total the test decides from the filter string, and canned facet counts. Every request is recorded.
 */
class FakeResultEngine extends NullEngine
{
    /**
     * @var list<array{query: string, options: array<string, mixed>, limit: ?int, orders: array<int, array{column: string, direction: string}>}>
     */
    public array $requests = [];

    /**
     * @param  array<string, array<string, int>>  $facets  Meilisearch attribute => value => count.
     * @param  ?Closure(string): int  $total  The total for a filter string; defaults to every printing.
     */
    public function __construct(private readonly array $facets = [], private readonly ?Closure $total = null) {}

    public function search(Builder $builder)
    {
        $filter = (string) ($builder->options['filter'] ?? '');

        $this->requests[] = [
            'query' => $builder->query,
            'options' => $builder->options,
            'limit' => $builder->limit,
            'orders' => $builder->orders,
        ];

        // The fake's own read of the catalogue must not count as a query of the page under test.
        $connection = $builder->model->getConnection();
        $logging = $connection->logging();
        $connection->disableQueryLog();
        $all = $builder->model->newQuery()->orderBy('id')->pluck('id');

        if ($logging) {
            $connection->enableQueryLog();
        }

        $total = $this->total === null ? $all->count() : ($this->total)($filter);
        $hits = $all->take(min($total, (int) $builder->limit))->map(fn (int $id): array => ['id' => $id])->values()->all();

        return [
            'hits' => $hits,
            'totalHits' => $total,
            'facetDistribution' => collect($builder->options['facets'] ?? [])->mapWithKeys(fn (string $attribute): array => [$attribute => $this->facets[$attribute] ?? []])->all(),
        ];
    }

    public function map(Builder $builder, $results, $model)
    {
        $ids = collect($results['hits'])->pluck('id')->all();

        if ($ids === []) {
            return new Collection;
        }

        $positions = array_flip($ids);

        return $model->getScoutModelsByIds($builder, $ids)->sortBy(fn ($printing) => $positions[$printing->getScoutKey()])->values();
    }

    public function getTotalCount($results)
    {
        return $results['totalHits'];
    }
}

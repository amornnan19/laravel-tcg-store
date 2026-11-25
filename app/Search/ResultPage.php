<?php

namespace App\Search;

use App\Models\Printing;
use Illuminate\Support\Collection;

final readonly class ResultPage
{
    /**
     * @param  Collection<int, Printing>  $printings  With `card`, `set` and `variants` loaded.
     * @param  int  $total  Every printing the filters match, not only the ones listed.
     * @param  array<string, array<string, int>>  $facets  Group => option value => count, each group counted against every
     *                                                     other filter. Empty when the database answered.
     * @param  bool  $limited  True when the engine failed and the database answered instead.
     */
    public function __construct(
        public Collection $printings,
        public int $total,
        public array $facets = [],
        public bool $limited = false,
    ) {}
}

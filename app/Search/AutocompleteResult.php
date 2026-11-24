<?php

namespace App\Search;

use App\Models\CardSet;
use App\Models\Printing;
use Illuminate\Support\Collection;

final readonly class AutocompleteResult
{
    /**
     * @param  ?ParsedQuery  $jump  The destination the query names, when it is not plain text.
     * @param  Collection<int, Printing>  $printings  At most 6, with `card`, `set` and `variants` loaded.
     * @param  Collection<int, CardSet>  $sets  At most 2.
     * @param  bool  $limited  True when the engine failed and the database answered instead.
     */
    public function __construct(
        public ?ParsedQuery $jump,
        public Collection $printings,
        public Collection $sets,
        public bool $limited = false,
    ) {}
}

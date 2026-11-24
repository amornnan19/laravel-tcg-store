<?php

namespace App\Search;

use App\Enums\QueryKind;

/**
 * What a raw search string means: a destination (a printing or a set) or plain text.
 */
final readonly class ParsedQuery
{
    /**
     * @param  list<int>  $setIds  Every visible set the code names (the same code can exist in two games); at most 2 for a `Set` jump.
     * @param  list<int>  $printingIds  Every printing, in every language, that matches a `Printing` query.
     * @param  ?int  $numberPlain  The integer of a bare `190/190`, set only on a `Text` query.
     */
    public function __construct(
        public QueryKind $kind,
        public string $text,
        public array $setIds = [],
        public array $printingIds = [],
        public ?int $numberPlain = null,
    ) {}
}

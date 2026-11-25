<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deck-list importer limits
    |--------------------------------------------------------------------------
    |
    | The longest paste the importer reads, in characters and in non-blank lines, and the
    | most one line may ask for. A larger quantity is read as `max_qty` and the line says so.
    |
    */

    'max_chars' => 10000,
    'max_lines' => 200,
    'max_qty' => 99,

    /*
    |--------------------------------------------------------------------------
    | Fuzzy name matching
    |--------------------------------------------------------------------------
    |
    | A name that matches no card exactly is looked up in the search engine. A hit is considered
    | only at a Meilisearch ranking score of at least `fuzzy_min_score` (a floor, 0 to 1) and
    | accepted only when the name is within `fuzzy_min_similarity` (normalised Levenshtein, 0 to 1)
    | of one of the card's names. Only the first `fuzzy_max_lines` unmatched lines whose text is at most
    | `fuzzy_max_chars` characters are looked up.
    |
    */

    'fuzzy_min_score' => 0.5,
    'fuzzy_min_similarity' => 0.8,
    'fuzzy_max_lines' => 30,
    'fuzzy_max_chars' => 100,

];

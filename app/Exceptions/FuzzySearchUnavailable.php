<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The search engine could not answer a fuzzy name lookup. A deck-list parse stops asking for the rest of the list.
 */
class FuzzySearchUnavailable extends RuntimeException {}

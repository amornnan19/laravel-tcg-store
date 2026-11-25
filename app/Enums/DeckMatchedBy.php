<?php

namespace App\Enums;

/**
 * Which rule of the matching order found a deck line's card.
 */
enum DeckMatchedBy: string
{
    case Code = 'code';
    case DeclaredSet = 'declared_set';
    case Name = 'name';
    case Fuzzy = 'fuzzy';
}

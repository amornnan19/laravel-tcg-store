<?php

namespace App\Enums;

/**
 * What a line of a pasted deck list turned out to be.
 */
enum DeckLineKind: string
{
    case Matched = 'matched';
    case Unmatched = 'unmatched';
    case Ignored = 'ignored';
    case Directive = 'directive';
}

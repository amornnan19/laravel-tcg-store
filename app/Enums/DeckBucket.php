<?php

namespace App\Enums;

/**
 * The three groups a deck list is sorted into, in the order the page shows them.
 */
enum DeckBucket: string
{
    case Full = 'full';
    case Partial = 'partial';
    case Missing = 'missing';

    public function label(): string
    {
        return __('enums.deck_bucket.'.$this->value);
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SealedKind: string implements HasLabel
{
    case BoosterBox = 'booster_box';
    case BoosterPack = 'booster_pack';
    case StarterDeck = 'starter_deck';
    case Accessory = 'accessory';

    public function label(): string
    {
        return __('enums.sealed_kind.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}

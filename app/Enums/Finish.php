<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Finish: string implements HasLabel
{
    case Normal = 'normal';
    case Foil = 'foil';

    /**
     * The single letter used in a SKU.
     */
    public function skuCode(): string
    {
        return match ($this) {
            self::Normal => 'N',
            self::Foil => 'F',
        };
    }

    public function label(): string
    {
        return __('enums.finish.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}

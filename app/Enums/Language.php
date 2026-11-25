<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Language: string implements HasLabel
{
    case Ja = 'ja';
    case En = 'en';
    case Th = 'th';

    public function label(): string
    {
        return __('enums.language.'.$this->value);
    }

    /**
     * The short code shown on badges and card meta lines.
     */
    public function shortCode(): string
    {
        return match ($this) {
            self::Ja => 'JP',
            self::En => 'EN',
            self::Th => 'TH',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}

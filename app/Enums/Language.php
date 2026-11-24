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

    public function getLabel(): string
    {
        return $this->label();
    }
}

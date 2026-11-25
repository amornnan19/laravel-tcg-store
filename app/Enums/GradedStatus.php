<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GradedStatus: string implements HasColor, HasLabel
{
    case Listed = 'listed';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return __('enums.graded_status.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Listed => 'success',
            self::Reserved => 'warning',
            self::Sold => 'info',
            self::Withdrawn => 'gray',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CardCondition: string implements HasColor, HasLabel
{
    case Nm = 'nm';
    case Lp = 'lp';
    case Mp = 'mp';
    case Hp = 'hp';
    case Dmg = 'dmg';

    /**
     * NM is better than LP: 1 is the best condition, so "better than LP" is answerable in SQL too.
     */
    public function sort(): int
    {
        return match ($this) {
            self::Nm => 1,
            self::Lp => 2,
            self::Mp => 3,
            self::Hp => 4,
            self::Dmg => 5,
        };
    }

    public function label(): string
    {
        return __('enums.condition.'.$this->value);
    }

    /**
     * The design-token name, so the storefront badge is never hand-coloured.
     */
    public function color(): string
    {
        return 'cond-'.$this->value;
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * The Filament palette name for the admin panel.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Nm => 'success',
            self::Lp => 'info',
            self::Mp, self::Hp => 'warning',
            self::Dmg => 'danger',
        };
    }
}

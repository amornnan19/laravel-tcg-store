<?php

namespace App\Enums;

enum BuylistPayout: string
{
    case Cash = 'cash';
    case Credit = 'credit';

    public function label(): string
    {
        return __('enums.buylist_payout.'.$this->value);
    }
}

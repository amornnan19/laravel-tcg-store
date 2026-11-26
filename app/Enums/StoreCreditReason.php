<?php

namespace App\Enums;

enum StoreCreditReason: string
{
    case Buylist = 'buylist';
    case Order = 'order';
    case Refund = 'refund';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return __('enums.store_credit_reason.'.$this->value);
    }
}

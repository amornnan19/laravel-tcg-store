<?php

namespace App\Enums;

/**
 * How a pre-order is paid: a deposit now and the rest before the release, or everything now at a discount.
 */
enum PreorderPlan: string
{
    case Deposit = 'deposit';
    case Full = 'full';

    public function label(): string
    {
        return __('enums.preorder_plan.'.$this->value);
    }
}

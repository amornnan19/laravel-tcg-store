<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    public function label(): string
    {
        return __('enums.payment_status.'.$this->value);
    }
}

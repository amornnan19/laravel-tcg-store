<?php

namespace App\Enums;

/**
 * Why staff reject a slip. Three are fixed sentences the customer reads verbatim; `other` carries free text.
 */
enum RejectReason: string
{
    case AmountMismatch = 'amount_mismatch';
    case Unreadable = 'unreadable';
    case WrongAccount = 'wrong_account';
    case Other = 'other';

    public function label(): string
    {
        return __('admin.payment.reason_options.'.$this->value);
    }
}

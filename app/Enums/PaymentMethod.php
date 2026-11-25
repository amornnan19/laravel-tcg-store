<?php

namespace App\Enums;

use App\Support\PromptPay;

enum PaymentMethod: string
{
    case Promptpay = 'promptpay';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';

    public function label(): string
    {
        return __('enums.payment_method.'.$this->value);
    }

    /**
     * The methods checkout offers: the ones the shop has configured the details for. Card is not offered in M5.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        $offered = [];

        if (PromptPay::isValidId((string) config('shop.promptpay_id'))) {
            $offered[] = self::Promptpay;
        }

        if (filled(config('shop.bank.name')) && filled(config('shop.bank.account_name')) && filled(config('shop.bank.account_number'))) {
            $offered[] = self::BankTransfer;
        }

        return $offered;
    }
}

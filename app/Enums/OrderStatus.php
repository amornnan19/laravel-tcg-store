<?php

namespace App\Enums;

enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingBalance = 'awaiting_balance';
    case Paid = 'paid';
    case Packing = 'packing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return __('enums.order_status.'.$this->value);
    }

    /**
     * The design-token name, so the badge is never hand-coloured.
     */
    public function color(): string
    {
        return match ($this) {
            self::AwaitingPayment, self::AwaitingBalance => 'low',
            self::Paid, self::Packing => 'info',
            self::Shipped, self::Delivered => 'stock',
            self::Cancelled, self::Refunded => 'danger',
        };
    }
}

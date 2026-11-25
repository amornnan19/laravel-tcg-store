<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where an alert said it would be delivered when it was set. Display only: the message is routed by the customer's
 * preference at send time.
 */
enum AlertChannel: string implements HasLabel
{
    case Line = 'line';
    case Email = 'email';

    public function label(): string
    {
        return __('enums.alert_channel.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}

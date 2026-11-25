<?php

namespace App\Enums;

enum Courier: string
{
    case Flash = 'flash';
    case Kerry = 'kerry';
    case Jt = 'jt';
    case Thaipost = 'thaipost';

    public function label(): string
    {
        return __('enums.courier.'.$this->value);
    }

    /**
     * The flat rate in satang, from `config/shipping.php`.
     */
    public function rateSatang(): int
    {
        return (int) config('shipping.couriers.'.$this->value.'.rate_satang');
    }

    /**
     * "1–2 วัน": the delivery time label.
     */
    public function daysLabel(): string
    {
        return __(config('shipping.couriers.'.$this->value.'.days_th'));
    }

    /**
     * The courier's public tracking page for a parcel number.
     */
    public function trackingUrl(string $number): string
    {
        return str_replace('{number}', rawurlencode($number), (string) config('shipping.couriers.'.$this->value.'.tracking_url'));
    }
}

<?php

namespace App\Exceptions;

use App\Support\LineLogin;
use RuntimeException;

/**
 * An alert action cannot do what the customer asked. `$reason` is the suffix of a `storefront.alerts.refused.*` line the
 * storefront shows; `$replace` fills its placeholders.
 */
class AlertRefused extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(public readonly string $reason, public readonly array $replace = [])
    {
        parent::__construct("Alert refused: {$reason}.");
    }

    public function message(): string
    {
        $key = $this->reason === 'no_channel' && ! LineLogin::isConfigured() ? 'no_channel_email' : $this->reason;

        return __('storefront.alerts.refused.'.$key, $this->replace);
    }
}

<?php

namespace App\Support;

class LineLogin
{
    /**
     * Whether LINE Login is configured, so customer-facing copy may mention LINE.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.line.client_id'));
    }
}

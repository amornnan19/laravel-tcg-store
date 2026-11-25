<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Grader: string implements HasLabel
{
    case Psa = 'psa';
    case Bgs = 'bgs';
    case Cgc = 'cgc';

    public function label(): string
    {
        return strtoupper($this->value);
    }

    /**
     * The grader's public page for a certificate number, or null when this grader has none configured.
     */
    public function verifyUrl(string $cert): ?string
    {
        $template = config('graded.verify_urls.'.$this->value);

        return is_string($template) && $template !== '' ? str_replace('{cert}', rawurlencode($cert), $template) : null;
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}

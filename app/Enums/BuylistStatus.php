<?php

namespace App\Enums;

enum BuylistStatus: string
{
    /** Never written: the builder keeps its list in the session. The case exists because the docs list it. */
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Received = 'received';
    case Revised = 'revised';
    case Accepted = 'accepted';
    case Paid = 'paid';
    case Returned = 'returned';

    public function label(): string
    {
        return __('enums.buylist_status.'.$this->value);
    }

    /**
     * The label staff read: the same states, but `revised` says who is waited on.
     */
    public function staffLabel(): string
    {
        return $this === self::Revised ? __('enums.buylist_status_staff.revised') : $this->label();
    }

    /**
     * The design-token name, so the badge is never hand-coloured.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Submitted, self::Revised => 'low',
            self::Received, self::Accepted => 'info',
            self::Paid => 'stock',
            self::Returned => 'danger',
        };
    }
}

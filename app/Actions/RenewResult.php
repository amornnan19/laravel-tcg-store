<?php

namespace App\Actions;

use App\Enums\RenewOutcome;
use App\Models\Variant;

/**
 * What RenewCartItem did to a line, for the cart page to tell the customer.
 */
final readonly class RenewResult
{
    /**
     * @param  array{from: int, to: int}|null  $qtyReduced  The line's quantity when fewer copies were left than it asked for.
     * @param  array{from: int, to: int}|null  $priceChanged  The unit price, in satang, when it moved while the hold had expired.
     * @param  Variant|null  $substitute  For `Taken`: the cheapest other condition of the same printing that can be bought now.
     */
    public function __construct(
        public RenewOutcome $outcome,
        public ?array $qtyReduced = null,
        public ?array $priceChanged = null,
        public ?Variant $substitute = null,
    ) {}
}

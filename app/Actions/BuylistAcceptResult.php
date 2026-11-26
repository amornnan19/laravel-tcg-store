<?php

namespace App\Actions;

use App\Models\BuylistSubmission;

/**
 * What accepting a submission did: the submission, and how many variants it had to create (unpriced, so not for sale
 * until staff price them).
 */
final readonly class BuylistAcceptResult
{
    public function __construct(public BuylistSubmission $submission, public int $createdVariants = 0) {}
}

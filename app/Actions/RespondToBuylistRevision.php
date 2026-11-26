<?php

namespace App\Actions;

use App\Exceptions\BuylistRefused;
use App\Models\BuylistSubmission;
use App\Models\User;

/**
 * The customer's answer to a revised total: take it (the cards go into stock and they are paid, or the cash is queued)
 * or ask for the cards back, free. Both are checked under the submission's lock by the action that carries them out:
 * owner only, `revised` only. Accepting goes through `AcceptBuylistSubmission` with the customer's confirmation, so the
 * stock locks are taken before the submission's, in the project's order.
 */
class RespondToBuylistRevision
{
    public function __construct(private AcceptBuylistSubmission $accept, private ReturnBuylistSubmission $return) {}

    /**
     * @throws BuylistRefused when the customer is not the owner or the submission is not `revised`.
     */
    public function handle(BuylistSubmission $submission, User $customer, bool $accept): BuylistSubmission
    {
        return $accept
            ? $this->accept->handle($submission, customer: $customer)->submission
            : $this->return->handle($submission, customer: $customer);
    }
}

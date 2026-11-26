<?php

namespace App\Policies;

use App\Models\BuylistSubmission;
use App\Models\User;

/**
 * Staff read and work every submission; a submission is made by a customer at `/buylist`, never in the panel, and is
 * never deleted.
 */
class BuylistSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function view(User $user, BuylistSubmission $submission): bool
    {
        return $user->is_staff === true;
    }

    public function update(User $user, BuylistSubmission $submission): bool
    {
        return $user->is_staff === true;
    }
}

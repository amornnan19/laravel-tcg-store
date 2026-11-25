<?php

namespace App\Actions;

use App\Models\RestockAlert;
use App\Models\User;

class RemoveRestockAlert
{
    /**
     * Deletes the customer's own alert; someone else's is left alone.
     */
    public function handle(User $user, RestockAlert $alert): bool
    {
        return RestockAlert::query()->whereKey($alert->id)->where('user_id', $user->id)->delete() > 0;
    }
}

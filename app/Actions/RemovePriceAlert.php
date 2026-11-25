<?php

namespace App\Actions;

use App\Models\PriceAlert;
use App\Models\User;

class RemovePriceAlert
{
    /**
     * Deletes the customer's own alert; someone else's is left alone.
     */
    public function handle(User $user, PriceAlert $alert): bool
    {
        return PriceAlert::query()->whereKey($alert->id)->where('user_id', $user->id)->delete() > 0;
    }
}

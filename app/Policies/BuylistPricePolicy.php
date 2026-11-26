<?php

namespace App\Policies;

use App\Models\BuylistPrice;
use App\Models\User;

class BuylistPricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function view(User $user, BuylistPrice $buylistPrice): bool
    {
        return $user->is_staff === true;
    }

    public function create(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function update(User $user, BuylistPrice $buylistPrice): bool
    {
        return $user->is_staff === true;
    }

    public function delete(User $user, BuylistPrice $buylistPrice): bool
    {
        return $user->is_staff === true;
    }
}

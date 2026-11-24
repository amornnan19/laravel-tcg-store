<?php

namespace App\Policies;

use App\Models\PricePoint;
use App\Models\User;

class PricePointPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PricePoint $pricePoint): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PricePoint $pricePoint): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PricePoint $pricePoint): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, PricePoint $pricePoint): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, PricePoint $pricePoint): bool
    {
        return $user->is_staff === true;
    }
}

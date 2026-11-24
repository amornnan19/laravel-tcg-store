<?php

namespace App\Policies;

use App\Models\CardSet;
use App\Models\User;

class CardSetPolicy
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
    public function view(User $user, CardSet $cardSet): bool
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
    public function update(User $user, CardSet $cardSet): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CardSet $cardSet): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CardSet $cardSet): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CardSet $cardSet): bool
    {
        return $user->is_staff === true;
    }
}

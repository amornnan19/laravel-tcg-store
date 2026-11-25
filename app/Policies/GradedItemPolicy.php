<?php

namespace App\Policies;

use App\Models\GradedItem;
use App\Models\User;

class GradedItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function view(User $user, GradedItem $gradedItem): bool
    {
        return $user->is_staff === true;
    }

    public function create(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function update(User $user, GradedItem $gradedItem): bool
    {
        return $user->is_staff === true;
    }

    public function delete(User $user, GradedItem $gradedItem): bool
    {
        return $user->is_staff === true;
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function restore(User $user, GradedItem $gradedItem): bool
    {
        return $user->is_staff === true;
    }

    public function forceDelete(User $user, GradedItem $gradedItem): bool
    {
        return $user->is_staff === true;
    }
}

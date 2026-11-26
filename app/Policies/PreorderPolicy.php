<?php

namespace App\Policies;

use App\Models\Preorder;
use App\Models\User;

class PreorderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function view(User $user, Preorder $preorder): bool
    {
        return $user->is_staff === true;
    }

    public function create(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function update(User $user, Preorder $preorder): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Never deleted: orders refer to it. Close it instead.
     */
    public function delete(User $user, Preorder $preorder): bool
    {
        return false;
    }
}

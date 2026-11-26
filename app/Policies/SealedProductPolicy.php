<?php

namespace App\Policies;

use App\Models\SealedProduct;
use App\Models\User;

class SealedProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function view(User $user, SealedProduct $sealedProduct): bool
    {
        return $user->is_staff === true;
    }

    public function create(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function update(User $user, SealedProduct $sealedProduct): bool
    {
        return $user->is_staff === true;
    }

    public function delete(User $user, SealedProduct $sealedProduct): bool
    {
        return $user->is_staff === true;
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function restore(User $user, SealedProduct $sealedProduct): bool
    {
        return $user->is_staff === true;
    }

    public function restoreAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function forceDelete(User $user, SealedProduct $sealedProduct): bool
    {
        return $user->is_staff === true;
    }
}

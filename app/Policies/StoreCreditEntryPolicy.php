<?php

namespace App\Policies;

use App\Models\StoreCreditEntry;
use App\Models\User;

/**
 * Staff read the ledger and adjust balances; nobody edits or deletes an entry.
 */
class StoreCreditEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function view(User $user, StoreCreditEntry $entry): bool
    {
        return $user->is_staff === true;
    }

    /**
     * Adding a ledger line by hand (the "ปรับยอดเครดิต" action); never a plain create form.
     */
    public function adjust(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StoreCreditEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, StoreCreditEntry $entry): bool
    {
        return false;
    }
}

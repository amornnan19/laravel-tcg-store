<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Staff only. Orders are placed by customers and never created or deleted in the panel; `update` stands for "may move
 * it on, cancel or refund it and edit its notes".
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function view(User $user, Order $order): bool
    {
        return $user->is_staff === true;
    }

    public function update(User $user, Order $order): bool
    {
        return $user->is_staff === true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }
}

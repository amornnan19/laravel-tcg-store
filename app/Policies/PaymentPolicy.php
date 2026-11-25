<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Staff only. A payment is never created, edited or deleted through the panel: it moves through the actions in
 * `app/Actions`, so `update` stands for "may confirm or reject it".
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_staff === true;
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->is_staff === true;
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->is_staff === true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}

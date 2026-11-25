<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A storefront login path was asked to sign in a staff user. The panel's second factor is enforced only by Filament's
 * own login page, so no other path may start a session for `is_staff`.
 */
class StaffLoginRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Staff accounts cannot sign in on the storefront.');
    }
}

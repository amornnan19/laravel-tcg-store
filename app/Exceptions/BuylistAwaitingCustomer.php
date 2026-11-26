<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A submission that is `revised` is waiting for the customer to accept the new total or ask for the cards back.
 * Nothing is paid and nothing goes into stock until they answer, so accepting it as staff is refused.
 */
class BuylistAwaitingCustomer extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The submission is waiting for the customer to answer the revised total.');
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The slip reference staff entered is already credited on another payment of the same order, so crediting it again would
 * count one transfer twice.
 */
class SlipRefAlreadyCredited extends RuntimeException
{
    public function message(): string
    {
        return __('admin.payment.slip_ref_credited');
    }
}

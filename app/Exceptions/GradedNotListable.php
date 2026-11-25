<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A graded item cannot be for sale without a real photo of the front, the back and the label, and a certificate number.
 */
class GradedNotListable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A graded item needs the front, back and label photos and a certificate number before it can be for sale.');
    }
}

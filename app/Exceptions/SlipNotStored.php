<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The slip could not be written to the private disk, so the payment must not be marked submitted.
 */
class SlipNotStored extends RuntimeException {}

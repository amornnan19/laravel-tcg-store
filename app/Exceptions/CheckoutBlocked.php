<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The cart cannot become an order right now: it is empty, or a line has no live hold of its full quantity, can no
 * longer be bought, or has less stock than the hold says. Nothing was written.
 */
class CheckoutBlocked extends RuntimeException {}

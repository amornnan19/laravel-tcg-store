<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The item cannot be bought: no price, a card the storefront hides, or a row that no longer exists.
 */
class NotPurchasable extends RuntimeException {}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The order is not in a state the action may move it from, for example cancelling an order that already shipped.
 */
class IllegalOrderTransition extends RuntimeException {}

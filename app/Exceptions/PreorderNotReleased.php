<?php

namespace App\Exceptions;

/**
 * A pre-order was asked to be packed before its release date: there is nothing to pack yet.
 */
class PreorderNotReleased extends IllegalOrderTransition {}

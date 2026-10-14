<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a purchase cannot proceed. The message is Japanese text safe to show directly on screen.
 */
class PurchaseException extends RuntimeException {}

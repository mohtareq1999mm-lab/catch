<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when checkout finds the cart missing or already emptied by a
 * concurrent/previous successful checkout. Mapped to a controlled 400 so
 * duplicate-checkout requests fail cleanly instead of surfacing as 500s.
 */
class CartEmptyException extends RuntimeException
{
}

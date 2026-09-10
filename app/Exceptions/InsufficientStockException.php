<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an order cannot reserve the physical inventory it needs.
 * Checkout endpoints translate this into a controlled 422 response while the
 * caller's transaction rolls back (no order, no reservation, cart intact).
 */
class InsufficientStockException extends InvalidArgumentException
{
}

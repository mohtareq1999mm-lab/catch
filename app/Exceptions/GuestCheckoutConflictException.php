<?php

namespace App\Exceptions;

/**
 * Safe, client-facing guest checkout conflict (no internal detail).
 */
class GuestCheckoutConflictException extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 409)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

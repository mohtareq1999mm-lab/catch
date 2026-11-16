<?php

namespace App\Exceptions;

use App\Contracts\ApiRejection;

/**
 * Safe, client-facing guest checkout conflict (no internal detail).
 *
 * Rendered by the API rejection renderer; the checkout controller does not
 * build its envelope.
 */
class GuestCheckoutConflictException extends \RuntimeException implements ApiRejection
{
    public function __construct(string $message, private int $httpStatus = 409)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function statusCode(): int
    {
        return $this->httpStatus;
    }

    public function message(): string
    {
        return $this->getMessage();
    }

    public function errors(): array
    {
        return [];
    }

    public function data(): array
    {
        return [];
    }
}

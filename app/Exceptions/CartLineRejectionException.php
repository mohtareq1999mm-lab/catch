<?php

namespace App\Exceptions;

use App\Contracts\ApiRejection;
use RuntimeException;

/**
 * A cart line cannot be applied for a business reason that is not tied to a
 * specific product lookup (invalid variant, unsupported shipping lane, quantity
 * bounds, unknown operation).
 *
 * The message is already localized by the caller that knows the domain rule.
 * Rendering belongs to the API rejection renderer, not to controllers.
 */
class CartLineRejectionException extends RuntimeException implements ApiRejection
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 422;
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

<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Contracts\ApiRejection;
use InvalidArgumentException;

/**
 * #156: a carrier shipment id is already bound to another shipment in the
 * same provider/mode scope. Type (not message text) drives the HTTP 422.
 */
final class ProviderShipmentIdConflictException extends InvalidArgumentException implements ApiRejection
{
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

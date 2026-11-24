<?php

declare(strict_types=1);

namespace App\DTOs\Shipping;

final class ShippingPollingResult
{
    public function __construct(
        public readonly int $accepted = 0,
        public readonly int $duplicates = 0,
        public readonly int $errors = 0,
        public readonly ?string $cursor = null,
    ) {}
}

<?php

namespace App\DTOs;

class GatewayResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $gatewayTransactionId = null,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $status = null,
        public readonly ?string $errorMessage = null,
        public readonly ?array $rawResponse = null,
    ) {}
}

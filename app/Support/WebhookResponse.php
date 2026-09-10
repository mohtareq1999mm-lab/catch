<?php

declare(strict_types=1);

namespace App\Support;

class WebhookResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly int $statusCode,
        public readonly ?string $body = null,
        public readonly array $headers = [],
        public readonly float $duration = 0.0,
        public readonly int $attempts = 1,
        public readonly ?string $error = null,
    ) {}
}

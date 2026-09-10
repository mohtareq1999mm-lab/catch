<?php

declare(strict_types=1);

namespace App\ValueObjects;

class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
    ) {}

    public function generate(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    public function verify(string $payload, string $signature): bool
    {
        return hash_equals($this->generate($payload), $signature);
    }
}

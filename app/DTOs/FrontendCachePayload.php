<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\FrontendResource;

class FrontendCachePayload
{
    public readonly string $requestId;

    public readonly string $occurredAt;

    public function __construct(
        public readonly FrontendResource $resource,
        ?string $requestId = null,
        ?string $occurredAt = null,
    ) {
        $this->requestId = $requestId ?? (string) str()->uuid();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
    }

    public function toArray(): array
    {
        return [
            'version' => config('frontend.version', 1),
            'request_id' => $this->requestId,
            'resource' => $this->resource->value,
            'occurred_at' => $this->occurredAt,
        ];
    }
}

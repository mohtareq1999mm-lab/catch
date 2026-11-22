<?php

declare(strict_types=1);

namespace App\DTOs\Shipping;

use App\Enums\Shipping\ShippingWebhookIngestOutcome;

/** Result of one webhook ingest attempt; no lifecycle authority. */
final class ShippingWebhookIngestResult
{
    public function __construct(
        public readonly ShippingWebhookIngestOutcome $outcome,
        public readonly ?string $eventKey = null,
        public readonly ?int $retryAfter = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\DTOs\FrontendCachePayload;
use App\Events\FrontendCacheInvalidation;
use App\Jobs\SendFrontendWebhookJob;

class DispatchFrontendCacheInvalidation
{
    public function handle(FrontendCacheInvalidation $event): void
    {
        $payload = new FrontendCachePayload(
            resource: $event->resource,
        );

        // P5: queue the webhook instead of executing it inline. The job keeps
        // its meem-high classification and retry semantics; observers firing
        // many invalidations per request no longer block the HTTP response.
        SendFrontendWebhookJob::dispatch($payload);
    }
}

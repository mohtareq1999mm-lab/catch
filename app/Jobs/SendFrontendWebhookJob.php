<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\FrontendWebhookDispatcher;
use App\DTOs\FrontendCachePayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFrontendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    public function __construct(
        public readonly FrontendCachePayload $payload,
    ) {
        $this->onQueue(config('frontend.queue', 'meem-high'));
    }

    public function handle(FrontendWebhookDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->payload);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(5);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendFrontendWebhookJob failed after all retries.', [
            'request_id' => $this->payload->requestId,
            'resource' => $this->payload->resource->value,
            'error' => $e->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}

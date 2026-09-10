<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FrontendWebhookDispatcher;
use App\DTOs\FrontendCachePayload;
use App\Exceptions\FrontendWebhookException;
use App\Support\WebhookResponse;
use App\ValueObjects\WebhookSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FrontendWebhookService implements FrontendWebhookDispatcher
{
    public function __construct(
        private readonly WebhookSignature $signature,
    ) {}

    public function dispatch(FrontendCachePayload $payload): WebhookResponse
    {
        if (!config('frontend.enabled', false)) {
            return new WebhookResponse(
                success: false,
                statusCode: 0,
                error: 'Frontend webhook is disabled.',
            );
        }

        $url = config('frontend.url');

        if (blank($url)) {
            throw new FrontendWebhookException('Frontend webhook URL is not configured.');
        }

        $body = $payload->toArray();
        $bodyJson = json_encode($body);
        $signatureValue = $this->signature->generate($bodyJson);

        $headers = [
            'X-Webhook-Secret' => config('frontend.secret'),
            'X-Webhook-Signature' => $signatureValue,
            'X-Webhook-Version' => (string) config('frontend.version', '1'),
            'X-Webhook-Request-Id' => $body['request_id'],
            'User-Agent' => config('frontend.user_agent', 'MeemCommerce-Webhook/1.0'),
        ];

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->timeout(config('frontend.timeout', 10))
                ->retry(
                    times: config('frontend.retry_times', 3),
                    sleepMilliseconds: config('frontend.retry_sleep', 1000),
                )
                ->throw()
                ->post($url, $body);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::info('Frontend webhook dispatched successfully.', [
                'request_id' => $body['request_id'],
                'resource' => $payload->resource->value,
                'url' => $url,
                'status' => $response->status(),
                'duration_ms' => round($duration, 2),
            ]);

            return new WebhookResponse(
                success: true,
                statusCode: $response->status(),
                body: $response->body(),
                headers: $response->headers(),
                duration: $duration,
                attempts: 1,
            );
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::error('Frontend webhook dispatch failed.', [
                'request_id' => $body['request_id'],
                'resource' => $payload->resource->value,
                'url' => $url,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration, 2),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new FrontendWebhookException(
                message: 'Failed to dispatch frontend webhook: ' . $e->getMessage(),
                code: (int) $e->getCode(),
                previous: $e,
            );
        }
    }
}

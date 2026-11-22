<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Enums\Shipping\WebhookRateLimitOutcome;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository;

/**
 * #143: fail-closed webhook limiter keyed by provider and source IP.
 *
 * An actual limit is 429. Any backend/config failure is 503 so no unverified
 * delivery reaches signature verification or the durable inbox.
 */
final class CarrierWebhookRateLimiter
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Repository $config,
    ) {}

    /** @return array{outcome:WebhookRateLimitOutcome,retry_after:int} */
    public function evaluate(string $providerCode, string $sourceIp): array
    {
        $maxAttempts = (int) $this->config->get('shipping.webhook_rate_limit.max_attempts', 60);
        $decaySeconds = (int) $this->config->get('shipping.webhook_rate_limit.decay_seconds', 60);

        if ($maxAttempts < 1 || $decaySeconds < 1) {
            return ['outcome' => WebhookRateLimitOutcome::UNAVAILABLE, 'retry_after' => 60];
        }

        $key = 'shipping-webhook|'.$providerCode.'|'.$sourceIp;

        try {
            if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
                $retryAfter = max(1, $this->limiter->availableIn($key));

                return ['outcome' => WebhookRateLimitOutcome::LIMITED, 'retry_after' => $retryAfter];
            }

            $this->limiter->hit($key, $decaySeconds);

            return ['outcome' => WebhookRateLimitOutcome::ALLOWED, 'retry_after' => 0];
        } catch (\Throwable $e) {
            // Backend unavailable: fail closed. Log only the failure class,
            // never a payload, signature, or secret.
            report($e);

            return ['outcome' => WebhookRateLimitOutcome::UNAVAILABLE, 'retry_after' => $decaySeconds];
        }
    }
}

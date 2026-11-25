<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Enums\Shipping\WebhookRateLimitOutcome;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * #143/#153: fail-closed webhook limiter keyed by provider and trusted source IP.
 *
 * Source IP is derived only through configured trusted proxies. Missing/invalid
 * proxy configuration, malformed IPs, or limiter backend failures return 503 so
 * no unverified delivery reaches signature verification or the durable inbox.
 */
final class CarrierWebhookRateLimiter
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Repository $config,
    ) {}

    /**
     * @param array<string,mixed> $server
     * @return array{outcome:WebhookRateLimitOutcome,retry_after:int}
     */
    public function evaluate(string $providerCode, string $remoteAddr, array $server = []): array
    {
        $maxAttempts = (int) $this->config->get('shipping.webhook_rate_limit.max_attempts', 60);
        $decaySeconds = (int) $this->config->get('shipping.webhook_rate_limit.decay_seconds', 60);

        if ($maxAttempts < 1 || $decaySeconds < 1) {
            return ['outcome' => WebhookRateLimitOutcome::UNAVAILABLE, 'retry_after' => 60];
        }

        $sourceIp = $this->resolveTrustedSourceIp($remoteAddr, $server);
        if ($sourceIp === null) {
            return ['outcome' => WebhookRateLimitOutcome::UNAVAILABLE, 'retry_after' => $decaySeconds];
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

    /**
     * @param array<string,mixed> $server
     */
    private function resolveTrustedSourceIp(string $remoteAddr, array $server): ?string
    {
        $trustedProxies = $this->trustedProxies();
        $remoteAddr = trim($remoteAddr);

        if ($trustedProxies === [] || filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (!IpUtils::checkIp($remoteAddr, $trustedProxies)) {
            // Request did not arrive through a configured trusted proxy boundary.
            return null;
        }

        $forwardedIps = $this->forwardedIps($server);
        if ($forwardedIps === []) {
            return $remoteAddr;
        }

        $clientIp = $remoteAddr;
        foreach (array_reverse($forwardedIps) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return null;
            }

            if (IpUtils::checkIp($ip, $trustedProxies)) {
                $clientIp = $ip;
                continue;
            }

            $clientIp = $ip;
            break;
        }

        return filter_var($clientIp, FILTER_VALIDATE_IP) === false ? null : $clientIp;
    }

    /** @return list<string> */
    private function trustedProxies(): array
    {
        $configured = $this->config->get('shipping.webhook_rate_limit.trusted_proxies', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        if (!is_array($configured)) {
            return [];
        }

        $proxies = [];
        foreach ($configured as $proxy) {
            if (!is_string($proxy)) {
                continue;
            }

            $proxy = trim($proxy);
            if ($proxy === '') {
                continue;
            }

            if (filter_var($proxy, FILTER_VALIDATE_IP) === false
                && !IpUtils::isValidRange($proxy)
            ) {
                return [];
            }

            $proxies[] = $proxy;
        }

        return array_values(array_unique($proxies));
    }

    /**
     * @param array<string,mixed> $server
     * @return list<string>
     */
    private function forwardedIps(array $server): array
    {
        $forwardedFor = $server['HTTP_X_FORWARDED_FOR'] ?? $server['X_FORWARDED_FOR'] ?? null;
        if (!is_string($forwardedFor) || trim($forwardedFor) === '') {
            return [];
        }

        $ips = [];
        foreach (explode(',', $forwardedFor) as $part) {
            $ip = trim($part);
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}

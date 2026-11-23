<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\CarrierWebhookEvent;
use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\InvalidCarrierWebhookPayload;
use App\Exceptions\InvalidCarrierWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * #143: provider-specific HMAC verification over the exact request bytes.
 *
 * The secret/version come only from config/shipping.php (deployment env).
 * Supported schemes are explicit: an unregistered provider or version fails
 * closed. Nothing here parses JSON before the signature is proven.
 */
final class CarrierWebhookVerifier
{
    /** @var array<string,array{header:string,algorithm:string}> */
    private const SCHEMES = [
        // Local placeholder provider. Real carriers get an explicit entry plus
        // recorded fixture evidence before any production traffic is enabled.
        'null' => ['header' => 'X-Shipping-Signature', 'algorithm' => 'sha256'],
    ];

    private const SUPPORTED_VERSIONS = ['v1'];

    public function __construct(
        private readonly Repository $config,
        private readonly ShippingWebhookFingerprint $fingerprints,
    ) {}

    public function signatureHeader(string $providerCode): string
    {
        return $this->resolveProvider($providerCode)['header'];
    }

    public function verify(string $providerCode, string $rawPayload, ?string $signature): CarrierWebhookEvent
    {
        $context = $this->resolveProvider($providerCode);
        $this->assertSignature($context, $rawPayload, $signature);

        $body = json_decode($rawPayload, true);
        if (!is_array($body)) {
            throw new InvalidCarrierWebhookPayload('Carrier webhook body is not a JSON object.');
        }

        return $this->normalize($providerCode, $context, $body);
    }

    /**
     * @param array{header:string,algorithm:string,secret:string,version:string,mode:string,account_code:string} $context
     */
    private function assertSignature(array $context, string $rawPayload, ?string $signature): void
    {
        if ($rawPayload === '') {
            throw new InvalidCarrierWebhookPayload('Carrier webhook body is empty.');
        }
        if ($signature === null || trim($signature) === '') {
            throw new InvalidCarrierWebhookSignature('Carrier webhook signature is missing.');
        }

        $prefix = $context['version'].'=';
        if (!str_starts_with($signature, $prefix)) {
            throw new InvalidCarrierWebhookSignature('Carrier webhook signature version does not match configuration.');
        }

        $presented = strtolower(substr($signature, strlen($prefix)));
        if (preg_match('/^[a-f0-9]{64}$/', $presented) !== 1) {
            throw new InvalidCarrierWebhookSignature('Carrier webhook signature is malformed.');
        }

        $expected = hash_hmac($context['algorithm'], $rawPayload, $context['secret']);
        if (!hash_equals($expected, $presented)) {
            throw new InvalidCarrierWebhookSignature('Carrier webhook signature is invalid.');
        }
    }

    /** @return array{header:string,algorithm:string,secret:string,version:string,mode:string,account_code:string} */
    private function resolveProvider(string $providerCode): array
    {
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $providerCode) !== 1) {
            throw CarrierConfigurationException::unknownProvider($providerCode);
        }

        $scheme = self::SCHEMES[$providerCode] ?? null;
        if ($scheme === null) {
            throw CarrierConfigurationException::webhookNotConfigured($providerCode);
        }

        $provider = $this->config->get("shipping.providers.{$providerCode}");
        if (!is_array($provider)) {
            throw CarrierConfigurationException::unknownProvider($providerCode);
        }

        $mode = $provider['mode'] ?? $this->config->get('shipping.default_mode', 'sandbox');
        $block = is_string($mode) && is_array($provider['modes'] ?? null)
            ? ($provider['modes'][$mode] ?? null)
            : null;
        if (!is_array($block) || ($block['enabled'] ?? false) !== true) {
            throw CarrierConfigurationException::disabled($providerCode);
        }

        $webhook = is_array($block['webhook'] ?? null) ? $block['webhook'] : [];
        $secret = $webhook['secret'] ?? null;
        $version = $webhook['version'] ?? null;
        $account = $provider['account_code'] ?? null;

        if (!is_string($secret) || trim($secret) === ''
            || !is_string($account) || trim($account) === ''
        ) {
            throw CarrierConfigurationException::webhookNotConfigured($providerCode);
        }
        if (!is_string($version) || !in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw CarrierConfigurationException::unsupportedWebhookVersion($providerCode, is_string($version) ? $version : 'unknown');
        }

        return [
            'header' => $scheme['header'],
            'algorithm' => $scheme['algorithm'],
            'secret' => $secret,
            'version' => $version,
            'mode' => $mode,
            'account_code' => $account,
        ];
    }

    /**
     * @param array{header:string,algorithm:string,secret:string,version:string,mode:string,account_code:string} $context
     * @param array<string,mixed> $body
     */
    private function normalize(string $providerCode, array $context, array $body): CarrierWebhookEvent
    {
        $identity = $this->extractIdentity($body);
        $eventKey = $this->eventKey($providerCode, $context, $identity, $body);

        return new CarrierWebhookEvent(
            $providerCode,
            $context['mode'],
            $this->externalIds($identity, $eventKey),
            null,
            'redacted:'.hash('sha256', $eventKey),
            $eventKey,
            $identity['event_type'],
            $context['version'],
            $context['account_code'],
            $identity['provider_shipment_ref'],
            $identity['occurred_at'],
            $this->redactedPayload($context, $identity, $eventKey),
            $identity['shipment_uuid'],
            $identity['status'],
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{event_id:?string,event_type:string,status:string,object_ref:?string,provider_shipment_ref:?string,shipment_uuid:?string,occurred_at:CarbonImmutable}
     */
    private function extractIdentity(array $body): array
    {
        $occurredRaw = $this->stringField($body, ['occurred_at', 'created_at', 'timestamp', 'data.occurred_at']);

        return [
            'event_id' => $this->stringField($body, ['id', 'event_id', 'data.id']),
            'event_type' => $this->stringField($body, ['type', 'event_type', 'event', 'status']) ?? 'unknown',
            'status' => $this->stringField($body, ['status', 'state', 'data.status']) ?? 'unknown',
            'object_ref' => $this->stringField($body, ['data.object.id', 'data.shipment.id', 'data.id']),
            'provider_shipment_ref' => $this->stringField($body, [
                'data.shipment.id',
                'shipment_id',
                'data.shipment.tracking_number',
                'tracking_number',
                'provider_shipment_ref',
            ]),
            'shipment_uuid' => $this->stringField($body, ['metadata.shipment_uuid', 'data.metadata.shipment_uuid']),
            'occurred_at' => $this->parseTimestamp($occurredRaw) ?? CarbonImmutable::now(),
        ];
    }

    /**
     * @param array{header:string,algorithm:string,secret:string,version:string,mode:string,account_code:string} $context
     * @param array{event_id:?string,event_type:string,status:string,object_ref:?string,provider_shipment_ref:?string,shipment_uuid:?string,occurred_at:CarbonImmutable} $identity
     * @param array<string,mixed> $body
     */
    private function eventKey(string $providerCode, array $context, array $identity, array $body): string
    {
        if ($identity['event_id'] !== null) {
            return $identity['event_id'];
        }

        $normalized = $this->fingerprintFields($identity, $body);
        $candidates = $this->fingerprints->candidates(
            $providerCode,
            $context['mode'],
            $context['account_code'],
            $normalized,
        );

        $legacy = $this->legacyFallbackFingerprint($providerCode, $context, $identity);
        if (! in_array($legacy, $candidates, true)) {
            $candidates[] = $legacy;
        }

        foreach ($candidates as $candidate) {
            if ($this->existingFingerprint($providerCode, $context['mode'], $context['account_code'], $candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * Pre-#159 fallback format for dedupe continuity. Must match the legacy
     * fp:<sha256> identity exactly; never used as the primary format for new rows.
     *
     * @param array{header:string,algorithm:string,secret:string,version:string,mode:string,account_code:string} $context
     * @param array{event_id:?string,event_type:string,status:string,object_ref:?string,provider_shipment_ref:?string,shipment_uuid:?string,occurred_at:CarbonImmutable} $identity
     */
    private function legacyFallbackFingerprint(string $providerCode, array $context, array $identity): string
    {
        return 'fp:'.hash('sha256', implode('|', [
            $providerCode,
            $context['mode'],
            $context['account_code'],
            $identity['object_ref'] ?? 'no-object',
            $identity['event_type'],
            $identity['status'],
        ]));
    }

    /** @param array<string,mixed> $body
     *  @param array{event_id:?string,event_type:string,status:string,object_ref:?string,provider_shipment_ref:?string,shipment_uuid:?string,occurred_at:CarbonImmutable} $identity
     *  @return array<string,string>
     */
    private function fingerprintFields(array $identity, array $body): array
    {
        // #159: normalized, allowlisted fields only. Never raw bytes, receipt
        // time, PII, or mutable local processing fields.
        $fields = [
            'object_ref' => $identity['object_ref'] ?? '',
            'provider_shipment_ref' => $identity['provider_shipment_ref'] ?? '',
            'shipment_uuid' => $identity['shipment_uuid'] ?? '',
            'status' => $identity['status'],
            'event_type' => $identity['event_type'],
        ];

        // Keep only bounded scalar allowlisted values; reject PII-like keys even
        // when providers send them in odd locations by never reading them.
        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }

    private function existingFingerprint(string $providerCode, string $mode, string $accountCode, string $candidate): bool
    {
        return \App\Models\Shipping\ShippingWebhookEvent::query()
            ->where('provider_code', $providerCode)
            ->where('mode', $mode)
            ->where('account_code', $accountCode)
            ->where('event_key', $candidate)
            ->exists();
    }

    /**
     * @param array{header:string,algorithm:string,secret:string,version:string,mode:string,account_code:string} $context
     * @param array{event_id:?string,event_type:string,status:string,object_ref:?string,provider_shipment_ref:?string,shipment_uuid:?string,occurred_at:CarbonImmutable} $identity
     * @return array<string,string>
     */
    private function redactedPayload(array $context, array $identity, string $eventKey): array
    {
        // #159: explicit allowlist. PII keys/values are never stored.
        $candidate = [
            'event_key' => $eventKey,
            'event_type' => $identity['event_type'],
            'status' => $identity['status'],
            'provider_shipment_ref' => $identity['provider_shipment_ref'],
            'shipment_uuid' => $identity['shipment_uuid'],
            'occurred_at' => $identity['occurred_at']->toIso8601String(),
            'webhook_version' => $context['version'],
        ];

        $redacted = [];
        foreach ($candidate as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $clean = $this->redactPiiValue($key, $value);
            if ($clean !== null) {
                $redacted[$key] = $clean;
            }
        }

        return $redacted;
    }

    private function redactPiiValue(string $key, string $value): ?string
    {
        $keyLower = strtolower($key);
        if (preg_match('/email|phone|mobile|address|street|city|postal|zip|national|nid|ssn|passport|iban/', $keyLower) === 1) {
            return '[REDACTED]';
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return '[REDACTED_EMAIL]';
        }

        // Phone-like values with explicit separators/+; avoid redacting plain numeric refs.
        $digits = preg_replace('/\D+/', '', $value);
        if (is_string($digits) && strlen($digits) >= 10 && strlen($digits) <= 15
            && preg_match('/^\+[\d\s().-]{9,19}$/', $value) === 1) {
            return '[REDACTED_PHONE]';
        }

        return strlen($value) > 191 ? substr($value, 0, 191) : $value;
    }

    /**
     * @param array{event_id:?string,event_type:string,status:string,object_ref:?string,provider_shipment_ref:?string,shipment_uuid:?string,occurred_at:CarbonImmutable} $identity
     * @return array<string,string>
     */
    private function externalIds(array $identity, string $eventKey): array
    {
        return array_filter([
            'event' => $eventKey,
            'shipment' => $identity['provider_shipment_ref'],
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /** @param array<string,mixed> $body */
    private function stringField(array $body, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($body, $path);
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function parseTimestamp(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

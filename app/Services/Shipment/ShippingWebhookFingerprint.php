<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Exceptions\CarrierConfigurationException;
use Illuminate\Contracts\Config\Repository;

final class ShippingWebhookFingerprint
{
    public const VERSION_PREFIX = 'fp:';

    public function __construct(private readonly Repository $config) {}

    /**
     * @param array<string,string> $normalizedFields
     * @return list<string>
     */
    public function candidates(string $providerCode, string $mode, string $accountCode, array $normalizedFields): array
    {
        $versions = $this->versions();
        $current = $this->currentVersion();
        $ordered = array_values(array_unique([$current, ...array_keys($versions)]));

        $candidates = [];
        foreach ($ordered as $version) {
            $secret = $versions[$version] ?? null;
            if (! is_string($secret) || trim($secret) === '') {
                if ($version === $current) {
                    throw CarrierConfigurationException::webhookNotConfigured($providerCode);
                }
                continue;
            }

            $candidates[] = $this->format($version, $this->hash($version, $secret, $providerCode, $mode, $accountCode, $normalizedFields));
        }

        if ($candidates === []) {
            throw CarrierConfigurationException::webhookNotConfigured($providerCode);
        }

        return array_values(array_unique($candidates));
    }

    public function current(string $providerCode, string $mode, string $accountCode, array $normalizedFields): string
    {
        return $this->candidates($providerCode, $mode, $accountCode, $normalizedFields)[0];
    }

    /** @return array<string,string> */
    private function versions(): array
    {
        $configured = $this->config->get('shipping.webhook_fingerprints.keys', []);
        if (! is_array($configured)) {
            return [];
        }

        $versions = [];
        foreach ($configured as $version => $secret) {
            if (! is_string($version) || trim($version) === '' || ! is_string($secret) || trim($secret) === '') {
                continue;
            }
            $versions[trim($version)] = $secret;
        }

        return $versions;
    }

    private function currentVersion(): string
    {
        $version = $this->config->get('shipping.webhook_fingerprints.current_version', 'v1');
        return is_string($version) && trim($version) !== '' ? trim($version) : 'v1';
    }

    /** @param array<string,string> $normalizedFields */
    private function hash(string $version, string $secret, string $providerCode, string $mode, string $accountCode, array $normalizedFields): string
    {
        ksort($normalizedFields);
        $canonical = json_encode([
            'version' => $version,
            'provider' => $providerCode,
            'mode' => $mode,
            'account' => $accountCode,
            'fields' => $normalizedFields,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $canonical === false ? '' : $canonical, $secret);
    }

    private function format(string $version, string $hash): string
    {
        return self::VERSION_PREFIX.$version.':'.$hash;
    }
}

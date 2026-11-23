<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\ProviderStatusMapping;
use App\Enums\ShipmentStatus;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Support\ProviderCode;
use Illuminate\Contracts\Config\Repository;

/**
 * #151: versioned, provider-specific raw-status translation.
 *
 * Maps exist only where deployment configuration registers them explicitly.
 * Unknown, ambiguous, lost/damaged, and invalid-map cases never produce a
 * canonical status; the caller must create an internal exception instead.
 */
final class CarrierStatusMapper
{
    public function __construct(private readonly Repository $config) {}

    public function map(string $providerCode, string $mode, ?string $rawStatus): ProviderStatusMapping
    {
        $map = $this->resolveMap($providerCode, $mode);
        if ($map['error'] !== null) {
            return $this->exception($map['error'], $map['version']);
        }

        $normalized = $this->normalize($rawStatus);
        if ($normalized === '' || in_array($normalized, ['unknown', 'none', 'null'], true)) {
            return $this->exception(ProviderEventExceptionType::UNKNOWN_STATUS, $map['version']);
        }
        if ($this->isLostOrDamaged($normalized)) {
            return $this->exception(ProviderEventExceptionType::LOST_OR_DAMAGED, $map['version']);
        }

        $candidates = $this->candidates($map['statuses'], $normalized);
        if ($candidates === []) {
            return $this->exception(ProviderEventExceptionType::UNKNOWN_STATUS, $map['version']);
        }
        if (count($candidates) > 1) {
            return $this->exception(ProviderEventExceptionType::AMBIGUOUS_STATUS, $map['version']);
        }

        $mappedStatus = array_key_first($candidates);
        if (!is_string($mappedStatus) || ShipmentStatus::tryFrom($mappedStatus) === null) {
            return $this->exception(ProviderEventExceptionType::INVALID_MAPPING_CONFIG, $map['version']);
        }

        return new ProviderStatusMapping($mappedStatus, $map['version'], null);
    }

    /**
     * @return array{error: ?ProviderEventExceptionType, version: string, statuses: array<string,mixed>}
     */
    private function resolveMap(string $providerCode, string $mode): array
    {
        $invalid = static fn (?ProviderEventExceptionType $error): array => [
            'error' => $error,
            'version' => '',
            'statuses' => [],
        ];

        if (!ProviderCode::isValid($providerCode)) {
            return $invalid(ProviderEventExceptionType::UNREGISTERED_PROVIDER);
        }
        if (preg_match('/^[a-z0-9_-]{1,16}$/', $mode) !== 1) {
            return $invalid(ProviderEventExceptionType::INVALID_MAPPING_CONFIG);
        }

        $provider = $this->config->get("shipping.providers.{$providerCode}");
        if (!is_array($provider)) {
            return $invalid(ProviderEventExceptionType::UNREGISTERED_PROVIDER);
        }

        $block = $provider['modes'][$mode] ?? null;
        $statusMap = is_array($block) ? ($block['status_map'] ?? null) : null;
        if (!is_array($statusMap)) {
            return $invalid(ProviderEventExceptionType::INVALID_MAPPING_CONFIG);
        }

        $version = $this->version($statusMap['version'] ?? null);
        $statuses = $statusMap['statuses'] ?? null;
        if ($version === '' || !is_array($statuses)) {
            return ['error' => ProviderEventExceptionType::INVALID_MAPPING_CONFIG, 'version' => $version, 'statuses' => []];
        }

        return ['error' => null, 'version' => $version, 'statuses' => $statuses];
    }

    /**
     * @param  array<string,mixed>  $statuses
     * @return array<string,list<string>>
     */
    private function candidates(array $statuses, string $normalized): array
    {
        $candidates = [];
        foreach ($statuses as $rawKey => $canonical) {
            if (!is_string($rawKey) || !is_string($canonical)) {
                continue;
            }
            if ($this->normalize($rawKey) === $normalized) {
                $candidates[$canonical][] = $rawKey;
            }
        }

        return $candidates;
    }

    private function isLostOrDamaged(string $normalized): bool
    {
        // Lost and damaged outcomes stay exceptions by policy, even if a
        // deployed map entry tries to translate them.
        return preg_match('/(?:^|_)(?:lost|damage|damaged)(?:_|$)/', $normalized) === 1;
    }

    private function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))) ?? '';

        return trim($normalized, '_');
    }

    private function version(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    private function exception(ProviderEventExceptionType $type, string $version): ProviderStatusMapping
    {
        return new ProviderStatusMapping(null, $version, $type);
    }
}

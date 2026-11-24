<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Enums\Shipping\ProviderMode;
use App\Exceptions\CarrierConfigurationException;
use App\Support\ProviderCode;
use Illuminate\Contracts\Config\Repository;

final class ProviderPollingCapabilityResolver
{
    public const CURSOR_TYPES = ['timestamp', 'token', 'opaque', 'none'];
    public const PAGINATION_TYPES = ['page', 'cursor', 'offset', 'none'];
    private const MAX_OVERLAP_SECONDS = 86400;

    public function __construct(private readonly Repository $config) {}

    /**
     * Strict polling-consumer boundary: a provider without an explicit
     * declaration fails closed.
     *
     * @return array{supported:bool,cursor_type:?string,pagination:?string,overlap_window_seconds:int}
     */
    public function forProvider(string $providerCode, string $mode): array
    {
        $code = trim($providerCode);
        if (!ProviderCode::isValid($code)) {
            throw CarrierConfigurationException::unknownProvider($providerCode);
        }

        $mode = $this->validatedMode($code, $mode);
        $provider = $this->config->get("shipping.providers.{$code}");
        if (!is_array($provider)) {
            throw CarrierConfigurationException::unknownProvider($code);
        }

        $polling = $provider['polling'] ?? null;
        $block = is_array($provider['modes'] ?? null) ? ($provider['modes'][$mode] ?? null) : null;
        if (is_array($block) && array_key_exists('polling', $block)) {
            $polling = $block['polling'];
        }

        if (!is_array($polling)) {
            throw CarrierConfigurationException::invalidPollingCapability($code);
        }

        $supported = $polling['supported'] ?? null;
        $cursorType = $polling['cursor_type'] ?? null;
        $pagination = $polling['pagination'] ?? null;
        $overlap = $polling['overlap_window_seconds'] ?? null;

        if (!is_bool($supported)) {
            throw CarrierConfigurationException::invalidPollingCapability($code);
        }

        $overlapSeconds = $this->validateOverlap($code, $overlap);

        if ($supported === false) {
            if ($cursorType !== null || $pagination !== null || $overlapSeconds !== 0) {
                throw CarrierConfigurationException::invalidPollingCapability($code);
            }

            return [
                'supported' => false,
                'cursor_type' => null,
                'pagination' => null,
                'overlap_window_seconds' => 0,
            ];
        }

        if (!is_string($cursorType) || !in_array($cursorType, self::CURSOR_TYPES, true)) {
            throw CarrierConfigurationException::invalidPollingCapability($code);
        }
        if (!is_string($pagination) || !in_array($pagination, self::PAGINATION_TYPES, true)) {
            throw CarrierConfigurationException::invalidPollingCapability($code);
        }

        return [
            'supported' => true,
            'cursor_type' => $cursorType,
            'pagination' => $pagination,
            'overlap_window_seconds' => $overlapSeconds,
        ];
    }

    /**
     * Config-read boundary for surfaces that do not require polling (status,
     * assignment, gateway creation). A missing declaration is tolerated for
     * backwards compatibility; a present but invalid declaration fails closed.
     *
     * @return array{supported:bool,cursor_type:?string,pagination:?string,overlap_window_seconds:int}|null
     */
    public function forProviderIfDeclared(string $providerCode, string $mode): ?array
    {
        $code = trim($providerCode);
        $mode = $this->validatedMode($code, $mode);
        $provider = $this->config->get("shipping.providers.{$code}");
        if (!is_array($provider)) {
            return null;
        }

        $declared = array_key_exists('polling', $provider);
        $block = is_array($provider['modes'] ?? null) ? ($provider['modes'][$mode] ?? null) : null;
        if (is_array($block) && array_key_exists('polling', $block)) {
            $declared = true;
        }
        if (!$declared) {
            return null;
        }

        return $this->forProvider($code, $mode);
    }

    private function validatedMode(string $providerCode, string $mode): string
    {
        $mode = trim($mode);
        $parsed = ProviderMode::tryFrom($mode);

        if ($parsed === null || !$parsed->isAllowedFor($providerCode)) {
            throw CarrierConfigurationException::invalidMode($providerCode, $mode);
        }

        return $mode;
    }

    private function validateOverlap(string $providerCode, mixed $overlap): int
    {
        // int only: no numeric-string or float coercion.
        if (!is_int($overlap) || $overlap < 0 || $overlap > self::MAX_OVERLAP_SECONDS) {
            throw CarrierConfigurationException::invalidPollingCapability($providerCode);
        }

        return $overlap;
    }
}

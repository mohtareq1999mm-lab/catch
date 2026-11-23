<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\CarrierGatewayContext;
use App\Enums\SettlementMode;
use App\Enums\Shipping\CarrierCapability;
use App\Exceptions\CarrierConfigurationException;
use App\Models\Shipment;
use App\Services\Shipment\Contracts\CarrierGatewayContract;
use Illuminate\Contracts\Config\Repository;

/**
 * Resolves carrier adapters from deployment configuration only.
 *
 * The factory is intentionally the only place that reads carrier config:
 * adapters receive a non-secret context and never read credentials.
 */
class CarrierGatewayFactory
{
    public function __construct(private readonly Repository $config) {}

    public function make(
        string $providerCode,
        ?CarrierCapability $capability = null,
        ?string $channel = null,
        ?string $geography = null,
    ): CarrierGatewayContract {
        $provider = $this->requireProvider($providerCode);
        $modes = is_array($provider['modes'] ?? null) ? $provider['modes'] : [];
        $this->assertSingleMode($providerCode, $modes);

        $mode = $this->validatedMode($providerCode, $provider);
        $block = $this->requireModeBlock($providerCode, $modes, $mode);
        $this->requireCredentials($providerCode, $block);
        $this->requireCapabilityVerification($providerCode, $block, $capability);

        $channel = $this->requireChannel($providerCode, $provider, $channel);
        $geography = $this->requireGeography($providerCode, $provider, $geography);
        $adapterClass = $this->requireAdapterClass($providerCode, $provider, $capability);
        $context = self::buildContext($providerCode, $provider, $block, $mode, $channel, $geography);

        return $this->instantiate($providerCode, $adapterClass, $context);
    }

    public function hasProvider(string $providerCode): bool
    {
        $providers = $this->config->get('shipping.providers', []);

        return is_array($providers) && array_key_exists($providerCode, $providers);
    }

    /**
     * #149: the single, validated mode a provider may operate in. Mixed
     * sandbox/production configuration fails closed before any mode is used.
     */
    public function resolveMode(string $providerCode): string
    {
        $provider = $this->requireProvider($providerCode);
        $modes = is_array($provider['modes'] ?? null) ? $provider['modes'] : [];
        $this->assertSingleMode($providerCode, $modes);

        return $this->validatedMode($providerCode, $provider);
    }

    /**
     * #149: every later operation must use the mode snapshotted on the
     * shipment, never a current config flag or a caller-supplied mode.
     */
    public function assertShipmentMode(Shipment $shipment, string $mode): void
    {
        $snapshot = $shipment->provider_mode;

        if (!is_string($snapshot) || $snapshot === '') {
            throw CarrierConfigurationException::modeSnapshotMissing((int) $shipment->id);
        }
        if ($snapshot !== $mode) {
            throw CarrierConfigurationException::modeMismatch(
                is_string($shipment->provider_code) ? $shipment->provider_code : '',
                $snapshot,
                $mode,
            );
        }
    }

    public function makeForShipment(
        Shipment $shipment,
        ?CarrierCapability $capability = null,
        ?string $channel = null,
        ?string $geography = null,
    ): CarrierGatewayContract {
        $providerCode = is_string($shipment->provider_code) ? $shipment->provider_code : '';

        $this->assertShipmentMode($shipment, $this->resolveMode($providerCode));

        return $this->make($providerCode, $capability, $channel, $geography);
    }

    /** @param array<string,mixed> $provider */
    private function validatedMode(string $providerCode, array $provider): string
    {
        $configured = $provider['mode'] ?? $this->config->get('shipping.default_mode', 'sandbox');
        $mode = is_string($configured) ? trim($configured) : '';

        if ($mode === '' || (SettlementMode::tryFrom($mode) === null && $mode !== 'internal')) {
            throw CarrierConfigurationException::invalidMode($providerCode, $mode);
        }

        return $mode;
    }

    /**
     * Read-only configuration projection used by the admin status surface.
     * No credentials, URLs, queues, secrets or verification evidence are kept.
     *
     * @return array{provider:string,mode:string,enabled:bool,verified_capabilities:list<string>}
     */
    public function status(string $providerCode): array
    {
        $provider = $this->config->get("shipping.providers.{$providerCode}");
        $mode = is_array($provider) && is_string($provider['mode'] ?? null) ? $provider['mode'] : 'sandbox';
        $modes = is_array($provider) && is_array($provider['modes'] ?? null) ? $provider['modes'] : [];
        $block = $modes[$mode] ?? null;

        return [
            'provider' => $providerCode,
            'mode' => $mode,
            'enabled' => is_array($block) && ($block['enabled'] ?? false) === true,
            'verified_capabilities' => is_array($block) ? self::verifiedCapabilities($block) : [],
        ];
    }

    /** @return list<array{provider:string,mode:string,enabled:bool,verified_capabilities:list<string>}> */
    public function statuses(): array
    {
        $providers = $this->config->get('shipping.providers', []);

        if (!is_array($providers)) {
            return [];
        }

        $statuses = [];
        foreach (array_keys($providers) as $providerCode) {
            $statuses[] = $this->status((string) $providerCode);
        }

        return $statuses;
    }

    /** @return array<string,mixed> */
    private function requireProvider(string $providerCode): array
    {
        $provider = $this->config->get("shipping.providers.{$providerCode}");

        if (!is_array($provider)) {
            throw CarrierConfigurationException::unknownProvider($providerCode);
        }

        return $provider;
    }

    /** @param array<string,mixed> $modes */
    private function assertSingleMode(string $providerCode, array $modes): void
    {
        $active = array_filter(
            $modes,
            static fn (mixed $block): bool => is_array($block)
                && (($block['enabled'] ?? false) === true || self::hasCredentials($block))
        );

        if (count($active) > 1) {
            throw CarrierConfigurationException::mixedModes($providerCode);
        }
    }

    /** @param array<string,mixed> $modes @return array<string,mixed> */
    private function requireModeBlock(string $providerCode, array $modes, string $mode): array
    {
        $block = $modes[$mode] ?? null;

        if (!is_array($block) || ($block['enabled'] ?? false) !== true) {
            throw CarrierConfigurationException::disabled($providerCode);
        }

        return $block;
    }

    /** @param array<string,mixed> $block */
    private function requireCredentials(string $providerCode, array $block): void
    {
        $credentials = $block['credentials'] ?? [];

        if (!is_array($credentials) || $credentials === []) {
            throw CarrierConfigurationException::missingCredentials($providerCode);
        }
        foreach ($credentials as $credential) {
            if (!is_string($credential) || trim($credential) === '') {
                throw CarrierConfigurationException::missingCredentials($providerCode);
            }
        }
    }

    /** @param array<string,mixed> $block */
    private function requireCapabilityVerification(
        string $providerCode,
        array $block,
        ?CarrierCapability $capability,
    ): void {
        if ($capability !== null && !self::isVerified($block, $capability)) {
            throw CarrierConfigurationException::unverifiedCapability($providerCode, $capability->value);
        }
    }

    /** @param array<string,mixed> $provider */
    private function requireChannel(string $providerCode, array $provider, ?string $channel): string
    {
        $allowed = self::stringList($provider['channels'] ?? []);
        $channel ??= $allowed[0] ?? '';

        if (!in_array($channel, $allowed, true)) {
            throw CarrierConfigurationException::channelNotConfigured($providerCode, $channel);
        }

        return $channel;
    }

    /** @param array<string,mixed> $provider */
    private function requireGeography(string $providerCode, array $provider, ?string $geography): string
    {
        $allowed = self::stringList($provider['geographies'] ?? []);
        $geography = $geography === null ? ($allowed[0] ?? '') : strtoupper($geography);

        if (!in_array($geography, $allowed, true)) {
            throw CarrierConfigurationException::geographyNotConfigured($providerCode, $geography);
        }

        return $geography;
    }

    /** @param array<string,mixed> $provider @return class-string */
    private function requireAdapterClass(
        string $providerCode,
        array $provider,
        ?CarrierCapability $capability,
    ): string {
        $adapterClass = $provider['adapter'] ?? null;

        if (!is_string($adapterClass) || !class_exists($adapterClass)) {
            throw CarrierConfigurationException::invalidAdapter($providerCode);
        }
        if ($capability !== null && !is_a($adapterClass, $capability->contract(), true)) {
            throw CarrierConfigurationException::adapterMissingCapability($providerCode, $capability->value);
        }

        return $adapterClass;
    }

    /** @param class-string $adapterClass */
    private function instantiate(
        string $providerCode,
        string $adapterClass,
        CarrierGatewayContext $context,
    ): CarrierGatewayContract {
        $gateway = app()->make($adapterClass, ['context' => $context]);

        if (!$gateway instanceof CarrierGatewayContract) {
            throw CarrierConfigurationException::invalidAdapter($providerCode);
        }
        if ($gateway->context()->providerCode !== $context->providerCode
            || $gateway->context()->mode !== $context->mode
        ) {
            throw CarrierConfigurationException::adapterContextMismatch($providerCode);
        }

        return $gateway;
    }

    /** @param array<string,mixed> $provider @param array<string,mixed> $block */
    private static function buildContext(
        string $providerCode,
        array $provider,
        array $block,
        string $mode,
        string $channel,
        string $geography,
    ): CarrierGatewayContext {
        $retry = is_array($block['retry'] ?? null) ? $block['retry'] : [];
        $queues = is_array($block['queues'] ?? null) ? $block['queues'] : [];
        $webhook = is_array($block['webhook'] ?? null) ? $block['webhook'] : [];
        $statusMap = is_array($block['status_map'] ?? null) ? $block['status_map'] : [];

        return new CarrierGatewayContext(
            $providerCode,
            $mode,
            self::stringValue($provider['account_code'] ?? null),
            $channel,
            $geography,
            self::stringValue($block['base_url'] ?? null),
            self::intValue($block['timeout_seconds'] ?? null, 5),
            self::intValue($retry['attempts'] ?? null, 2),
            self::intValue($retry['backoff_ms'] ?? null, 100),
            self::intValue($retry['max_backoff_ms'] ?? null, 1000),
            self::stringValue($queues['dispatch'] ?? null),
            self::stringValue($queues['webhook'] ?? null),
            self::stringValue($webhook['version'] ?? null),
            self::intValue($statusMap['version'] ?? null, 1),
            self::verifiedCapabilities($block),
        );
    }

    /** @param array<string,mixed> $block */
    private static function isVerified(array $block, CarrierCapability $capability): bool
    {
        $verification = $block['capability_verification'] ?? [];
        $record = is_array($verification) ? ($verification[$capability->value] ?? null) : null;

        return is_array($record)
            && is_string($record['verified_at'] ?? null)
            && trim($record['verified_at']) !== ''
            && is_string($record['evidence'] ?? null)
            && trim($record['evidence']) !== '';
    }

    /** @param array<string,mixed> $block @return list<string> */
    private static function verifiedCapabilities(array $block): array
    {
        $verification = $block['capability_verification'] ?? [];
        $verified = [];

        if (is_array($verification)) {
            foreach ($verification as $capability => $record) {
                if (is_string($capability)
                    && CarrierCapability::tryFrom($capability) !== null
                    && is_array($record)
                    && is_string($record['verified_at'] ?? null)
                    && trim($record['verified_at']) !== ''
                    && is_string($record['evidence'] ?? null)
                    && trim($record['evidence']) !== ''
                ) {
                    $verified[] = $capability;
                }
            }
        }

        return $verified;
    }

    /** @param array<string,mixed> $block */
    private static function hasCredentials(array $block): bool
    {
        $credentials = $block['credentials'] ?? [];

        if (!is_array($credentials)) {
            return false;
        }

        foreach ($credentials as $credential) {
            if (is_string($credential) && trim($credential) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $options @return list<string> */
    private static function stringList($options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $values = [];
        foreach ($options as $option) {
            if (is_string($option) && trim($option) !== '') {
                $values[] = $option;
            }
        }

        return $values;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function intValue(mixed $value, int $fallback): int
    {
        return is_int($value) ? $value : $fallback;
    }
}

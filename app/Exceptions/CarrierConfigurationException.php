<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Fail-closed carrier configuration error. Never contains credential values.
 */
class CarrierConfigurationException extends RuntimeException
{
    public static function unknownProvider(string $providerCode): self
    {
        return new self("Unknown carrier provider [{$providerCode}]");
    }

    /** #148: customer-facing service level must be configured, never inferred. */
    public static function missingServiceLevel(string $providerCode): self
    {
        return new self("Carrier provider [{$providerCode}] has no configured service level");
    }

    public static function disabled(string $providerCode): self
    {
        return new self("Carrier provider [{$providerCode}] is disabled");
    }

    public static function missingCredentials(string $providerCode): self
    {
        return new self("Carrier provider [{$providerCode}] is missing credentials");
    }

    public static function mixedModes(string $providerCode): self
    {
        return new self("Carrier provider [{$providerCode}] has mixed sandbox/production configuration");
    }

    public static function unverifiedCapability(string $providerCode, string $capability): self
    {
        return new self("Carrier provider [{$providerCode}] has no verification for capability [{$capability}]");
    }

    public static function channelNotConfigured(string $providerCode, string $channel): self
    {
        return new self("Carrier provider [{$providerCode}] channel [{$channel}] is not configured");
    }

    public static function geographyNotConfigured(string $providerCode, string $geography): self
    {
        return new self("Carrier provider [{$providerCode}] geography [{$geography}] is not configured");
    }

    public static function invalidAdapter(string $providerCode): self
    {
        return new self("Carrier provider [{$providerCode}] has no usable adapter");
    }

    public static function adapterMissingCapability(string $providerCode, string $capability): self
    {
        return new self("Carrier provider [{$providerCode}] adapter does not implement capability [{$capability}]");
    }

    public static function adapterContextMismatch(string $providerCode): self
    {
        return new self("Carrier provider [{$providerCode}] adapter context does not match configuration");
    }

    /** #143: no secret value is ever included in the message. */
    public static function webhookNotConfigured(string $providerCode): self
    {
        return new self("Carrier provider [{$providerCode}] has no usable webhook configuration");
    }

    public static function unsupportedWebhookVersion(string $providerCode, string $version): self
    {
        return new self("Carrier provider [{$providerCode}] has unsupported webhook version [{$version}]");
    }
}

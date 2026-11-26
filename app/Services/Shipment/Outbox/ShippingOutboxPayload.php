<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use InvalidArgumentException;

/**
 * Outbox request payloads are references to other records, never business
 * data. Non-identifier fields are dropped before persistence so a caller
 * cannot accidentally write contact details, money, or secrets into the
 * durable queue.
 */
final class ShippingOutboxPayload
{
    private const IDENTIFIER_KEY = '/^(?:id|[a-z][a-z0-9_]*(?:_id|_uuid|_key))$/';

    private const SENSITIVE_KEY = '/(?:email|phone|mobile|address|name|token|secret|password|passwd|amount|price|total|fee|note|description|url|uri|payload|data|(?:api|secret|access|private|auth)[_-]?key)/i';

    private const SAFE_IDENTIFIER = '/^[A-Za-z0-9][A-Za-z0-9:._-]*$/';

    private const MAX_LENGTH = 191;

    /** @param array<mixed> $payload
     *  @return array<string,int|string>
     */
    public static function redact(array $payload): array
    {
        $identifiers = [];

        foreach ($payload as $key => $identifier) {
            if (self::isIdentifier($key, $identifier)) {
                /** @var int|string $identifier */
                $identifiers[$key] = $identifier;
            }
        }

        ksort($identifiers);

        return $identifiers;
    }

    public static function assertSafeIdentifier(string $identifier, string $field, int $maxLength = self::MAX_LENGTH): void
    {
        if ($identifier === '' || strlen($identifier) > $maxLength || preg_match(self::SAFE_IDENTIFIER, $identifier) !== 1) {
            throw new InvalidArgumentException("Invalid shipping outbox {$field}.");
        }
    }

    /** @param array<mixed> $payload */
    public static function assertIdentifiersOnly(array $payload): void
    {
        foreach ($payload as $key => $identifier) {
            if (self::isIdentifier($key, $identifier)) {
                continue;
            }

            throw new InvalidArgumentException('Shipping outbox payloads must contain identifiers only.');
        }
    }

    private static function isIdentifier(mixed $key, mixed $identifier): bool
    {
        if (! is_string($key)
            || preg_match(self::IDENTIFIER_KEY, $key) !== 1
            || preg_match(self::SENSITIVE_KEY, $key) === 1
        ) {
            return false;
        }

        if (is_int($identifier)) {
            return $identifier > 0;
        }

        if (! is_string($identifier) || $identifier === '' || strlen($identifier) > self::MAX_LENGTH) {
            return false;
        }

        return preg_match(self::SAFE_IDENTIFIER, $identifier) === 1;
    }
}

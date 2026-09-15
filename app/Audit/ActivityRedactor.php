<?php

namespace App\Audit;

/**
 * Centralized redaction for audit payloads.
 *
 * Never persist credentials/secrets. Applied recursively to old/new/context.
 */
final class ActivityRedactor
{
    private const EXACT_KEYS = [
        'password',
        'password_confirmation',
        'remember_token',
        'provider_access_token',
        'webhook_secret',
        'access_token',
        'refresh_token',
        'api_token',
    ];

    private const SUBSTRINGS = [
        'password',
        'credential',
        'secret',
        'api_key',
        'access_token',
    ];

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::isSensitive((string) $key) ? '[REDACTED]' : self::redact($item);
            }

            return $out;
        }

        return $value;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::EXACT_KEYS, true)) {
            return true;
        }

        if (str_ends_with($key, '_token')
            || str_ends_with($key, '_secret')
            || str_ends_with($key, '_key')
            || str_ends_with($key, '_password')
            || str_ends_with($key, '_credentials')) {
            return true;
        }

        foreach (self::SUBSTRINGS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}

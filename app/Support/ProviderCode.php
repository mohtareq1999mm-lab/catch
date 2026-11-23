<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical provider-code policy (#148 / #151).
 *
 * Provider codes are lowercase deployment-configuration keys. The same rule
 * guards config resolution, admin override input, and raw event mapping so a
 * provider name can never be treated differently across those boundaries.
 */
final class ProviderCode
{
    public static function isValid(string $code): bool
    {
        return preg_match('/\A[a-z0-9_-]{1,32}\z/', $code) === 1;
    }
}

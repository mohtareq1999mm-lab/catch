<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Marvel\Enums\Permission as MarvelPermission;
use Spatie\Permission\Models\Permission;

/**
 * Single source of truth for the finance permission boundary.
 *
 * These permissions are deliberately excluded from every "sync all API
 * permissions" seeder path. Finance authority is assigned explicitly through
 * the dedicated role or through an audited, expiring break-glass grant.
 */
final class FinanceAccess
{
    public const ROLE = 'finance';

    public const PERMISSIONS = [
        MarvelPermission::COD_SETTLEMENT_VIEW,
        MarvelPermission::COD_SETTLEMENT_MATCH,
        MarvelPermission::COD_SETTLEMENT_CLOSE,
        MarvelPermission::COD_ADJUSTMENT_APPROVE,
    ];

    /**
     * @return array<int, string>
     */
    public static function permissions(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * API permissions that may be synchronized by broad seeders.
     *
     * Finance permissions are never part of a broad grant: doing so would give
     * settlement closure and COD approval authority to unrelated roles.
     */
    public static function broadlyGrantablePermissions(): Collection
    {
        return Permission::query()
            ->where('guard_name', 'api')
            ->whereNotIn('name', self::permissions())
            ->get();
    }
}

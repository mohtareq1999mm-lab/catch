<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Marvel\Enums\Permission as MarvelPermission;
use Spatie\Permission\Models\Permission;

/**
 * #187: finance authority boundary.
 *
 * These permissions are granular evidence/authority operations. They never
 * include payment status mutation and are deliberately excluded from broad
 * role synchronization.
 */
final class FinanceAuthority
{
    public const ROLE = 'finance';

    /** @var list<string> */
    public const PERMISSIONS = [
        MarvelPermission::COD_SETTLEMENT_VIEW,
    ];

    /** @return list<string> */
    public static function permissions(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * Permissions that broad seeders may synchronize. Finance permissions are
     * assigned only through the dedicated finance role.
     */
    public static function broadlyGrantablePermissions(): Collection
    {
        return Permission::query()
            ->where('guard_name', 'api')
            ->whereNotIn('name', self::permissions())
            ->get();
    }
}

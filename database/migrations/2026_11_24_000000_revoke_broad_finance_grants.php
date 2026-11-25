<?php

use App\Support\FinanceAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #191: earlier broad seeders granted every API permission to admin users and
 * the super_admin role. Remove those inherited finance grants so authority is
 * only held by the dedicated finance role or an explicit break-glass grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        if (! is_array($tables) || ! Schema::hasTable($tables['permissions'] ?? '')) {
            return;
        }

        $permissionIds = DB::table($tables['permissions'])
            ->whereIn('name', FinanceAccess::permissions())
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable($tables['model_has_permissions'] ?? '')) {
            DB::table($tables['model_has_permissions'])
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        if (! Schema::hasTable($tables['roles'] ?? '') || ! Schema::hasTable($tables['role_has_permissions'] ?? '')) {
            return;
        }

        $financeRoleId = DB::table($tables['roles'])
            ->where('name', FinanceAccess::ROLE)
            ->value('id');

        $query = DB::table($tables['role_has_permissions'])
            ->whereIn('permission_id', $permissionIds);

        if ($financeRoleId !== null) {
            $query->where('role_id', '!=', $financeRoleId);
        }

        $query->delete();
    }

    public function down(): void
    {
        // Revoked grants are intentionally not restored.
    }
};

<?php

use App\Support\FinanceAuthority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #187: broad seeders may previously have granted finance permissions to
 * unrelated roles/users. Revoke those inherited grants; only the dedicated
 * finance role (or an explicit later assignment) retains finance authority.
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
            ->whereIn('name', FinanceAuthority::permissions())
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
            ->where('name', FinanceAuthority::ROLE)
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
        // Revoked inherited grants are intentionally not restored.
    }
};

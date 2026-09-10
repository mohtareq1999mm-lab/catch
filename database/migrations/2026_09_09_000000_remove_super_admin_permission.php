<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the legacy `super_admin` permission (guard `api`).
     *
     * `super_admin` is a role, not a permission. The Spatie exception
     * "There is no permission named `super_admin` for guard `api`." is thrown
     * whenever code calls `hasPermissionTo('super_admin')` or uses the
     * `permission:super_admin` middleware while no matching row exists.
     * All runtime checks have been migrated to `hasRole('super_admin')` /
     * `role:super_admin` — this migration cleans the orphan row and its pivots
     * so a fresh `migrate:fresh --seed` never recreates it.
     */
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('name', 'super_admin')
            ->where('guard_name', 'api')
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->delete();

        if (app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Intentionally no-op: super_admin must remain a role, not a permission.
        // Recreating it would reintroduce the guard mismatch.
    }
};

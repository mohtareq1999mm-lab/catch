<?php

namespace Database\Seeders;

use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Database\Seeder;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;

/**
 * Granular per-status transition permissions.
 *
 * - Ensures change-order-status.<code> for every catalog status
 *   (future codes included via OrderFlowService::syncTargetStatusPermissions).
 * - Compatibility bridge (explicit, documented in docs/order-flow/):
 *   roles/users holding the legacy general `update-order-status` receive
 *   any missing granular target permissions additively, so existing
 *   administrators keep exactly their current access. Going forward the
 *   granular permission is additionally required on PATCH — granting only
 *   the general permission no longer suffices for new assignments.
 * - Never removes anything: strictly additive, safe to re-run.
 */
class OrderStatusPermissionSeeder extends Seeder
{
    public function run(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('permissions')) {
            $this->command?->warn('OrderStatusPermissionSeeder skipped: permissions table is missing.');

            return;
        }

        $names = app(OrderFlowService::class)->syncTargetStatusPermissions();

        if (empty($names)) {
            return;
        }

        $general = Permission::query()
            ->where('name', 'update-order-status')
            ->where('guard_name', 'api')
            ->first();

        if (!$general) {
            return;
        }

        // Roles holding the general permission inherit the granular set.
        foreach (Role::query()->whereHas('permissions', fn ($q) => $q->where('permissions.id', $general->getKey()))->get() as $role) {
            foreach ($names as $name) {
                try {
                    if (!$role->hasPermissionTo($name)) {
                        $role->givePermissionTo($name);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        // Direct user grants (non-role holders) inherit the same way.
        foreach ($general->users()->cursor() as $user) {
            foreach ($names as $name) {
                try {
                    if (method_exists($user, 'hasPermissionTo') && !$user->hasPermissionTo($name)) {
                        $user->givePermissionTo($name);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }
}

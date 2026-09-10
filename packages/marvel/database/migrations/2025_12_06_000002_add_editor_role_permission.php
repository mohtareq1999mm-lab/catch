<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $guard = 'api';

        $permissionId = DB::table('permissions')
            ->where('name', PermissionEnum::EDITOR)
            ->where('guard_name', $guard)
            ->value('id');

        if (!$permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => PermissionEnum::EDITOR,
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleId = DB::table('roles')
            ->where('name', RoleEnum::EDITOR)
            ->where('guard_name', $guard)
            ->value('id');

        if (!$roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => RoleEnum::EDITOR,
                'guard_name' => $guard,
                'display_name' => json_encode(['en' => 'Editor', 'ar' => 'محرر']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $linkExists = DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->exists();

        if (!$linkExists) {
            DB::table('role_has_permissions')->insert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $guard = 'api';

        $permissionId = DB::table('permissions')
            ->where('name', PermissionEnum::EDITOR)
            ->where('guard_name', $guard)
            ->value('id');

        $roleId = DB::table('roles')
            ->where('name', RoleEnum::EDITOR)
            ->where('guard_name', $guard)
            ->value('id');

        if ($roleId && $permissionId) {
            DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
        }

        if ($roleId) {
            DB::table('roles')->where('id', $roleId)->delete();
        }

        if ($permissionId) {
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};


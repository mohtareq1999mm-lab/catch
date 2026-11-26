<?php

namespace Database\Seeders;

use App\Support\FinanceAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class AdminSeeder extends Seeder
{
    /**
     * Seed the super_admin role with every system permission and grant it to
     * the admin@demo.com user.
     *
     * Idempotent by design: PermissionSeeder creates missing permissions with
     * firstOrCreate (existing rows are skipped) and the role/user assignments
     * below only create or sync what is missing, so re-running is safe.
     */
    public function run(): void
    {
        // 1. Ensure every system permission exists (skip existing, create missing).
        $this->call(PermissionSeeder::class);

        $allApiPermissions = FinanceAccess::broadlyGrantablePermissions();

        // 2. super_admin role holds 100% of the system permissions.
        $roleSuperAdmin = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Super Admin', 'ar' => 'مدير_النظام']]
        );

        if ($allApiPermissions->isNotEmpty()) {
            $roleSuperAdmin->syncPermissions($allApiPermissions);
        }

        // 3. admin@demo.com user receives the super_admin role and all permissions.
        $user = User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
                'phone_number' => '34567890',
                'email_verified_at' => now(),
                'type' => 'admin',
            ]
        );

        if ($user->type !== 'admin' || ! $user->is_active) {
            $user->update(['type' => 'admin', 'is_active' => true]);
        }

        if (! $user->hasRole('super_admin')) {
            $user->assignRole($roleSuperAdmin);
        }

        if ($allApiPermissions->isNotEmpty()) {
            $user->syncPermissions($allApiPermissions);
        }

        // Bust Spatie's permission cache so the new role/permission state is
        // effective immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

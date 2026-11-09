<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class Admin2UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $allApiPermissions = Permission::where('guard_name', 'api')->get();

        $roleSuperAdmin = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Super Admin', 'ar' => 'مدير_النظام']]
        );

        if ($allApiPermissions->isNotEmpty()) {
            $roleSuperAdmin->syncPermissions($allApiPermissions);
        }

        $user = User::updateOrCreate(
            ['email' => 'admin2@demo.com'],
            [
                'name' => 'Admin 2',
                'password' => Hash::make('password'),
                'is_active' => true,
                'phone_number' => '34567891',
                'email_verified_at' => now(),
                'type' => 'admin',
            ]
        );

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }

        if ($allApiPermissions->isNotEmpty()) {
            $user->syncPermissions($allApiPermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}


<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleAndPermissionTest extends TestCase
{
    use DatabaseTransactions;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('users')) {
            $this->createTables();
        }

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->beginDatabaseTransaction();
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('display_name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('model');
            $table->uuid('uuid')->nullable();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('generated_conversions');
            $table->json('custom_properties');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable();
            $table->nullableTimestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->onDelete('cascade');
            $table->primary(['permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onDelete('cascade');
            $table->primary(['role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->onDelete('cascade');
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onDelete('cascade');
            $table->primary(['permission_id', 'role_id'],
                'role_has_permissions_permission_id_role_id_primary');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });
    }

    private function createSuperAdminUser(): User
    {
        $permissions = [
            PermissionEnum::CREATE_ROLES,
            PermissionEnum::UPDATE_ROLES,
            PermissionEnum::DELETE_ROLES,
            PermissionEnum::VIEW_ROLES,
            PermissionEnum::VIEW_ROLE,
            PermissionEnum::ASSIGN_ROLE,
            PermissionEnum::REMOVE_ROLE];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'display_name' => json_encode(['en' => 'Super Admin', 'ar' => 'مدير النظام']),
            'guard_name' => self::GUARD]);

        foreach ($permissions as $perm) {
            $role->givePermissionTo($perm);
        }

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $user->assignRole($role);

        foreach ($permissions as $perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }

    private function createCustomerUser(): User
    {
        Permission::findOrCreate(PermissionEnum::CUSTOMER, self::GUARD);
        $role = Role::create([
            'name' => RoleEnum::CUSTOMER,
            'display_name' => json_encode(['en' => 'Customer', 'ar' => 'عميل']),
            'guard_name' => self::GUARD]);
        $role->givePermissionTo(PermissionEnum::CUSTOMER);

        $user = User::create([
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $user->assignRole($role);
        $user->givePermissionTo(PermissionEnum::CUSTOMER);

        return $user;
    }

    private function seedPermissions(int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Permission::findOrCreate("test-permission-{$i}", self::GUARD);
        }
    }

    private function seedRoles(int $count = 2): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Role::create([
                'name' => "test_role_{$i}",
                'display_name' => json_encode(['en' => "Test Role {$i}", 'ar' => 'دور اختبار ' . $i]),
                'guard_name' => self::GUARD]);
        }
    }

    // ==================== ROLES ====================

    public function test_super_admin_can_fetch_all_roles(): void
    {
        $user = $this->createSuperAdminUser();
        $this->seedRoles(3);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/roles');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => [
                'data' => [
                    '*' => ['id', 'display_name', 'name', 'guard_name', 'created_at', 'updated_at']],
                'page', 'current_page', 'from', 'to', 'last_page', 'path', 'per_page', 'total',
                'next_page_url', 'prev_page_url', 'last_page_url', 'first_page_url']]);
    }

    public function test_super_admin_can_create_role(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/roles', [
            'display_name' => ['en' => 'Moderator', 'ar' => 'مشرف']]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => ['id', 'display_name']]);
        $response->assertJsonPath('data.display_name', '{"en":"Moderator","ar":"مشرف"}');

        $this->assertDatabaseHas('roles', [
            'name' => 'moderator',
            'guard_name' => self::GUARD]);
    }

    public function test_create_role_validates_display_name(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/roles', [
            'display_name' => 'not-an-array']);

        $response->assertStatus(422);
    }

    public function test_create_role_without_permission_returns_403(): void
    {
        $user = $this->createCustomerUser();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/roles', [
            'display_name' => ['en' => 'Blocked', 'ar' => 'محظور']]);

        $response->assertStatus(403);
    }

    public function test_super_admin_can_update_role(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'temp_role',
            'display_name' => json_encode(['en' => 'Temp Role', 'ar' => 'دور مؤقت']),
            'guard_name' => self::GUARD]);

        $response = $this->putJson(self::PREFIX . "/roles/{$role->id}", [
            'display_name' => ['en' => 'Updated Role', 'ar' => 'دور محدث']]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.display_name', '{"en":"Updated Role","ar":"دور محدث"}');
    }

    public function test_update_nonexistent_role_returns_error(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->putJson(self::PREFIX . '/roles/99999', [
            'display_name' => ['en' => 'Ghost', 'ar' => 'شبح']]);

        $response->assertStatus(404);
    }

    public function test_super_admin_can_delete_role(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'delete_me',
            'display_name' => json_encode(['en' => 'Delete Me', 'ar' => 'احذفني']),
            'guard_name' => self::GUARD]);

        $response = $this->deleteJson(self::PREFIX . "/roles/{$role->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_delete_role_with_assigned_users_returns_conflict(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'protected_role',
            'display_name' => json_encode(['en' => 'Protected', 'ar' => 'محمي']),
            'guard_name' => self::GUARD]);

        $customer = $this->createCustomerUser();
        $customer->assignRole($role);

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_id' => $customer->id]);

        $response = $this->deleteJson(self::PREFIX . "/roles/{$role->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_delete_nonexistent_role_returns_error(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . '/roles/99999');

        $response->assertStatus(404);
    }

    public function test_super_admin_can_fetch_role_by_id(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'visible_role',
            'display_name' => json_encode(['en' => 'Visible Role', 'ar' => 'دور مرئي']),
            'guard_name' => self::GUARD]);
        $perm = Permission::findOrCreate('visible-perm', self::GUARD);
        $role->givePermissionTo($perm);

        $response = $this->getJson(self::PREFIX . "/roles/{$role->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => ['id', 'display_name', 'permissions']]);
        $response->assertJsonPath('data.id', $role->id);
    }

    public function test_show_nonexistent_role_returns_404(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/roles/99999');

        $response->assertStatus(404);
    }

    public function test_get_all_roles_supports_search(): void
    {
        $user = $this->createSuperAdminUser();
        $this->seedRoles(5);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/roles?search=test');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // ==================== PERMISSIONS ====================

    public function test_super_admin_can_fetch_all_permissions(): void
    {
        $user = $this->createSuperAdminUser();
        $this->seedPermissions(3);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/permissions');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => [
                '*' => ['id', 'label']]]);
    }

    public function test_get_permissions_supports_search(): void
    {
        $user = $this->createSuperAdminUser();
        $this->seedPermissions(3);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/permissions?search=test-permission');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // ==================== ASSIGN PERMISSION TO ROLE ====================

    public function test_assign_permissions_to_role(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'editor_role',
            'display_name' => json_encode(['en' => 'Editor Role', 'ar' => 'دور المحرر']),
            'guard_name' => self::GUARD]);

        $perm1 = Permission::findOrCreate('edit-articles', self::GUARD);
        $perm2 = Permission::findOrCreate('publish-articles', self::GUARD);

        $response = $this->postJson(self::PREFIX . "/roles/{$role->id}/permissions", [
            'permissions' => [$perm1->id, $perm2->id]]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => ['id', 'display_name', 'permissions']]);

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $perm1->id]);
    }

    public function test_assign_permissions_to_nonexistent_role_returns_error(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $perm1 = Permission::findOrCreate('test-perm', self::GUARD);

        $response = $this->postJson(self::PREFIX . '/roles/99999/permissions', [
            'permissions' => [$perm1->id]]);

        $response->assertStatus(404);
    }

    public function test_assign_permissions_validates_ids_exist(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'validator_role',
            'display_name' => json_encode(['en' => 'Validator', 'ar' => 'مدقق']),
            'guard_name' => self::GUARD]);

        $response = $this->postJson(self::PREFIX . "/roles/{$role->id}/permissions", [
            'permissions' => [99999]]);

        $response->assertStatus(422);
    }

    public function test_assign_permissions_validates_integer_input(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'validator_role_2',
            'display_name' => json_encode(['en' => 'Validator 2', 'ar' => 'مدقق 2']),
            'guard_name' => self::GUARD]);

        $response = $this->postJson(self::PREFIX . "/roles/{$role->id}/permissions", [
            'permissions' => ['not-an-integer']]);

        $response->assertStatus(422);
    }

    // ==================== ASSIGN ROLE ====================

    public function test_assign_role_to_user(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'mod_role',
            'display_name' => json_encode(['en' => 'Mod Role', 'ar' => 'دور المشرف']),
            'guard_name' => self::GUARD]);

        $targetUser = $this->createCustomerUser();

        $response = $this->postJson(self::PREFIX . "/users/{$targetUser->id}/assign-role", [
            'role_ids' => [$role->id]]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => ['id', 'name', 'email', 'roles', 'permissions']]);

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_id' => $targetUser->id]);
    }

    public function test_assign_role_to_nonexistent_user_returns_error(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'ghost_role',
            'display_name' => json_encode(['en' => 'Ghost', 'ar' => 'شبح']),
            'guard_name' => self::GUARD]);

        $response = $this->postJson(self::PREFIX . '/users/99999/assign-role', [
            'role_ids' => [$role->id]]);

        $response->assertStatus(500);
    }

    public function test_assign_role_validates_role_ids_exist(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $targetUser = $this->createCustomerUser();

        $response = $this->postJson(self::PREFIX . "/users/{$targetUser->id}/assign-role", [
            'role_ids' => [99999]]);

        $response->assertStatus(422);
    }

    public function test_assign_role_without_permission_returns_403(): void
    {
        $user = $this->createCustomerUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'blocked_role',
            'display_name' => json_encode(['en' => 'Blocked', 'ar' => 'محظور']),
            'guard_name' => self::GUARD]);

        $targetUser = User::create([
            'name' => 'Target',
            'email' => 'target@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $response = $this->postJson(self::PREFIX . "/users/{$targetUser->id}/assign-role", [
            'role_ids' => [$role->id]]);

        $response->assertStatus(403);
    }

    // ==================== REMOVE ROLE ====================

    public function test_remove_role_from_user(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => RoleEnum::EDITOR,
            'display_name' => json_encode(['en' => 'Editor', 'ar' => 'محرر']),
            'guard_name' => self::GUARD]);
        $targetUser = $this->createCustomerUser();
        $targetUser->assignRole($role);

        $response = $this->postJson(self::PREFIX . "/users/{$targetUser->id}/remove-role", [
            'role_ids' => [$role->id]]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseMissing('model_has_roles', [
            'role_id' => $role->id,
            'model_id' => $targetUser->id]);
    }

    public function test_remove_role_from_nonexistent_user_returns_error(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => 'ghost_role',
            'display_name' => json_encode(['en' => 'Ghost', 'ar' => 'شبح']),
            'guard_name' => self::GUARD]);

        $response = $this->postJson(self::PREFIX . '/users/99999/remove-role', [
            'role_ids' => [$role->id]]);

        $response->assertStatus(404);
    }

    public function test_remove_role_without_permission_returns_403(): void
    {
        $user = $this->createCustomerUser();
        Sanctum::actingAs($user);

        $role = Role::create([
            'name' => RoleEnum::EDITOR,
            'display_name' => json_encode(['en' => 'Editor', 'ar' => 'محرر']),
            'guard_name' => self::GUARD]);
        $targetUser = User::create([
            'name' => 'Target2',
            'email' => 'target2@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $response = $this->postJson(self::PREFIX . "/users/{$targetUser->id}/remove-role", [
            'role_ids' => [$role->id]]);

        $response->assertStatus(403);
    }

    // ==================== DIRECT USER PERMISSIONS ====================

    public function test_give_permission_to_user(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $perm = Permission::findOrCreate('special-access', self::GUARD);
        $targetUser = $this->createCustomerUser();

        $response = $this->postJson(self::PREFIX . "/users/{$targetUser->id}/permissions", [
            'permissions' => [$perm->id]]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertTrue($targetUser->fresh()->hasPermissionTo('special-access'));
    }

    public function test_sync_permissions_on_user(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $perm1 = Permission::findOrCreate('sync-perm-1', self::GUARD);
        $perm2 = Permission::findOrCreate('sync-perm-2', self::GUARD);
        $targetUser = $this->createCustomerUser();
        $targetUser->givePermissionTo($perm1);

        $response = $this->putJson(self::PREFIX . "/users/{$targetUser->id}/permissions", [
            'permissions' => [$perm2->id]]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $freshUser = $targetUser->fresh();
        $this->assertFalse($freshUser->hasPermissionTo('sync-perm-1'));
        $this->assertTrue($freshUser->hasPermissionTo('sync-perm-2'));
    }

    public function test_remove_permission_from_user(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $perm = Permission::findOrCreate('removable-perm', self::GUARD);
        $targetUser = $this->createCustomerUser();
        $targetUser->givePermissionTo($perm);

        $response = $this->deleteJson(self::PREFIX . "/users/{$targetUser->id}/permissions", [
            'permissions' => [$perm->id]]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertFalse($targetUser->fresh()->hasPermissionTo('removable-perm'));
    }

    public function test_give_permission_validates_ids_exist(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $targetUser = $this->createCustomerUser();

        $response = $this->postJson(self::PREFIX . "/users/{$targetUser->id}/permissions", [
            'permissions' => [99999]]);

        $response->assertStatus(422);
    }

    // ==================== UNAUTHENTICATED ====================

    public function test_unauthenticated_user_cannot_access_roles(): void
    {
        $response = $this->getJson(self::PREFIX . '/roles');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_access_permissions(): void
    {
        $response = $this->getJson(self::PREFIX . '/permissions');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_create_role(): void
    {
        $response = $this->postJson(self::PREFIX . '/roles', [
            'display_name' => ['en' => 'Hacker', 'ar' => 'هاكر']]);
        $response->assertStatus(401);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Events\UserRolesUpdated;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserControllerTest extends TestCase
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

        if (!DB::table('settings')->where('language', 'en')->exists()) {
            DB::table('settings')->insert([
                'language' => 'en',
                'options' => json_encode([
                    'app_settings' => ['trust' => true],
                    'useMustVerifyEmail' => false]),
                'created_at' => now(),
                'updated_at' => now()]);
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
            $table->string('type')->default('user');
            $table->boolean('is_active')->default(true);
            $table->string('phone_number')->nullable()->unique();
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
            $table->string('display_name')->default('');
            $table->string('guard_name');
            $table->timestamps();
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

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
            $table->timestamps();
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->json('avatar')->nullable();
            $table->text('bio')->nullable();
            $table->json('socials')->nullable();
            $table->string('contact')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->foreign('customer_id')->references('id')->on('users');
            $table->timestamps();
        });

        Schema::create('address', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type');
            $table->boolean('default')->default(false);
            $table->json('address');
            $table->json('location')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->foreign('customer_id')->references('id')->on('users');
            $table->timestamps();
        });

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('owner_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('owner_id')->references('id')->on('users');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
    }

    private static int $phoneCounter = 100;

    private function adminAddPayload(array $overrides = []): array
    {
        self::$phoneCounter++;
        return array_merge([
            'name' => 'Admin User',
            'email' => 'admin' . self::$phoneCounter . '@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone_number' => '0100000' . self::$phoneCounter,
            'is_active' => true], $overrides);
    }

    private function createSuperAdminUser(): User
    {
        $permissionNames = [
            PermissionEnum::VIEW_USERS,
            PermissionEnum::CREATE_USER,
            PermissionEnum::DELETE_USER,
            PermissionEnum::EDIT_USER,
            PermissionEnum::BAN_USER,
            PermissionEnum::ACTIVATE_USER,
            PermissionEnum::MAKE_ADMIN,
            PermissionEnum::RESTORE_USER];

        $permissions = collect($permissionNames)->map(fn($name) =>
            \Spatie\Permission\Models\Permission::findOrCreate($name, self::GUARD)
        );

        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);
        $role->syncPermissions($permissions);

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'admin',
            'is_active' => true,
            'phone_number' => '01000000001']);

        $user->assignRole($role);

        return $user;
    }

    private function createRegularUser(): User
    {
        return User::create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'user',
            'is_active' => true,
            'phone_number' => '01000000999']);
    }

    // --- GET /users tests ---

    public function test_super_admin_can_fetch_all_users_when_no_active_filter_passed(): void
    {
        $admin = $this->createSuperAdminUser();

        User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01000000002']);

        User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
            'type' => 'user',
            'phone_number' => '01000000003']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users');

        $response->assertOk();

        $users = $response->json('data.data');
        $emails = collect($users)->pluck('email')->toArray();

        $this->assertContains('active@example.com', $emails);
        $this->assertContains('inactive@example.com', $emails);
    }

    public function test_super_admin_can_filter_active_users(): void
    {
        $admin = $this->createSuperAdminUser();

        User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01000000004']);

        User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
            'type' => 'user',
            'phone_number' => '01000000005']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?active=true');

        $response->assertOk();
        $users = $response->json('data.data');
        $emails = collect($users)->pluck('email')->toArray();

        $this->assertContains('active@example.com', $emails);
        $this->assertNotContains('inactive@example.com', $emails);
    }

    public function test_super_admin_can_filter_inactive_users(): void
    {
        $admin = $this->createSuperAdminUser();

        User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01000000006']);

        User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
            'type' => 'user',
            'phone_number' => '01000000007']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?in_active=true');

        $response->assertOk();
        $users = $response->json('data.data');
        $emails = collect($users)->pluck('email')->toArray();

        $this->assertNotContains('active@example.com', $emails);
        $this->assertContains('inactive@example.com', $emails);
    }

    // --- POST /admin-users/add tests ---

    public function test_super_admin_can_create_admin_user(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
            'is_active' => true,
        'phone_number' => '0100000201']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('status', 200);
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('users', [
            'email' => 'newadmin@example.com',
            'type' => 'admin']);
    }

    public function test_create_admin_user_succeeds_without_roles(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'No Role Admin',
            'email' => 'norole@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone_number' => '0100000202',
            'roles' => []])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'norole@example.com',
            'type' => 'admin']);
    }

    public function test_create_admin_user_fails_with_duplicate_email(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Duplicate',
            'email' => 'superadmin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [],
        'phone_number' => '0100000203'])->assertStatus(422);
    }

    public function test_non_admin_cannot_create_admin_user(): void
    {
        $user = $this->createRegularUser();

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Hacker',
            'email' => 'hacker@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => []])->assertStatus(403);
    }

    public function test_create_admin_user_fails_without_name(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'email' => 'noname@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000204'])->assertStatus(422);
    }

    public function test_create_admin_user_fails_without_email(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'No Email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000205'])->assertStatus(422);
    }

    public function test_create_admin_user_fails_with_invalid_email(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Bad Email',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000206'])->assertStatus(422);
    }

    public function test_create_admin_user_fails_without_password(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'No Password',
            'email' => 'nopassword@example.com',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000207'])->assertStatus(422);
    }

    public function test_create_admin_user_fails_with_short_password(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Short Pwd',
            'email' => 'shortpwd@example.com',
            'password' => '12345',
            'password_confirmation' => '12345',
            'roles' => [$role->id],
        'phone_number' => '0100000208'])->assertStatus(422);
    }

    public function test_create_admin_user_fails_without_password_confirmation(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'No Confirmation',
            'email' => 'noconfirm@example.com',
            'password' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000209'])->assertStatus(422);
    }

    public function test_create_admin_user_fails_with_mismatched_password_confirmation(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different456',
            'roles' => [$role->id],
        'phone_number' => '0100000210'])->assertStatus(422);
    }

    public function test_create_admin_user_fails_with_invalid_is_active(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Invalid Active',
            'email' => 'invalidactive@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
            'is_active' => 'invalid',
        'phone_number' => '0100000211'])->assertStatus(422);
    }

    public function test_create_admin_user_fails_with_nonexistent_role_id(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Bad Role',
            'email' => 'badrole@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [99999],
        'phone_number' => '0100000212'])->assertStatus(422);
    }

    public function test_create_admin_user_with_phone_number(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'With Phone',
            'email' => 'withphone@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
            'phone_number' => '01000000123']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'withphone@example.com',
            'phone_number' => '01000000123']);
    }

    public function test_create_admin_user_with_is_active_false(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Inactive Admin',
            'email' => 'inactiveadmin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
            'is_active' => 0,
        'phone_number' => '0100000213']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'inactiveadmin@example.com',
            'is_active' => false]);
    }

    // --- PUT /admin-users/update-activation tests ---

    public function test_super_admin_can_toggle_user_activation_full_cycle(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $deactivate = $this->putJson(self::PREFIX . '/admin-users/update-activation', [
            'user_id' => $target->id]);

        $deactivate->assertOk();
        $deactivate->assertJsonPath('success', true);
        $deactivate->assertJsonStructure(['status', 'message', 'success']);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => false]);

        $reactivate = $this->putJson(self::PREFIX . '/admin-users/update-activation', [
            'user_id' => $target->id]);

        $reactivate->assertOk();
        $reactivate->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => true]);
    }

    public function test_cannot_deactivate_active_admin_user(): void
    {
        $admin = $this->createSuperAdminUser();

        $otherAdmin = User::create([
            'name' => 'Other Admin',
            'email' => 'other@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'admin',
            'is_active' => true,
            'phone_number' => '01000000998']);

        Sanctum::actingAs($admin);

        $this->putJson(self::PREFIX . '/admin-users/update-activation', [
            'user_id' => $otherAdmin->id])->assertOk();
    }

    public function test_update_activation_fails_without_user_id(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->putJson(self::PREFIX . '/admin-users/update-activation', [])
            ->assertStatus(422);
    }

    public function test_update_activation_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->putJson(self::PREFIX . '/admin-users/update-activation', [
            'user_id' => 99999])->assertStatus(422);
    }

    // --- DELETE /admin-users/delete/{id} tests ---

    public function test_super_admin_can_delete_regular_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->deleteJson(self::PREFIX . '/admin-users/delete/' . $target->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'User deleted successfully');

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_cannot_delete_admin_user(): void
    {
        $admin = $this->createSuperAdminUser();

        $otherAdmin = User::create([
            'name' => 'Other Admin',
            'email' => 'other2@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'admin',
            'is_active' => true,
            'phone_number' => '01000000997']);

        $adminRole = Role::where('name', RoleEnum::SUPER_ADMIN)->first();
        if ($adminRole) {
            $otherAdmin->assignRole($adminRole);
        }

        Sanctum::actingAs($admin);

        $this->deleteJson(self::PREFIX . '/admin-users/delete/' . $otherAdmin->id)
            ->assertStatus(400)
            ->assertJsonPath('message', 'User cannot be deleted');
    }

    public function test_cannot_delete_self(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->deleteJson(self::PREFIX . '/admin-users/delete/' . $admin->id)
            ->assertStatus(400)
            ->assertJsonPath('message', 'User cannot be deleted');
    }

    public function test_delete_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->deleteJson(self::PREFIX . '/admin-users/delete/99999')
            ->assertStatus(404);
    }

    // --- Authorization failure tests ---

    public function test_unauthenticated_user_cannot_access_admin_endpoints(): void
    {
        $this->postJson(self::PREFIX . '/admin-users/add', [])->assertStatus(401);
        $this->putJson(self::PREFIX . '/admin-users/update-activation', [])->assertStatus(401);
        $this->deleteJson(self::PREFIX . '/admin-users/delete/1')->assertStatus(401);
    }

    public function test_regular_user_cannot_access_admin_endpoints(): void
    {
        $user = $this->createRegularUser();

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/admin-users/add', [])->assertStatus(403);
        $this->putJson(self::PREFIX . '/admin-users/update-activation', [])->assertStatus(403);
        $this->deleteJson(self::PREFIX . '/admin-users/delete/1')->assertStatus(403);
    }

    // --- Response structure tests ---

    public function test_admin_add_response_has_correct_structure(): void
    {
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Structured Admin',
            'email' => 'structured@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000214']);

        $response->assertJsonStructure([
            'status',
            'message',
            'success',
            'data' => [
                'id',
                'name',
                'email']]);
    }

    public function test_update_activation_response_has_correct_structure(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->putJson(self::PREFIX . '/admin-users/update-activation', [
            'user_id' => $target->id]);

        $response->assertJsonStructure(['status', 'message', 'success']);
    }

    public function test_delete_response_has_correct_structure(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->deleteJson(self::PREFIX . '/admin-users/delete/' . $target->id);

        $response->assertJsonStructure(['status', 'message', 'success']);
    }

    // ========================================================================
    // REGRESSION TESTS — Fillable (type, phone_number)
    // ========================================================================

    public function test_type_is_persisted_as_admin_through_endpoint(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Fillable Admin',
            'email' => 'fillable@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
            'is_active' => true,
        'phone_number' => '0100000215']);

        $this->assertDatabaseHas('users', [
            'email' => 'fillable@example.com',
            'type' => 'admin']);
    }

    public function test_default_user_type_is_user_when_not_specified(): void
    {
        $user = User::create([
            'name' => 'Default Type User',
            'email' => 'defaulttype@example.com',
            'password' => Hash::make('password')]);

        $this->assertDatabaseHas('users', [
            'email' => 'defaulttype@example.com',
            'type' => 'user']);
    }

    public function test_phone_number_is_persisted_through_endpoint(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Phone Persist',
            'email' => 'phonepersist@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
            'phone_number' => '01009998877']);

        $this->assertDatabaseHas('users', [
            'email' => 'phonepersist@example.com',
            'phone_number' => '01009998877']);
    }

    // ========================================================================
    // REGRESSION TESTS — Spatie Guard (Role::findById with 'api')
    // ========================================================================

    public function test_admin_add_finds_role_with_api_guard(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Api Guard Role',
            'email' => 'apiguard@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000216']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // ========================================================================
    // REGRESSION TESTS — Optional Roles (all combinations)
    // ========================================================================

    public function test_create_admin_user_with_single_role(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Single Role',
            'email' => 'singlerole@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000217']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('users', ['email' => 'singlerole@example.com']);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_id' => User::where('email', 'singlerole@example.com')->first()->id]);
    }

    public function test_create_admin_user_with_multiple_roles(): void
    {
        $admin = $this->createSuperAdminUser();
        $role1 = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);
        $role2 = Role::findOrCreate('customer', self::GUARD);

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Multi Role',
            'email' => 'multirole@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role1->id, $role2->id],
        'phone_number' => '0100000218']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('users', ['email' => 'multirole@example.com']);
        $user = User::where('email', 'multirole@example.com')->first();
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role1->id,
            'model_id' => $user->id]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role2->id,
            'model_id' => $user->id]);
    }

    public function test_create_admin_user_with_duplicate_role_ids(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Dup Role',
            'email' => 'duprole@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id, $role->id],
        'phone_number' => '0100000219']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('users', ['email' => 'duprole@example.com']);
        $user = User::where('email', 'duprole@example.com')->first();
        $this->assertCount(1, $user->roles()->get());
    }

    public function test_create_admin_user_with_mixed_valid_and_invalid_roles(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Mixed Role',
            'email' => 'mixedrole@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id, 99999],
        'phone_number' => '0100000220'])->assertStatus(422);
    }

    // ========================================================================
    // REGRESSION TESTS — Pagination
    // ========================================================================

    public function test_users_endpoint_returns_paginated_response(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['current_page', 'data', 'first_page_url', 'from', 'last_page',
            'last_page_url', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total']]);
    }

    public function test_users_endpoint_custom_per_page(): void
    {
        $admin = $this->createSuperAdminUser();
        User::create(['name' => 'Page A', 'email' => 'pagea@example.com', 'password' => Hash::make('p')]);
        User::create(['name' => 'Page B', 'email' => 'pageb@example.com', 'password' => Hash::make('p')]);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?limit=1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_users_endpoint_large_page_number_returns_empty(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?page=9999');

        $response->assertOk();
        $this->assertEmpty($response->json('data.data'));
    }

    // ========================================================================
    // REGRESSION TESTS — Phone Number Edge Cases
    // ========================================================================

    public function test_create_admin_user_with_nullable_phone(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Null Phone',
            'email' => 'nullphone@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000221'])->assertOk();
    }

    public function test_create_admin_user_fails_with_duplicate_phone(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Dup Phone',
            'email' => 'dupphone1@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
            'phone_number' => '01000000123'])->assertOk();

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Dup Phone 2',
            'email' => 'dupphone2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
            'phone_number' => '01000000123'])->assertStatus(422);
    }

    // ========================================================================
    // REGRESSION TESTS — User Type Edge Cases
    // ========================================================================

    public function test_create_regular_user_defaults_to_user_type(): void
    {
        $user = $this->createRegularUser();
        $this->assertEquals('user', $user->type);
    }

    public function test_admin_user_type_persists_in_database(): void
    {
        $admin = $this->createSuperAdminUser();
        $role = Role::findOrCreate(RoleEnum::SUPER_ADMIN, self::GUARD);

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/admin-users/add', [
            'name' => 'Type Check',
            'email' => 'typecheck@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [$role->id],
        'phone_number' => '0100000222']);

        $this->assertDatabaseHas('users', [
            'email' => 'typecheck@example.com',
            'type' => 'admin']);
    }

    // ========================================================================
    // REGRESSION TESTS — Existing Endpoint Authorization
    // ========================================================================

    public function test_non_admin_cannot_access_users_endpoint(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/users')
            ->assertStatus(403);
    }

    // ========================================================================
    // makeOrRevokeAdmin — POST /users/make-admin
    // ========================================================================

    public function test_super_admin_can_make_user_admin(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/make-admin', [
            'user_id' => $target->id]);

        $response->assertOk();

        $target->refresh();
        $this->assertEquals(UserType::ADMIN->value, $target->type);
    }

    public function test_super_admin_can_revoke_admin_from_user(): void
    {
        $admin = $this->createSuperAdminUser();

        $target = User::create([
            'name' => 'To Be Revoked',
            'email' => 'tobeevoked@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'admin',
            'is_active' => true,
            'phone_number' => '01000000996']);

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/make-admin', [
            'user_id' => $target->id]);

        $response->assertOk();

        $target->refresh();
        $this->assertEquals(UserType::USER->value, $target->type);
    }

    public function test_make_admin_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/make-admin', [
            'user_id' => 99999]);

        $response->assertStatus(404);
    }

    public function test_make_admin_fails_for_unauthorized_user(): void
    {
        $user = $this->createRegularUser();
        $target = User::create([
            'name' => 'Target User',
            'email' => 'targetuser@example.com',
            'password' => Hash::make('password'),
            'type' => 'user',
            'is_active' => true,
            'phone_number' => '01000000993']);

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/users/make-admin', [
            'user_id' => $target->id])->assertStatus(404);
    }

    public function test_make_admin_fails_for_unauthenticated_user(): void
    {
        $this->postJson(self::PREFIX . '/users/make-admin', [
            'user_id' => 1])->assertStatus(401);
    }

    // ========================================================================
    // banUser — POST /users/block-user
    // ========================================================================

    public function test_super_admin_can_ban_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/block-user', [
            'id' => $target->id]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => false]);
    }

    public function test_ban_user_already_banned_succeeds(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = User::create([
            'name' => 'Already Banned',
            'email' => 'alreadybanned@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
            'type' => 'user',
            'phone_number' => '01000000995']);

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/block-user', [
            'id' => $target->id]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => false]);
    }

    public function test_ban_user_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/users/block-user', [
            'id' => 99999])->assertStatus(404);
    }

    public function test_ban_user_fails_for_unauthorized_user(): void
    {
        $user = $this->createRegularUser();

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/users/block-user', [
            'id' => 1])->assertStatus(403);
    }

    public function test_ban_user_fails_for_unauthenticated_user(): void
    {
        $this->postJson(self::PREFIX . '/users/block-user', [
            'id' => 1])->assertStatus(401);
    }

    // ========================================================================
    // activeUser — POST /users/unblock-user
    // ========================================================================

    public function test_super_admin_can_activate_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = User::create([
            'name' => 'To Activate',
            'email' => 'toactivate@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
            'type' => 'user',
            'phone_number' => '01000000994']);

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/unblock-user', [
            'id' => $target->id]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => true]);
    }

    public function test_activate_user_already_active_succeeds(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/unblock-user', [
            'id' => $target->id]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'is_active' => true]);
    }

    public function test_activate_user_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/users/unblock-user', [
            'id' => 99999])->assertStatus(404);
    }

    public function test_activate_user_fails_for_unauthorized_user(): void
    {
        $user = $this->createRegularUser();

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/users/unblock-user', [
            'id' => 1])->assertStatus(403);
    }

    public function test_activate_user_fails_for_unauthenticated_user(): void
    {
        $this->postJson(self::PREFIX . '/users/unblock-user', [
            'id' => 1])->assertStatus(401);
    }

    // ========================================================================
    // Search — GET /users?search=
    // ========================================================================

    public function test_users_search_by_name(): void
    {
        $admin = $this->createSuperAdminUser();
        User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password'),
            'type' => 'user']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?search=John');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertContains('john@example.com', $emails);
    }

    public function test_users_search_by_email(): void
    {
        $admin = $this->createSuperAdminUser();
        User::create([
            'name' => 'Email Search',
            'email' => 'emailsearch@example.com',
            'password' => Hash::make('password'),
            'type' => 'user']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?search=emailsearch');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertContains('emailsearch@example.com', $emails);
    }

    public function test_users_search_partial_match(): void
    {
        $admin = $this->createSuperAdminUser();
        User::create([
            'name' => 'Partial Match User',
            'email' => 'partialmatch@example.com',
            'password' => Hash::make('password'),
            'type' => 'user']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?search=artial');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertContains('partialmatch@example.com', $emails);
    }

    public function test_users_search_no_results(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?search=zzzzz_nonexistent');

        $response->assertOk();
        $this->assertEmpty($response->json('data.data'));
    }

    public function test_users_search_with_pagination(): void
    {
        $admin = $this->createSuperAdminUser();
        User::create([
            'name' => 'Paginated Search',
            'email' => 'paginated@example.com',
            'password' => Hash::make('password'),
            'type' => 'user']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?search=Pagin&limit=1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_users_search_with_active_filter(): void
    {
        $admin = $this->createSuperAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?search=Super&active=true');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertContains('superadmin@example.com', $emails);
    }

    public function test_users_search_with_inactive_filter(): void
    {
        $admin = $this->createSuperAdminUser();
        User::create([
            'name' => 'Inactive Search',
            'email' => 'inactivesearch@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
            'type' => 'user']);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?search=Inactive&in_active=true');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertContains('inactivesearch@example.com', $emails);
    }

    // ========================================================================
    // Event Testing — UserRolesUpdated
    // ========================================================================

    public function test_user_roles_updated_event_dispatched_on_make_admin(): void
    {
        Event::fake();

        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/users/make-admin', [
            'user_id' => $target->id]);

        Event::assertDispatched(UserRolesUpdated::class);
    }

    public function test_user_roles_updated_event_dispatched_on_revoke_admin(): void
    {
        Event::fake();

        $admin = $this->createSuperAdminUser();

        $target = User::create([
            'name' => 'Event Revoke',
            'email' => 'eventrevoke@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'admin',
            'is_active' => true]);

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/users/make-admin', [
            'user_id' => $target->id]);

        Event::assertDispatched(UserRolesUpdated::class);
    }
}

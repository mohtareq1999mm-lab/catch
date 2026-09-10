<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserCrudTest extends TestCase
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
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
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

        Schema::create('address', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->json('address')->nullable();
            $table->json('location')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('users');
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->decimal('total_points', 16, 4)->default(0);
            $table->decimal('available_points', 16, 4)->default(0);
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('users');
        });
    }

    private function createSuperAdminUser(): User
    {
        $permissions = [
            PermissionEnum::VIEW_USERS,
            PermissionEnum::CREATE_USER,
            PermissionEnum::DELETE_USER,
            PermissionEnum::EDIT_USER,
            PermissionEnum::RESTORE_USER,
            PermissionEnum::ADD_POINTS,
            PermissionEnum::BAN_USER,
            PermissionEnum::ACTIVATE_USER];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'display_name' => json_encode(['en' => 'Super Admin']),
            'guard_name' => self::GUARD]);

        foreach ($permissions as $perm) {
            $role->givePermissionTo($perm);
        }

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

    // ========================================================================
    // GET /api/users/{id} — show
    // ========================================================================

    public function test_super_admin_can_view_user_by_id(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users/' . $target->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $target->id);
        $response->assertJsonPath('data.name', $target->name);
        $response->assertJsonPath('data.email', $target->email);
    }

    public function test_show_user_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users/99999');

        $response->assertStatus(404);
    }

    public function test_show_user_fails_for_unauthorized_user(): void
    {
        $user = $this->createRegularUser();
        $target = User::create([
            'name' => 'Target', 'email' => 'showtarget@example.com',
            'password' => Hash::make('p'), 'type' => 'user', 'is_active' => true, 'phone_number' => '01000000991']);

        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/users/' . $target->id)->assertStatus(403);
    }

    public function test_show_user_fails_for_unauthenticated_user(): void
    {
        $this->getJson(self::PREFIX . '/users/1')->assertStatus(401);
    }

    // ========================================================================
    // POST /api/users — store
    // ========================================================================

    public function test_super_admin_can_create_user_via_store(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users', [
            'first_name' => 'Store',
            'last_name' => 'Created',
            'name' => 'Store Created',
            'email' => 'storecreated@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone_number' => '01000001000',
            'policy' => true]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('users', ['email' => 'storecreated@gmail.com']);
    }

    public function test_store_user_without_name_returns_422(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/users', [
            'email' => 'noname@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'])->assertStatus(422);
    }

    // ========================================================================
    // PUT /api/users/{id} — update
    // ========================================================================

    public function test_super_admin_can_update_any_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->putJson(self::PREFIX . '/users/' . $target->id, [
            'name' => 'Updated By Admin']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Updated By Admin']);
    }

    public function test_user_cannot_update_self_without_permission(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);

        $this->putJson(self::PREFIX . '/users/' . $user->id, [
            'name' => 'Self Updated'])->assertStatus(403);
    }

    public function test_user_cannot_update_other_user(): void
    {
        $user = $this->createRegularUser();
        $other = User::create([
            'name' => 'Other', 'email' => 'otherupdate@example.com',
            'password' => Hash::make('p'), 'type' => 'user', 'is_active' => true, 'phone_number' => '01000000990']);

        Sanctum::actingAs($user);

        $this->putJson(self::PREFIX . '/users/' . $other->id, ['name' => 'Hacked'])->assertStatus(403);
    }

    public function test_update_user_fails_for_unauthenticated_user(): void
    {
        $this->putJson(self::PREFIX . '/users/1', ['name' => 'No Auth'])->assertStatus(401);
    }

    // ========================================================================
    // DELETE /api/users/{id} — destroy
    // ========================================================================

    public function test_super_admin_can_delete_user_via_destroy(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->deleteJson(self::PREFIX . '/users/' . $target->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_destroy_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $this->deleteJson(self::PREFIX . '/users/99999')->assertStatus(404);
    }

    public function test_destroy_fails_for_unauthenticated_user(): void
    {
        $this->deleteJson(self::PREFIX . '/users/1')->assertStatus(401);
    }

    // ========================================================================
    // DELETE /admin-users/delete-forever/{id} — force delete
    // ========================================================================

    public function test_super_admin_can_force_delete_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $target->delete();
        $response = $this->deleteJson(self::PREFIX . '/admin-users/delete-forever/' . $target->id);

        $response->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_force_delete_fails_for_super_admin(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $this->deleteJson(self::PREFIX . '/admin-users/delete-forever/' . $admin->id)
            ->assertStatus(400);
    }

    public function test_force_delete_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $this->deleteJson(self::PREFIX . '/admin-users/delete-forever/99999')->assertStatus(404);
    }

    // ========================================================================
    // PUT /admin-users/restore/{id}
    // ========================================================================

    public function test_super_admin_can_restore_soft_deleted_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();
        $target->delete();

        Sanctum::actingAs($admin);

        $response = $this->putJson(self::PREFIX . '/admin-users/restore/' . $target->id);

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_restore_fails_for_non_trashed_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $this->putJson(self::PREFIX . '/admin-users/restore/' . $target->id)
            ->assertStatus(400);
    }

    public function test_restore_fails_for_nonexistent_user(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $this->putJson(self::PREFIX . '/admin-users/restore/99999')->assertStatus(404);
    }

    // ========================================================================
    // GET /api/admin-users/trashed (not directly routed — uses /users?trash=true)
    // ========================================================================

    public function test_super_admin_can_view_trashed_users(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();
        $target->delete();

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users?trash=true');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertContains('regular@example.com', $emails);
    }

    // ========================================================================
    // POST /api/add-points
    // ========================================================================

    public function test_super_admin_can_add_points_to_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/add-points', [
            'customer_id' => $target->id,
            'points' => 100]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('wallets', [
            'customer_id' => $target->id,
            'total_points' => 100,
            'available_points' => 100]);
    }

    public function test_add_points_accumulates(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/add-points', ['customer_id' => $target->id, 'points' => 100]);
        $this->postJson(self::PREFIX . '/add-points', ['customer_id' => $target->id, 'points' => 50]);

        $this->assertDatabaseHas('wallets', [
            'customer_id' => $target->id,
            'total_points' => 150,
            'available_points' => 150]);
    }

    public function test_add_points_fails_without_customer_id(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/add-points', ['points' => 100])->assertStatus(422);
    }

    public function test_add_points_fails_without_points(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/add-points', ['customer_id' => 1])->assertStatus(422);
    }

    public function test_add_points_with_negative_value(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/add-points', [
            'customer_id' => $target->id,
            'points' => -50]);

        $response->assertOk();
        $this->assertDatabaseHas('wallets', [
            'customer_id' => $target->id,
            'total_points' => -50,
            'available_points' => -50]);
    }

    public function test_add_points_fails_for_unauthorized_user(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/add-points', ['customer_id' => 1, 'points' => 100])
            ->assertStatus(403);
    }

    // ========================================================================
    // POST /api/users/block-user — ban self guard
    // ========================================================================

    public function test_ban_user_cannot_ban_self(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/block-user', [
            'id' => $admin->id]);

        $response->assertStatus(403);
    }

    // ========================================================================
    // POST /api/subscribe-to-newsletter
    // ========================================================================

    public function test_subscribe_to_newsletter_works(): void
    {
        if (empty(config('newsletter.apiKey'))) {
            $this->markTestSkipped('MailChimp API key not configured');
        }

        $response = $this->postJson(self::PREFIX . '/subscribe-to-newsletter', [
            'email' => 'subscriber@example.com']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // ========================================================================
    // POST /api/users/make-admin — edge cases
    // ========================================================================

    public function test_make_admin_toggles_twice_returns_to_user(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/users/make-admin', ['user_id' => $target->id]);
        $target->refresh();
        $this->assertEquals('admin', $target->type);

        $this->postJson(self::PREFIX . '/users/make-admin', ['user_id' => $target->id]);
        $target->refresh();
        $this->assertEquals('user', $target->type);
    }

    public function test_make_admin_dispatches_event_on_each_toggle(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Event::fake();

        Sanctum::actingAs($admin);

        $this->postJson(self::PREFIX . '/users/make-admin', ['user_id' => $target->id]);
        $this->postJson(self::PREFIX . '/users/make-admin', ['user_id' => $target->id]);

        Event::assertDispatchedTimes(UserRolesUpdated::class, 2);
    }

    // ========================================================================
    // POST /api/admin-users/delete-forever/{id} — unauthenticated
    // ========================================================================

    public function test_force_delete_fails_for_unauthenticated_user(): void
    {
        $this->deleteJson(self::PREFIX . '/admin-users/delete-forever/1')->assertStatus(401);
    }

    public function test_restore_fails_for_unauthenticated_user(): void
    {
        $this->putJson(self::PREFIX . '/admin-users/restore/1')->assertStatus(401);
    }

    // ========================================================================
    // REGRESSION TESTS for Verified Bugs
    // ========================================================================

    /** @see BUG-1: Missing UserType import in UserController */
    public function test_make_admin_does_not_crash(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users/make-admin', [
            'user_id' => $target->id]);

        $response->assertOk();
        $target->refresh();
        $this->assertEquals('admin', $target->type);
    }

    /** @see BUG-3: UserUpdateRequest email unique:users missing ignore() */
    public function test_update_user_with_same_email_works(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->putJson(self::PREFIX . '/users/' . $target->id, [
            'name' => 'Admin Update Same Email',
            'email' => 'regular@example.com']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    /** @see BUG-4: storeUser() missing phone_number */
    public function test_store_user_persists_phone_number(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/users', [
            'first_name' => 'Phone',
            'last_name' => 'Test',
            'name' => 'Phone Test',
            'email' => 'phonetest@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone_number' => '01099998888',
            'policy' => true]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'email' => 'phonetest@gmail.com',
            'phone_number' => '01099998888']);
    }

    /** @see BUG-6: destroy() missing self/super_admin guard */
    public function test_destroy_fails_for_self_delete(): void
    {
        $admin = $this->createSuperAdminUser();
        Sanctum::actingAs($admin);

        $response = $this->deleteJson(self::PREFIX . '/users/' . $admin->id);

        $response->assertStatus(400);
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    /** @see BUG-6: destroy() missing self/super_admin guard */
    public function test_destroy_fails_for_super_admin_delete(): void
    {
        $admin = $this->createSuperAdminUser();
        $anotherAdmin = User::create([
            'name' => 'Another Admin',
            'email' => 'anotheradmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'admin',
            'is_active' => true,
            'phone_number' => '01000000002']);
        $role = Role::where('name', RoleEnum::SUPER_ADMIN)->first();
        $anotherAdmin->assignRole($role);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson(self::PREFIX . '/users/' . $anotherAdmin->id);

        $response->assertStatus(400);
        $this->assertNotSoftDeleted('users', ['id' => $anotherAdmin->id]);
    }

    /** @see BUG-7: UserResource missing fields */
    public function test_show_user_returns_all_resource_fields(): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createRegularUser();

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::PREFIX . '/users/' . $target->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'name', 'email', 'email_verified_at', 'is_active',
                'type', 'phone_number', 'created_at', 'updated_at']]);
        $response->assertJsonPath('data.type', 'user');
        $response->assertJsonPath('data.phone_number', '01000000999');
    }
}

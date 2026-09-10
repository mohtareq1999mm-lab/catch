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
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivityLogApiTest extends TestCase
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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->string('type')->default('user');
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
            $table->string('display_name');
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

    private function createSuperAdmin(): User
    {
        Permission::findOrCreate(self::GUARD);
        Permission::findOrCreate(PermissionEnum::VIEW_ACTIVITY_LOG, self::GUARD);

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'display_name' => json_encode(['en' => 'Super Admin', 'ar' => 'مدير النظام']),
            'guard_name' => self::GUARD]);
        $role->givePermissionTo([PermissionEnum::VIEW_ACTIVITY_LOG]);

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'phone_number' => '01000000001']);

        $user->assignRole($role);
        return $user;
    }

    public function test_unauthenticated_user_cannot_access_activity_logs(): void
    {
        $response = $this->getJson(self::PREFIX . '/logs/activity');

        $response->assertStatus(401);
    }

    public function test_super_admin_can_fetch_activity_logs(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        DB::table('activity_log')->delete();
        Activity::create([
            'log_name' => 'products',
            'description' => 'Test log entry',
            'event' => 'created',
            'subject_id' => 1,
            'subject_type' => User::class,
            'causer_id' => $user->id,
            'causer_type' => User::class]);

        $response = $this->getJson(self::PREFIX . '/logs/activity');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => ['id', 'log_name', 'description', 'event', 'subject_id', 'subject_type', 'causer_id', 'causer_type', 'properties', 'created_at', 'updated_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonFragment(['description' => 'Test log entry']);
    }

    public function test_can_filter_logs_by_log_name(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        Activity::create(['log_name' => 'products', 'description' => 'Product created', 'event' => 'created', 'subject_type' => User::class, 'causer_id' => $user->id, 'causer_type' => User::class]);
        Activity::create(['log_name' => 'users', 'description' => 'User updated', 'event' => 'updated', 'subject_type' => User::class, 'causer_id' => $user->id, 'causer_type' => User::class]);

        $response = $this->getJson(self::PREFIX . '/logs/activity?log_name=products');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.log_name', 'products');
    }

    public function test_can_search_logs(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        Activity::create(['log_name' => 'products', 'description' => 'Product created', 'event' => 'created', 'subject_type' => User::class, 'causer_id' => $user->id, 'causer_type' => User::class]);
        Activity::create(['log_name' => 'users', 'description' => 'User updated', 'event' => 'updated', 'subject_type' => User::class, 'causer_id' => $user->id, 'causer_type' => User::class]);

        $response = $this->getJson(self::PREFIX . '/logs/activity?search=Product');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.description', 'Product created');
    }

    public function test_returns_empty_when_no_logs(): void
    {
        $user = User::create([
            'name' => 'Plain User',
            'email' => 'plain@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'phone_number' => '01000000002']);
        Permission::findOrCreate(self::GUARD);
        Permission::findOrCreate(PermissionEnum::VIEW_ACTIVITY_LOG, self::GUARD);
        $user->givePermissionTo([PermissionEnum::VIEW_ACTIVITY_LOG]);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/logs/activity');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonPath('data', []);
    }

    public function test_non_admin_cannot_access_activity_logs(): void
    {
        Permission::findOrCreate(self::GUARD);

        $user = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'phone_number' => '01000000003']);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/logs/activity');

        $response->assertStatus(403);
    }
}

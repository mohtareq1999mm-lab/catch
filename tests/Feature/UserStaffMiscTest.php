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
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class UserStaffMiscTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $admin;
    private User $shopOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAllTestTables();

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

        if (!Schema::hasTable('shops')) {
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
        }

        if (!Schema::hasTable('user_profiles')) {
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
        }

        if (!Schema::hasTable('personal_access_tokens')) {
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
        }

        $permissions = [
            PermissionEnum::VIEW_USERS,
            PermissionEnum::CREATE_USER,
            PermissionEnum::DELETE_USER,
            PermissionEnum::EDIT_USER,
            PermissionEnum::STORE_OWNER,
            PermissionEnum::STAFF];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $superRole = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'display_name' => 'Super Admin',
            'guard_name' => self::GUARD]);
        $superRole->givePermissionTo(PermissionEnum::VIEW_USERS);

        $ownerRole = Role::create([
            'name' => RoleEnum::STORE_OWNER,
            'display_name' => 'Store Owner',
            'guard_name' => self::GUARD]);
        $ownerRole->givePermissionTo(PermissionEnum::STORE_OWNER);

        $this->admin = User::create([
            'name' => 'Super Admin',
            'email' => 'staffadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'admin',
            'is_active' => true,
            'phone_number' => '01000000085']);
        $this->admin->assignRole($superRole);

        $this->shopOwner = User::create([
            'name' => 'Shop Owner',
            'email' => 'shopowner@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'type' => 'admin',
            'is_active' => true,
            'phone_number' => '01000000084']);
        $this->shopOwner->assignRole($ownerRole);
    }

    // ========================================================================
    // POST /api/license-key/verify
    // ========================================================================

    public function test_license_key_verification_fails_without_key(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(self::PREFIX . '/license-key/verify', [])
            ->assertStatus(422);
    }

    public function test_license_key_verification_requires_string(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(self::PREFIX . '/license-key/verify', [
            'license_key' => 12345])->assertStatus(422);
    }

    // ========================================================================
    // GET /api/staffs — staff listing
    // ========================================================================

    public function test_staffs_endpoint_requires_shop_id(): void
    {
        Sanctum::actingAs($this->shopOwner);

        $this->getJson(self::PREFIX . '/staffs')
            ->assertStatus(403);
    }

    public function test_staffs_endpoint_requires_auth(): void
    {
        $this->getJson(self::PREFIX . '/staffs')->assertStatus(401);
    }

    // ========================================================================
    // GET /api/my-staffs
    // ========================================================================

    public function test_my_staffs_requires_auth(): void
    {
        $this->getJson(self::PREFIX . '/my-staffs')->assertStatus(401);
    }

    // ========================================================================
    // GET /api/all-staffs
    // ========================================================================

    public function test_all_staffs_requires_auth(): void
    {
        $this->getJson(self::PREFIX . '/all-staffs')->assertStatus(401);
    }

    // ========================================================================
    // POST /api/email/verification-notification
    // ========================================================================

    public function test_verification_notification_requires_auth(): void
    {
        $this->postJson(self::PREFIX . '/email/verification-notification')
            ->assertStatus(401);
    }

    // ========================================================================
    // GET /api/users?type= filter
    // ========================================================================

    public function test_index_filters_by_type(): void
    {
        Sanctum::actingAs($this->admin);

        User::create([
            'name' => 'Type User', 'email' => 'typeuser@example.com',
            'password' => Hash::make('p'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000083']);

        $response = $this->getJson(self::PREFIX . '/users?type=admin');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertNotContains('typeuser@example.com', $emails);
    }

    public function test_index_filters_users_flag(): void
    {
        Sanctum::actingAs($this->admin);

        User::create([
            'name' => 'Flagged User', 'email' => 'flagged@example.com',
            'password' => Hash::make('p'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000082']);

        $response = $this->getJson(self::PREFIX . '/users?users=true');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertContains('flagged@example.com', $emails);
        $this->assertNotContains('staffadmin@example.com', $emails);
    }

    public function test_index_filters_admins_flag(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson(self::PREFIX . '/users?admins=true');

        $response->assertOk();
        $emails = collect($response->json('data.data'))->pluck('email')->toArray();
        $this->assertContains('staffadmin@example.com', $emails);
    }

    // ========================================================================
    // Sorting — GET /users?order_by=&sort=
    // ========================================================================

    public function test_index_sorts_by_name_asc(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson(self::PREFIX . '/users?order_by=name&sort=asc');

        $response->assertOk();
    }

    public function test_index_sorts_by_name_desc(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson(self::PREFIX . '/users?order_by=name&sort=desc');

        $response->assertOk();
    }

    // ========================================================================
    // Fetch users by permission — GET /users (uses fetchUsersByPermission internally)
    // ========================================================================

    public function test_fetch_users_by_store_owner_permission(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson(self::PREFIX . '/users?permission=store_owner');

        $response->assertOk();
    }
}

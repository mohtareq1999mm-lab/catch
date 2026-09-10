<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\PickupLocation;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

class PickupLocationTest extends TestCase
{
    use DatabaseTransactions;

    private const ADMIN_PREFIX = '/api/v1';
    private const PUBLIC_PREFIX = '/api/v1/general';

    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        if (!Schema::hasTable('pickup_locations')) {
            $this->createPickupLocationsTable();
        }

        if (!Schema::hasTable('activity_log')) {
            $this->createActivityLogTable();
        }

        $this->seedPermissions();
        $this->seedUsers();
    }

    private function createActivityLogTable(): void
    {
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

    private function createPickupLocationsTable(): void
    {
        Schema::create('pickup_locations', function (Blueprint $table) {
            $table->id();
            $table->string('store_name');
            $table->text('address');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->json('working_hours')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('display_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
            });
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type']);
            });
        }

        if (!Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->index(['role_id', 'permission_id']);
            });
        }

        $perms = [
            'view-pickup-locations',
            'create-pickup-location',
            'update-pickup-location',
            'delete-pickup-location',
        ];

        foreach ($perms as $perm) {
            SpatiePermission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }
    }

    private function seedUsers(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('type')->default('user');
                $table->boolean('is_active')->default(true);
                $table->string('phone_number')->nullable();
                $table->rememberToken();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $this->admin->givePermissionTo([
            'view-pickup-locations',
            'create-pickup-location',
            'update-pickup-location',
            'delete-pickup-location',
        ]);

        $this->customer = User::create([
            'name' => 'Customer User',
            'email' => 'customer@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    // ========== Admin CRUD Tests ==========

    /** @test */
    public function admin_can_list_pickup_locations()
    {
        Sanctum::actingAs($this->admin);
        PickupLocation::create([
            'store_name' => 'Downtown Store',
            'address' => '123 Main St',
            'phone' => '01000000001',
            'status' => true,
        ]);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['data' => [['id', 'store_name', 'address', 'phone', 'status']]],
        ]);
    }

    /** @test */
    public function admin_can_create_pickup_location()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'New Store',
            'address' => '456 Oak Ave',
            'phone' => '01000000002',
            'email' => 'store@test.com',
            'latitude' => '30.0444',
            'longitude' => '31.2357',
            'working_hours' => [
                ['day' => 'Monday', 'open' => '09:00', 'close' => '21:00'],
            ],
            'status' => true,
            'display_order' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => ['store_name' => 'New Store'],
        ]);
        $this->assertDatabaseHas('pickup_locations', [
            'store_name' => 'New Store',
            'phone' => '01000000002',
        ]);
    }

    /** @test */
    public function admin_can_show_pickup_location()
    {
        Sanctum::actingAs($this->admin);
        $location = PickupLocation::create([
            'store_name' => 'Showcase Store',
            'address' => '789 Pine Rd',
            'phone' => '01000000003',
            'status' => true,
        ]);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations/' . $location->id);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => ['store_name' => 'Showcase Store'],
        ]);
    }

    /** @test */
    public function admin_can_update_pickup_location()
    {
        Sanctum::actingAs($this->admin);
        $location = PickupLocation::create([
            'store_name' => 'Old Name',
            'address' => '123 Elm St',
            'phone' => '01000000004',
            'status' => true,
        ]);

        $response = $this->putJson(self::ADMIN_PREFIX . '/pickup-locations/' . $location->id, [
            'store_name' => 'Updated Name',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pickup_locations', [
            'id' => $location->id,
            'store_name' => 'Updated Name',
        ]);
    }

    /** @test */
    public function admin_can_delete_pickup_location()
    {
        Sanctum::actingAs($this->admin);
        $location = PickupLocation::create([
            'store_name' => 'Delete Me',
            'address' => '321 Maple Dr',
            'phone' => '01000000005',
            'status' => true,
        ]);

        $response = $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/' . $location->id);

        $response->assertStatus(200);
        $this->assertSoftDeleted('pickup_locations', ['id' => $location->id]);
    }

    // ========== Validation Tests ==========

    /** @test */
    public function store_requires_store_name()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'address' => 'No Name St',
            'phone' => '01000000006',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['store_name']);
    }

    /** @test */
    public function store_requires_address()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'No Address',
            'phone' => '01000000007',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['address']);
    }

    /** @test */
    public function store_requires_phone()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'No Phone',
            'address' => '123 Silent St',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['phone']);
    }

    /** @test */
    public function store_accepts_valid_email()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Email Test',
            'address' => '123 Email St',
            'phone' => '01000000008',
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['email']);
    }

    /** @test */
    public function store_validates_display_order_is_integer()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Order Test',
            'address' => '123 Order St',
            'phone' => '01000000009',
            'display_order' => 'not-a-number',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['display_order']);
    }

    // ========== Authorization Tests ==========

    /** @test */
    public function unauthenticated_user_cannot_access_admin_endpoints()
    {
        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations');
        $response->assertStatus(401);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', []);
        $response->assertStatus(401);

        $response = $this->putJson(self::ADMIN_PREFIX . '/pickup-locations/1', []);
        $response->assertStatus(401);

        $response = $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/1');
        $response->assertStatus(401);
    }

    /** @test */
    public function customer_cannot_create_pickup_location()
    {
        Sanctum::actingAs($this->customer);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Hack Attempt',
            'address' => '123 Hack St',
            'phone' => '01000000010',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function customer_cannot_delete_pickup_location()
    {
        Sanctum::actingAs($this->customer);

        $response = $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/1');
        $response->assertStatus(403);
    }

    // ========== Public API Tests ==========

    /** @test */
    public function public_can_list_active_pickup_locations()
    {
        PickupLocation::create([
            'store_name' => 'Active Store',
            'address' => '123 Active St',
            'phone' => '01000000011',
            'status' => true,
        ]);
        PickupLocation::create([
            'store_name' => 'Inactive Store',
            'address' => '456 Inactive St',
            'phone' => '01000000012',
            'status' => false,
        ]);

        $response = $this->getJson(self::PUBLIC_PREFIX . '/pickup-locations');

        $response->assertStatus(200);
        $response->assertJsonFragment(['store_name' => 'Active Store']);
        $response->assertJsonMissing(['store_name' => 'Inactive Store']);
    }

    /** @test */
    public function public_can_show_active_pickup_location()
    {
        $location = PickupLocation::create([
            'store_name' => 'Public Store',
            'address' => '789 Public Ave',
            'phone' => '01000000013',
            'status' => true,
        ]);

        $response = $this->getJson(self::PUBLIC_PREFIX . '/pickup-locations/' . $location->id);

        $response->assertStatus(200);
        $response->assertJsonFragment(['store_name' => 'Public Store']);
    }

    /** @test */
    public function public_cannot_show_inactive_pickup_location()
    {
        $location = PickupLocation::create([
            'store_name' => 'Hidden Store',
            'address' => '321 Hidden Ln',
            'phone' => '01000000014',
            'status' => false,
        ]);

        $response = $this->getJson(self::PUBLIC_PREFIX . '/pickup-locations/' . $location->id);

        $response->assertStatus(404);
    }

    // ========== Edge Case Tests ==========

    /** @test */
    public function returns_404_for_nonexistent_pickup_location()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations/99999');
        $response->assertStatus(404);

        $response = $this->putJson(self::ADMIN_PREFIX . '/pickup-locations/99999', [
            'store_name' => 'Ghost',
        ]);
        $response->assertStatus(404);

        $response = $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/99999');
        $response->assertStatus(404);
    }

    /** @test */
    public function pickup_locations_are_ordered_by_display_order()
    {
        Sanctum::actingAs($this->admin);

        PickupLocation::create([
            'store_name' => 'Second',
            'address' => 'Addr 1',
            'phone' => '01000000015',
            'display_order' => 2,
        ]);
        PickupLocation::create([
            'store_name' => 'First',
            'address' => 'Addr 2',
            'phone' => '01000000016',
            'display_order' => 1,
        ]);
        PickupLocation::create([
            'store_name' => 'Third',
            'address' => 'Addr 3',
            'phone' => '01000000017',
            'display_order' => 3,
        ]);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations');

        $response->assertStatus(200);
        $names = collect($response->json('data.data'))->pluck('store_name')->toArray();
        $this->assertEquals(['First', 'Second', 'Third'], $names);
    }

    /** @test */
    public function admin_can_search_pickup_locations()
    {
        Sanctum::actingAs($this->admin);

        PickupLocation::create([
            'store_name' => 'Downtown Branch',
            'address' => 'Addr 1',
            'phone' => '01000000018',
        ]);
        PickupLocation::create([
            'store_name' => 'Uptown Branch',
            'address' => 'Addr 2',
            'phone' => '01000000019',
        ]);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations?search=Downtown');

        $response->assertStatus(200);
        $response->assertJsonFragment(['store_name' => 'Downtown Branch']);
        $response->assertJsonMissing(['store_name' => 'Uptown Branch']);
    }

    // ========== Default Pickup Location Tests ==========

    /** @test */
    public function creating_pickup_location_as_default_resets_previous_default()
    {
        Sanctum::actingAs($this->admin);

        PickupLocation::create([
            'store_name' => 'First Branch',
            'address' => 'Addr 1',
            'phone' => '01000000020',
            'status' => true,
            'is_default' => true,
        ]);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'New Default',
            'address' => '456 New St',
            'phone' => '01000000021',
            'status' => true,
            'is_default' => true,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('pickup_locations', [
            'store_name' => 'New Default',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('pickup_locations', [
            'store_name' => 'First Branch',
            'is_default' => false,
        ]);
    }

    /** @test */
    public function setting_new_default_via_update_resets_others()
    {
        Sanctum::actingAs($this->admin);

        $first = PickupLocation::create([
            'store_name' => 'First Branch',
            'address' => 'Addr 1',
            'phone' => '01000000022',
            'status' => true,
            'is_default' => true,
        ]);
        $second = PickupLocation::create([
            'store_name' => 'Second Branch',
            'address' => 'Addr 2',
            'phone' => '01000000023',
            'status' => true,
        ]);

        $response = $this->putJson(self::ADMIN_PREFIX . '/pickup-locations/' . $second->id, [
            'is_default' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pickup_locations', [
            'id' => $second->id,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('pickup_locations', [
            'id' => $first->id,
            'is_default' => false,
        ]);
    }

    /** @test */
    public function updating_default_location_fields_preserves_is_default()
    {
        Sanctum::actingAs($this->admin);

        $location = PickupLocation::create([
            'store_name' => 'Default Branch',
            'address' => 'Addr 1',
            'phone' => '01000000024',
            'status' => true,
            'is_default' => true,
        ]);

        $response = $this->putJson(self::ADMIN_PREFIX . '/pickup-locations/' . $location->id, [
            'store_name' => 'Renamed Branch',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pickup_locations', [
            'id' => $location->id,
            'store_name' => 'Renamed Branch',
            'is_default' => true,
        ]);
    }

    /** @test */
    public function only_one_default_pickup_location_exists_at_a_time()
    {
        Sanctum::actingAs($this->admin);

        PickupLocation::create([
            'store_name' => 'A',
            'address' => 'Addr A',
            'phone' => '01000000025',
            'status' => true,
            'is_default' => true,
        ]);
        PickupLocation::create([
            'store_name' => 'B',
            'address' => 'Addr B',
            'phone' => '01000000026',
            'status' => true,
            'is_default' => true,
        ]);
        PickupLocation::create([
            'store_name' => 'C',
            'address' => 'Addr C',
            'phone' => '01000000027',
            'status' => true,
            'is_default' => true,
        ]);

        $this->assertEquals(1, PickupLocation::where('is_default', true)->count());
    }

    /** @test */
    public function deleting_default_location_promotes_next_by_id()
    {
        Sanctum::actingAs($this->admin);

        $default = PickupLocation::create([
            'store_name' => 'Default Branch',
            'address' => 'Addr 1',
            'phone' => '01000000028',
            'status' => true,
            'is_default' => true,
        ]);
        $next = PickupLocation::create([
            'store_name' => 'Next Branch',
            'address' => 'Addr 2',
            'phone' => '01000000029',
            'status' => true,
        ]);

        $response = $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/' . $default->id);

        $response->assertStatus(200);
        $this->assertSoftDeleted('pickup_locations', ['id' => $default->id]);
        $this->assertDatabaseHas('pickup_locations', [
            'id' => $next->id,
            'is_default' => true,
        ]);
    }

    /** @test */
    public function deleting_non_default_location_keeps_default()
    {
        Sanctum::actingAs($this->admin);

        $default = PickupLocation::create([
            'store_name' => 'Default Branch',
            'address' => 'Addr 1',
            'phone' => '01000000030',
            'status' => true,
            'is_default' => true,
        ]);
        $other = PickupLocation::create([
            'store_name' => 'Other Branch',
            'address' => 'Addr 2',
            'phone' => '01000000031',
            'status' => true,
        ]);

        $response = $this->deleteJson(self::ADMIN_PREFIX . '/pickup-locations/' . $other->id);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pickup_locations', [
            'id' => $default->id,
            'is_default' => true,
        ]);
    }

    /** @test */
    public function admin_resource_exposes_is_default()
    {
        Sanctum::actingAs($this->admin);

        PickupLocation::create([
            'store_name' => 'Default Branch',
            'address' => 'Addr 1',
            'phone' => '01000000032',
            'status' => true,
            'is_default' => true,
        ]);

        $response = $this->getJson(self::ADMIN_PREFIX . '/pickup-locations');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['data' => [['is_default']]],
        ]);
        $response->assertJsonFragment(['is_default' => true]);
    }

    /** @test */
    public function public_resource_exposes_is_default()
    {
        PickupLocation::create([
            'store_name' => 'Default Branch',
            'address' => 'Addr 1',
            'phone' => '01000000033',
            'status' => true,
            'is_default' => true,
        ]);

        $response = $this->getJson(self::PUBLIC_PREFIX . '/pickup-locations');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['is_default']],
        ]);
        $response->assertJsonFragment(['is_default' => true]);
    }

    /** @test */
    public function service_returns_default_pickup_location()
    {
        $default = PickupLocation::create([
            'store_name' => 'Default Branch',
            'address' => 'Addr 1',
            'phone' => '01000000034',
            'status' => true,
            'is_default' => true,
        ]);
        PickupLocation::create([
            'store_name' => 'Other Branch',
            'address' => 'Addr 2',
            'phone' => '01000000035',
            'status' => true,
        ]);

        $service = app(\App\Services\General\PickupLocationService::class);
        $result = $service->getDefaultPickupLocation();

        $this->assertNotNull($result);
        $this->assertEquals($default->id, $result->id);
    }

    /** @test */
    public function service_returns_null_when_no_active_default_exists()
    {
        PickupLocation::create([
            'store_name' => 'Inactive Default',
            'address' => 'Addr 1',
            'phone' => '01000000036',
            'status' => false,
            'is_default' => true,
        ]);

        $service = app(\App\Services\General\PickupLocationService::class);
        $result = $service->getDefaultPickupLocation();

        $this->assertNull($result);
    }

    /** @test */
    public function store_validates_is_default_is_boolean()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Boolean Test',
            'address' => '123 Bool St',
            'phone' => '01000000037',
            'is_default' => 'not-a-boolean',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['is_default']);
    }

    /** @test */
    public function creating_pickup_location_without_is_default_is_not_default()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::ADMIN_PREFIX . '/pickup-locations', [
            'store_name' => 'Plain Branch',
            'address' => '123 Plain St',
            'phone' => '01000000038',
            'status' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pickup_locations', [
            'store_name' => 'Plain Branch',
            'is_default' => false,
        ]);
    }
}

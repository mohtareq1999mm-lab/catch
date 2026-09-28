<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\FlowInput;
use App\Models\OrderFlow\OrderFlow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Bulk Flow Input creation: ONE request → ONE flow → MANY definitions →
 * ONE transaction. Covers the §33 matrix: multi-create, atomic rollback,
 * duplicate keys (in-request + existing), invalid type/source/required_at,
 * permission denial, and deterministic sort ordering.
 */
class BulkFlowInputsTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Queue::fake();

        $this->createAllTestTables();

        if (!Settings::exists()) {
            Settings::create([
                'language' => 'en',
                'options' => ['catalog_currency_code' => 'EGP', 'base_currency_code' => 'EGP', 'currency' => 'EGP'],
                'minimum_order_amount' => 0]);
        }

        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Gov',
            'status' => true]);
        ShippingPrice::create([
            'governorate_id' => $governorate->id,
            'price' => 0,
            'status' => true]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-bulk@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);
        if (Schema::hasTable('roles')) {
            $role = \Marvel\Database\Models\Role::firstOrCreate(
                ['name' => 'super_admin', 'guard_name' => 'api'],
                ['display_name' => ['en' => 'Super Admin', 'ar' => 'ادمن']]
            );
            $this->admin->assignRole($role);
        }
        if (Schema::hasTable('permissions')) {
            foreach (['manage-order-flow-inputs', 'update-order-status'] as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
                $this->admin->givePermissionTo($perm);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function flow(): OrderFlow
    {
        return OrderFlow::query()->where('shipping_type', 'international')->firstOrFail();
    }

    private function postInputs(OrderFlow $flow, array $payload)
    {
        return $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", $payload);
    }

    private function item(string $key, array $overrides = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => ['en' => ucfirst(str_replace('_', ' ', $key)), 'ar' => 'تسمية'],
            'type' => 'text',
            'required' => false,
            'required_at' => 'checkout',
        ], $overrides);
    }

    public function test_creates_three_inputs_in_one_request(): void
    {
        $flow = $this->flow();

        $resp = $this->postInputs($flow, ['inputs' => [
            $this->item('from_country', [
                'type' => 'select',
                'source' => 'countries',
                'required' => true,
            ]),
            $this->item('to_country', [
                'type' => 'select',
                'source' => 'countries',
                'required' => true,
            ]),
            $this->item('customs_reference', [
                'required' => true,
                'required_at' => 'transition:customs_clearance',
                'validation' => ['min' => 3, 'max' => 100],
            ]),
        ]]);

        $resp->assertStatus(201);
        $data = $resp->json('data');
        $this->assertCount(3, $data);
        $this->assertSame(
            ['from_country', 'to_country', 'customs_reference'],
            collect($data)->pluck('key')->all()
        );
        // Deterministic sequential ordering in request order.
        $this->assertSame([1, 2, 3], collect($data)->pluck('sort_order')->all());
        $this->assertSame(3, FlowInput::query()->where('flow_id', $flow->id)->count());
    }

    public function test_atomic_rollback_when_third_item_fails(): void
    {
        $flow = $this->flow();

        $resp = $this->postInputs($flow, ['inputs' => [
            $this->item('bulk_ok_one'),
            $this->item('bulk_ok_two'),
            // to_country... first item key duplicated inside the request.
            $this->item('bulk_ok_one'),
        ]]);

        $resp->assertStatus(422);
        $this->assertSame(0, FlowInput::query()->where('flow_id', $flow->id)->count());
    }

    public function test_duplicate_key_against_existing_inputs_fails(): void
    {
        $flow = $this->flow();
        FlowInput::create([
            'flow_id' => $flow->id,
            'key' => 'to_country',
            'label' => ['en' => 'Existing', 'ar' => 'موجود'],
            'type' => 'text',
            'required' => false,
            'required_at' => 'checkout',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $resp = $this->postInputs($flow, ['inputs' => [
            $this->item('fresh_one'),
            $this->item('to_country'),
        ]]);

        $resp->assertStatus(422);
        // Nothing persisted — not even the valid first item.
        $this->assertSame(1, FlowInput::query()->where('flow_id', $flow->id)->count());
    }

    public function test_invalid_type_source_and_required_at_fail(): void
    {
        $flow = $this->flow();

        $this->postInputs($flow, ['inputs' => [$this->item('bad_type', ['type' => 'country'])]])
            ->assertStatus(422);

        $this->postInputs($flow, ['inputs' => [$this->item('bad_source', [
            'type' => 'select', 'source' => 'planets',
        ])]])->assertStatus(422);

        $this->postInputs($flow, ['inputs' => [$this->item('bad_timing', [
            'required_at' => 'transition:hyperdrive',
        ])]])->assertStatus(422);

        $this->assertSame(0, FlowInput::query()->where('flow_id', $flow->id)->count());
    }

    public function test_explicit_sort_orders_respected_and_collisions_rejected(): void
    {
        $flow = $this->flow();

        $resp = $this->postInputs($flow, ['inputs' => [
            $this->item('with_sort', ['sort_order' => 10]),
            $this->item('auto_sort'),
        ]]);

        $resp->assertStatus(201);
        $byKey = collect($resp->json('data'))->keyBy('key');
        $this->assertSame(10, $byKey['with_sort']['sort_order']);
        $this->assertSame(11, $byKey['auto_sort']['sort_order']);

        // Collision with the now-taken sort_order 10.
        $this->postInputs($flow, ['inputs' => [$this->item('clash', ['sort_order' => 10])]])
            ->assertStatus(422);
    }

    public function test_permission_denied_without_input_permission(): void
    {
        $plain = User::create([
            'name' => 'Plain',
            'email' => 'plain-bulk@example.com',
            'password' => bcrypt('password'),
            'type' => 'staff',
            'is_active' => true,
            'email_verified_at' => now()]);
        Sanctum::actingAs($plain);

        $this->postInputs($this->flow(), ['inputs' => [$this->item('nope')]])
            ->assertStatus(403);
        $this->assertSame(0, FlowInput::query()->where('flow_id', $this->flow()->id)->count());
    }

    public function test_empty_and_missing_inputs_rejected(): void
    {
        $flow = $this->flow();

        $this->postInputs($flow, ['inputs' => []])->assertStatus(422);
        $this->postInputs($flow, [])->assertStatus(422);
    }
}

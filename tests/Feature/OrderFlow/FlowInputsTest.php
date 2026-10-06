<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\FlowInput;
use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderFlowValue;
use App\Models\OrderFlow\OrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Dynamic Flow Input system.
 *
 * Covers: definition endpoint (shape, auth, no values leaked), admin input
 * CRUD + guards (key immutability, duplicates, bad type/required_at,
 * in-flight block), checkout inputs (required/unknown/inactive-source),
 * transition inputs (customs_reference gate, 422 leaves status unchanged),
 * permissions (granular + legacy compat + 403), and audit persistence.
 */
class FlowInputsTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $user;

    private User $admin;

    private Product $product;

    private Governorate $governorate;

    private Country $countryA;

    private Country $countryB;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Queue::fake();

        $this->createAllTestTables();

        if (!Schema::hasTable('order_status_history')) {
            Schema::create('order_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->string('old_status')->nullable();
                $table->string('new_status');
                $table->string('old_payment_status')->nullable();
                $table->string('new_payment_status')->nullable();
                $table->string('old_fulfillment_status')->nullable();
                $table->string('new_fulfillment_status')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
                $table->string('changed_by_type')->default('user');
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('changed_at');
                $table->timestamps();
            });
        }

        config(['payment.order_timeout_hours' => 24]);
        config(['payment.cod_order_timeout_hours' => 24 * 7]);
        config(['payment.default_currency' => 'EGP']);
        config(['shop.default_currency' => 'EGP']);

        if (!Settings::exists()) {
            Settings::create([
                'language' => 'en',
                'options' => ['catalog_currency_code' => 'EGP', 'base_currency_code' => 'EGP', 'currency' => 'EGP'],
                'minimum_order_amount' => 0]);
        }

        $this->countryA = Country::create(['name' => ['en' => 'Origin Land', 'ar' => 'أرض المنشأ'], 'status' => true]);
        $this->countryB = Country::create(['name' => ['en' => 'Dest Land', 'ar' => 'أرض الوجهة'], 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $this->countryA->id,
            'name' => 'Test Gov',
            'status' => true]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 0,
            'status' => true]);

        $this->user = User::create([
            'name' => 'Buyer',
            'email' => 'buyer-inputs@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-inputs@example.com',
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
            foreach ([
                'update-order-status', 'payments.mark_paid', 'view-orders', 'view-order',
                'view-order-flows', 'create-order-flows', 'update-order-flows', 'manage-order-flow-inputs',
            ] as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
                $this->admin->givePermissionTo($perm);
            }
            // Production parity: holders of the general permission inherit
            // the granular change-order-status.* set (compat bridge).
            (new \Database\Seeders\OrderStatusPermissionSeeder)->run();
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->product = Product::create([
            'name' => 'Product A',
            'slug' => 'product-a-' . Str::random(6),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 20,
            'reserved_quantity' => 0,
            'sold_quantity' => 0]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function internationalFlow(): OrderFlow
    {
        return OrderFlow::query()->where('shipping_type', 'international')->firstOrFail();
    }

    private function seedInternationalInputs(): void
    {
        $flow = $this->internationalFlow();

        foreach (\Database\Seeders\FlowInputSeeder::seedDefinitions()['international'] as $i => $definition) {
            FlowInput::create(array_merge($definition, [
                'flow_id' => $flow->id,
                'sort_order' => $i + 1,
                'is_active' => true,
            ]));
        }
    }

    private function baseCheckoutPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery'], $overrides);
    }

    private function freshCartWithProduct(User $user): void
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'active'],
            ['total_price' => 0, 'coupon' => null]
        );
        $cart->items()->delete();
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => $this->product->price,
            'total_price' => $this->product->price,
            'shipping_method' => ShippingMethod::SCHEDULED,
            'is_gift' => false]);
        $cart->update(['total_price' => $this->product->price]);
    }

    private function checkoutInternational(array $flowValues): Order
    {
        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload([
            'shipping_type' => 'international',
            'flow_values' => $flowValues,
        ]));

        $response->assertStatus(200);

        return Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
    }

    private function adminPatchStatus(Order $order, string $status, array $flowValues = [])
    {
        Sanctum::actingAs($this->admin);

        return $this->patchJson("/api/v1/orders/{$order->id}/status", array_filter([
            'status' => $status,
            'flow_values' => $flowValues ?: null,
        ]));
    }

    private function walkTo(Order $order, array $statuses): void
    {
        foreach ($statuses as $status) {
            $this->adminPatchStatus($order, $status)->assertStatus(200);
            $order->refresh();
        }
    }

    // -----------------------------------------------------------------
    // Definition endpoint
    // -----------------------------------------------------------------

    public function test_definition_returns_flow_statuses_and_inputs(): void
    {
        $this->seedInternationalInputs();
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/general/order-flows/by-shipping-type/international');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.shipping_type', 'international');
        $response->assertJsonStructure([
            'data' => [
                'statuses' => [['code', 'sort_order']],
                'inputs' => [['key', 'label', 'type', 'source', 'required', 'required_at', 'sort_order']],
            ],
        ]);

        $keys = collect($response->json('data.inputs'))->pluck('key')->all();
        $this->assertContains('from_country', $keys);
        $this->assertContains('to_country', $keys);
        $this->assertContains('customs_reference', $keys);
        $this->assertEquals(['en' => 'Country of origin', 'ar' => 'بلد المنشأ'], $response->json('data.inputs.0.label'));
    }

    public function test_definition_is_guest_accessible(): void
    {
        // D8b: flow definitions are guest-safe discovery (no auth required).
        $this->getJson('/api/v1/general/order-flows/by-shipping-type/local')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shipping_type', 'local');
    }

    public function test_definition_rejects_unknown_shipping_type(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/general/order-flows/by-shipping-type/sea')->assertStatus(422);
    }

    public function test_definition_never_exposes_order_values(): void
    {
        $this->seedInternationalInputs();
        $order = $this->checkoutInternational([
            'from_country' => $this->countryA->id,
            'to_country' => $this->countryB->id,
        ]);

        Sanctum::actingAs($this->user);
        $body = $this->getJson('/api/v1/general/order-flows/by-shipping-type/international')->json();

        // Definitions only: no runtime order values, order collections, or
        // internal identifiers leak into the customer-facing schema.
        $this->assertArrayNotHasKey('flow_values', $body['data']);
        $this->assertArrayNotHasKey('orders', $body['data']);
        $this->assertStringNotContainsString('"flow_id"', json_encode($body));
    }

    // -----------------------------------------------------------------
    // Checkout inputs
    // -----------------------------------------------------------------

    public function test_international_checkout_requires_from_and_to_country(): void
    {
        $this->seedInternationalInputs();
        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload([
            'shipping_type' => 'international',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertArrayHasKey('from_country', $response->json('data.errors'));
        $this->assertArrayHasKey('to_country', $response->json('data.errors'));
        $this->assertEquals(0, Order::query()->where('user_id', $this->user->id)->count());
    }

    public function test_checkout_rejects_unknown_input_keys(): void
    {
        $this->seedInternationalInputs();
        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload([
            'shipping_type' => 'international',
            'flow_values' => [
                'from_country' => $this->countryA->id,
                'to_country' => $this->countryB->id,
                'fake_field' => 'abc',
            ],
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('fake_field', $response->json('data.errors'));
    }

    public function test_checkout_rejects_inactive_source_country(): void
    {
        $this->seedInternationalInputs();
        $dead = Country::create(['name' => 'Dead Land', 'status' => false]);
        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload([
            'shipping_type' => 'international',
            'flow_values' => ['from_country' => $dead->id, 'to_country' => $this->countryB->id],
        ]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('from_country', $response->json('data.errors'));
    }

    public function test_international_checkout_persists_countries_and_audit(): void
    {
        $this->seedInternationalInputs();

        $order = $this->checkoutInternational([
            'from_country' => $this->countryA->id,
            'to_country' => $this->countryB->id,
        ]);

        $this->assertEquals('international', $order->shipping_type);
        $this->assertEquals($this->countryA->id, (int) $order->origin_country_id);
        $this->assertEquals($this->countryB->id, (int) $order->destination_country_id);
        $this->assertEquals(2, OrderFlowValue::query()->where('order_id', $order->id)->where('context', 'checkout')->count());
    }

    public function test_local_checkout_without_values_still_passes(): void
    {
        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload())
            ->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Transition inputs
    // -----------------------------------------------------------------

    public function test_customs_clearance_requires_reference_and_keeps_status_on_failure(): void
    {
        $this->seedInternationalInputs();
        $order = $this->checkoutInternational([
            'from_country' => $this->countryA->id,
            'to_country' => $this->countryB->id,
        ]);

        $this->walkTo($order, ['processing', 'packed', 'export_processing', 'shipped', 'in_transit', 'arrived_at_destination_country']);

        $this->adminPatchStatus($order, 'customs_clearance')->assertStatus(422);
        $this->assertEquals('arrived_at_destination_country', $order->fresh()->status);

        $this->adminPatchStatus($order, 'customs_clearance', ['customs_reference' => 'CUS-2026-00125'])
            ->assertStatus(200);

        $order->refresh();
        $this->assertEquals('customs_clearance', $order->status);
        $this->assertEquals('CUS-2026-00125', $order->customs_reference);
        $this->assertTrue(OrderFlowValue::query()->where('order_id', $order->id)
            ->where('input_key', 'customs_reference')->where('context', 'transition:customs_clearance')->exists());
    }

    public function test_transition_rejects_unknown_keys(): void
    {
        $this->seedInternationalInputs();
        $order = $this->checkoutInternational([
            'from_country' => $this->countryA->id,
            'to_country' => $this->countryB->id,
        ]);

        $this->adminPatchStatus($order, 'processing', ['nope' => 1])->assertStatus(422);
        $this->assertEquals('pending', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Admin input management + permissions
    // -----------------------------------------------------------------

    public function test_admin_input_crud_and_guards(): void
    {
        $flow = $this->internationalFlow();
        Sanctum::actingAs($this->admin);

        // Bulk contract: {inputs: [...]} — even for a single definition.
        $create = $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", [
            'inputs' => [[
                'key' => 'delivery_note',
                'label' => ['en' => 'Delivery note', 'ar' => 'ملاحظة التسليم'],
                'type' => 'text',
                'required' => false,
                'required_at' => 'checkout',
            ]],
        ]);
        $create->assertStatus(201);
        $inputId = $create->json('data.0.id');

        // Duplicate key rejected.
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", [
            'inputs' => [[
                'key' => 'delivery_note',
                'label' => ['en' => 'Dup', 'ar' => 'مكرر'],
                'type' => 'text',
            ]],
        ])->assertStatus(422);

        // Bad type rejected.
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", [
            'inputs' => [[
                'key' => 'country',
                'label' => ['en' => 'Country', 'ar' => 'بلد'],
                'type' => 'country',
            ]],
        ])->assertStatus(422);

        // Bad required_at rejected.
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", [
            'inputs' => [[
                'key' => 'odd',
                'label' => ['en' => 'Odd', 'ar' => 'غريب'],
                'type' => 'text',
                'required_at' => 'someday',
            ]],
        ])->assertStatus(422);

        // Key immutable.
        $this->putJson("/api/v1/admin/order-flow-inputs/{$inputId}", ['key' => 'renamed'])
            ->assertStatus(422);

        // Optional input deletes freely.
        $this->deleteJson("/api/v1/admin/order-flow-inputs/{$inputId}")->assertStatus(200);
    }

    public function test_required_input_cannot_be_removed_with_inflight_orders(): void
    {
        $this->seedInternationalInputs();
        $this->checkoutInternational([
            'from_country' => $this->countryA->id,
            'to_country' => $this->countryB->id,
        ]);

        $input = FlowInput::query()
            ->where('flow_id', $this->internationalFlow()->id)
            ->where('key', 'from_country')
            ->firstOrFail();

        Sanctum::actingAs($this->admin);

        // Deactivate blocked while the order is in-flight.
        $this->putJson("/api/v1/admin/order-flow-inputs/{$input->id}", ['is_active' => false])
            ->assertStatus(422);
        $this->deleteJson("/api/v1/admin/order-flow-inputs/{$input->id}")->assertStatus(422);
    }

    public function test_input_endpoints_require_permission(): void
    {
        $flow = $this->internationalFlow();
        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/admin/order-flows/{$flow->id}/inputs")->assertStatus(403);
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", [
            'inputs' => [[
                'key' => 'x',
                'label' => ['en' => 'X', 'ar' => 'س'],
                'type' => 'text',
            ]],
        ])->assertStatus(403);
    }

    public function test_arabic_labels_returned_for_ar_locale(): void
    {
        $this->seedInternationalInputs();
        Sanctum::actingAs($this->user);

        $response = $this->getJson(
            '/api/v1/general/order-flows/by-shipping-type/international',
            ['Accept-Language' => 'ar']
        );

        $response->assertStatus(200);
        $label = $response->json('data.inputs.0.label');
        $this->assertArrayHasKey('en', $label);
        $this->assertArrayHasKey('ar', $label);
        $this->assertNotEmpty($label['ar']);
    }

    // -----------------------------------------------------------------
    // Bilingual catalog + flow names
    // -----------------------------------------------------------------

    public function test_definition_names_are_bilingual_with_stable_codes(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/general/order-flows/by-shipping-type/international');
        $response->assertStatus(200);

        $flowName = $response->json('data.name');
        $this->assertSame('International Flow', $flowName['en']);
        $this->assertSame('المسار الدولي', $flowName['ar']);

        $byCode = collect($response->json('data.statuses'))->keyBy('code');
        $this->assertSame('Customs Clearance', $byCode['customs_clearance']['name']['en']);
        $this->assertSame('التخليص الجمركي', $byCode['customs_clearance']['name']['ar']);
        // Code stays the business identifier, never the translated name.
        $this->assertSame('customs_clearance', $byCode['customs_clearance']['code']);
    }

    public function test_catalog_update_accepts_bilingual_name(): void
    {
        $id = OrderStatus::query()->where('code', 'customs_clearance')->value('id');
        Sanctum::actingAs($this->admin);

        $resp = $this->putJson("/api/v1/admin/order-statuses/{$id}", [
            'name' => ['en' => 'Customs Clearance', 'ar' => 'التخليص الجمركي'],
        ]);

        $resp->assertOk();
        $this->assertSame('Customs Clearance', $resp->json('data.name.en'));
        $this->assertSame('التخليص الجمركي', $resp->json('data.name.ar'));
        $this->assertSame('customs_clearance', OrderStatus::query()->find($id)->code);
    }

    public function test_order_resource_names_are_bilingual(): void
    {
        $this->seedInternationalInputs();
        $order = $this->checkoutInternational([
            'from_country' => $this->countryA->id,
            'to_country' => $this->countryB->id,
        ]);
        $order->load(['flow', 'currentStatus']);
        Sanctum::actingAs($this->user);

        $resp = $this->getJson("/api/v1/general/orders/{$order->id}");
        $resp->assertOk();
        $this->assertSame('international', $resp->json('data.flow.code'));
        $this->assertSame('المسار الدولي', $resp->json('data.flow.name.ar'));
        $this->assertSame('pending', $resp->json('data.current_status.code'));
        $this->assertSame('قيد الانتظار', $resp->json('data.current_status.name.ar'));
    }

    // -----------------------------------------------------------------
    // Inactive inputs are retired, not fatal
    // -----------------------------------------------------------------

    public function test_inactive_input_values_are_ignored(): void
    {
        $flow = $this->internationalFlow();
        $input = FlowInput::create([
            'flow_id' => $flow->id,
            'key' => 'legacy_note',
            'label' => ['en' => 'Legacy note', 'ar' => 'ملاحظة قديمة'],
            'type' => 'text',
            'required' => false,
            'required_at' => 'checkout',
            'sort_order' => 99,
            'is_active' => false,
        ]);

        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);

        // Retired key passes validation but is never persisted.
        $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload([
            'shipping_type' => 'international',
            'flow_values' => ['legacy_note' => 'stale client'],
        ]))->assertStatus(200);

        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $this->assertFalse(OrderFlowValue::query()->where('order_id', $order->id)
            ->where('input_key', 'legacy_note')->exists());
        $this->assertTrue($input->fresh()->exists());
    }

    // -----------------------------------------------------------------
    // Staff vs admin permission split
    // -----------------------------------------------------------------

    public function test_staff_can_transition_but_not_without_target_permission(): void
    {
        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff-inputs@example.com',
            'password' => bcrypt('password'),
            'type' => 'staff',
            'is_active' => true,
            'email_verified_at' => now()]);
        if (Schema::hasTable('permissions')) {
            foreach (['update-order-status', 'change-order-status.processing'] as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'api']);
                $staff->givePermissionTo($perm);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        // Transition allowed: general + granular target held.
        Sanctum::actingAs($staff);
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(200);

        // Next target without its granular permission: 403, unchanged.
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'packed'])
            ->assertStatus(403);
        $this->assertSame('processing', $order->fresh()->status);

        // Legacy OR-compat: update-order-status still reaches input writes.
        $flow = $this->internationalFlow();
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", [
            'inputs' => [[
                'key' => 'staff_try',
                'label' => ['en' => 'X', 'ar' => 'س'],
                'type' => 'text',
            ]],
        ])->assertStatus(201);

        // Permissionless users are still fenced out (403 before validation).
        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", [
            'inputs' => [[
                'key' => 'user_try',
                'label' => ['en' => 'Y', 'ar' => 'ص'],
                'type' => 'text',
            ]],
        ])->assertStatus(403);
    }
}

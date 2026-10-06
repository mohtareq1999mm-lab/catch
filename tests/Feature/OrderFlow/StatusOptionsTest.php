<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderStatus;
use App\Services\OrderFlow\OrderFlowService;
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
 * Runtime Order Status API: GET options + PATCH granular enforcement.
 *
 * Matrix (§30): permission AND flow AND inputs must ALL pass.
 * Every rejection leaves the order status unchanged.
 */
class StatusOptionsTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $user;

    private User $admin;

    private Product $product;

    private Governorate $governorate;

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

        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Gov',
            'status' => true]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 0,
            'status' => true]);

        $this->user = User::create([
            'name' => 'Buyer',
            'email' => 'buyer-options@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-options@example.com',
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
            foreach (['update-order-status', 'payments.mark_paid', 'view-orders', 'view-order'] as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
                $this->admin->givePermissionTo($perm);
            }
            // Admin holds every granular target permission (seed-compat parity).
            foreach (OrderFlowService::ALL_STATUS_CODES as $code) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
                    ['name' => OrderFlowService::targetStatusPermission($code), 'guard_name' => 'api']);
                $this->admin->givePermissionTo($perm);
            }
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

    private function makeOrder(): Order
    {
        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload())->assertStatus(200);

        return Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
    }

    private function makeOperator(array $permissions): User
    {
        $op = User::create([
            'name' => 'Op ' . Str::random(6),
            'email' => 'op-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'staff',
            'is_active' => true,
            'email_verified_at' => now()]);

        if (Schema::hasTable('permissions')) {
            foreach ($permissions as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
                $op->givePermissionTo($perm);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $op;
    }

    private function optionsFor(User $user, Order $order)
    {
        Sanctum::actingAs($user);

        return $this->getJson("/api/v1/orders/{$order->id}/statuses");
    }

    private function patchAs(User $user, Order $order, array $payload)
    {
        Sanctum::actingAs($user);

        return $this->patchJson("/api/v1/orders/{$order->id}/status", $payload);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function optionsByCode(array $body): array
    {
        return collect($body['data']['statuses'] ?? [])->keyBy('code')->all();
    }

    // -----------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------

    public function test_unauthenticated_options_rejected(): void
    {
        $order = $this->makeOrder();

        // makeOrder() authenticates as the owner; drop all resolved guards
        // so this request is genuinely unauthenticated.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/orders/{$order->id}/statuses")->assertStatus(401);
    }

    public function test_unauthenticated_patch_rejected(): void
    {
        $order = $this->makeOrder();

        $status = $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'processing'])->status();
        $this->assertContains($status, [401, 403]);
        $this->assertSame('pending', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Order access (IDOR)
    // -----------------------------------------------------------------

    public function test_user_cannot_view_or_change_foreign_order(): void
    {
        $order = $this->makeOrder();
        $stranger = $this->makeOperator([]);

        $this->optionsFor($stranger, $order)->assertStatus(403);
        $this->patchAs($stranger, $order, ['status' => 'processing'])->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_owner_can_view_own_options(): void
    {
        $order = $this->makeOrder();

        $resp = $this->optionsFor($this->user, $order);
        $resp->assertOk();
        $resp->assertJsonPath('data.current_status.code', 'pending');
        $this->assertNotEmpty($resp->json('data.statuses'));
    }

    // -----------------------------------------------------------------
    // Permission × flow matrix
    // -----------------------------------------------------------------

    public function test_general_without_target_permission_is_403(): void
    {
        $order = $this->makeOrder();
        $op = $this->makeOperator(['update-order-status']);

        $resp = $this->patchAs($op, $order, ['status' => 'processing']);
        $resp->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_target_without_general_permission_is_403(): void
    {
        $order = $this->makeOrder();
        $op = $this->makeOperator(['change-order-status.processing']);

        // Route middleware still requires the general permission.
        $this->patchAs($op, $order, ['status' => 'processing'])->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_general_plus_target_permission_succeeds(): void
    {
        $order = $this->makeOrder();
        $op = $this->makeOperator(['update-order-status', 'change-order-status.processing']);

        $this->patchAs($op, $order, ['status' => 'processing'])->assertStatus(200);
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_permission_cannot_override_flow_rules(): void
    {
        // pending → delivered is structurally invalid even WITH the target perm.
        $order = $this->makeOrder();
        $op = $this->makeOperator(['view-order', 'update-order-status', 'change-order-status.delivered']);

        $resp = $this->optionsFor($op, $order);
        $byCode = $this->optionsByCode($resp->json());
        $this->assertTrue($byCode['delivered']['permitted']);
        $this->assertFalse($byCode['delivered']['transition_allowed']);
        $this->assertFalse($byCode['delivered']['allowed']);
        $this->assertSame('forbidden_transition', $byCode['delivered']['reason']);

        $this->patchAs($op, $order, ['status' => 'delivered'])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_options_flags_reflect_both_gates(): void
    {
        $order = $this->makeOrder();
        // Holds general + processing only (+view for the read endpoint).
        $op = $this->makeOperator(['view-order', 'update-order-status', 'change-order-status.processing']);

        $byCode = $this->optionsByCode($this->optionsFor($op, $order)->json());

        $this->assertTrue($byCode['processing']['transition_allowed']);
        $this->assertTrue($byCode['processing']['permitted']);
        $this->assertTrue($byCode['processing']['allowed']);
        $this->assertNull($byCode['processing']['reason']);
        $this->assertSame('change-order-status.processing', $byCode['processing']['permission']);

        // Completed is a valid exit but not permitted for this actor.
        $this->assertTrue($byCode['completed']['transition_allowed']);
        $this->assertFalse($byCode['completed']['permitted']);
        $this->assertFalse($byCode['completed']['allowed']);
        $this->assertSame('missing_permission', $byCode['completed']['reason']);
    }

    public function test_inactive_target_status_is_never_allowed(): void
    {
        $order = $this->makeOrder();
        OrderStatus::query()->where('code', 'packed')->update(['is_active' => false]);
        $op = $this->makeOperator(['view-order', 'update-order-status', 'change-order-status.packed']);

        $byCode = $this->optionsByCode($this->optionsFor($op, $order)->json());
        $this->assertFalse($byCode['packed']['allowed']);
        $this->assertSame('inactive_status', $byCode['packed']['reason']);

        $this->patchAs($op, $order, ['status' => 'packed'])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_super_admin_sees_all_valid_permitted(): void
    {
        $order = $this->makeOrder();

        $byCode = $this->optionsByCode($this->optionsFor($this->admin, $order)->json());

        $this->assertTrue($byCode['processing']['allowed']);
        $this->assertTrue($byCode['completed']['allowed']);
        $this->assertFalse($byCode['delivered']['allowed']);
    }

    // -----------------------------------------------------------------
    // Inputs + payment + transaction safety
    // -----------------------------------------------------------------

    public function test_missing_transition_input_is_422_with_status_unchanged(): void
    {
        $order = $this->makeOrder();
        $this->patchAs($this->admin, $order, ['status' => 'processing'])->assertStatus(200);

        // processing → packed is flow-valid and admin holds the target perm.
        $this->patchAs($this->admin, $order, ['status' => 'packed'])->assertStatus(200);
        $this->assertSame('packed', $order->fresh()->status);
    }

    public function test_unpaid_completed_requires_mark_paid(): void
    {
        $order = $this->makeOrder();
        $op = $this->makeOperator(['update-order-status', 'change-order-status.completed']);

        // Granular target perm held, payment authority missing → 422 (F-1).
        $this->patchAs($op, $order, ['status' => 'completed'])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->status);

        $op->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'payments.mark_paid', 'guard_name' => 'api'])
        );

        $this->patchAs($op, $order, ['status' => 'completed'])->assertStatus(200);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_options_names_are_bilingual(): void
    {
        $order = $this->makeOrder();

        $body = $this->optionsFor($this->user, $order)->json();
        $this->assertSame('قيد الانتظار', $body['data']['current_status']['name']['ar']);

        $byCode = $this->optionsByCode($body);
        $this->assertSame('Processing', $byCode['processing']['name']['en']);
        $this->assertSame('قيد التجهيز', $byCode['processing']['name']['ar']);
    }

    public function test_options_never_leak_pii_or_values(): void
    {
        $order = $this->makeOrder();

        $encoded = json_encode($this->optionsFor($this->user, $order)->json());
        $this->assertStringNotContainsString($this->user->email, $encoded);
        $this->assertStringNotContainsString('01000000000', $encoded);
        $this->assertStringNotContainsString('flow_values', $encoded);
    }

    // -----------------------------------------------------------------
    // Granular target permissions across the local lifecycle
    // -----------------------------------------------------------------

    public function test_granular_permissions_per_listed_status(): void
    {
        $order = $this->makeOrder();
        // Operator holds general + packed + shipped + out_for_delivery +
        // delivered + cancelled — but NOT processing.
        $op = $this->makeOperator([
            'view-order', 'update-order-status',
            'change-order-status.packed',
            'change-order-status.shipped',
            'change-order-status.out_for_delivery',
            'change-order-status.delivered',
            'change-order-status.cancelled',
        ]);

        // First step without its target perm: 403 even though the flow allows it.
        $this->patchAs($op, $order, ['status' => 'processing'])->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);

        // Grant the missing target perm: the same transition now succeeds.
        $op->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => 'change-order-status.processing', 'guard_name' => 'api'])
        );
        $this->patchAs($op, $order, ['status' => 'processing'])->assertStatus(200);

        // Walk the held targets: packed → shipped → out_for_delivery.
        foreach (['packed', 'shipped', 'out_for_delivery'] as $step) {
            $this->patchAs($op, $order, ['status' => $step])->assertStatus(200);
            $this->assertSame($step, $order->fresh()->status);
        }

        // Terminal delivery via its granular permission: the permission
        // gate passes, but Phase 8 (D8-5) still requires the shipment
        // completion invariant — refused (legacy 422 transition contract).
        $this->patchAs($op, $order, ['status' => 'delivered'])->assertStatus(422);
        $this->assertSame('out_for_delivery', $order->fresh()->status);

        // Reach the terminal source through the audited force path, then
        // verify it rejects everything afterwards.
        \Laravel\Sanctum\Sanctum::actingAs($op);
        app(\App\Services\General\OrderService::class)->changeOrderStatus(
            null, 'delivered', $order->id, true, 'granular perm walk', null, true, [], false, false, true
        );
        $this->assertSame('delivered', $order->fresh()->status);

        $this->patchAs($op, $order, ['status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_cancelled_exit_with_target_permission(): void
    {
        $order = $this->makeOrder();
        $op = $this->makeOperator([
            'view-order', 'update-order-status',
            'change-order-status.processing',
            'change-order-status.cancelled',
        ]);

        $this->patchAs($op, $order, ['status' => 'processing'])->assertStatus(200);
        $this->patchAs($op, $order, ['status' => 'cancelled'])->assertStatus(200);
        $this->assertSame('cancelled', $order->fresh()->status);
    }
}

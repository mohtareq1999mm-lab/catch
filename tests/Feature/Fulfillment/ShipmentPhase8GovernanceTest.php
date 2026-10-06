<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\ShipmentStatus;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\OrderStatusHistory;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\General\OrderService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 8 (P8-2, P8-6, P8-7, P8-8, F8-9) — DAG single-source, generic-update
 * hardening, order-completion guard + audited force path, mirror discipline,
 * concurrency semantics, inventory/payment bypass. Real database, no mocks.
 */
class ShipmentPhase8GovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-9', 'name' => 'Main 9', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-91',
            'barcode' => 'LOC-A-91', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P9', 'slug' => 'p9-' . uniqid(), 'sku' => 'SKU-SHIP-9',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    private function makeCompletedOrder(int $qty = 2, array $overrides = []): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create(array_merge([
            'user_id' => $user->id, 'name' => 'O9', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100 * $qty, 'total_price' => 100 * $qty,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P9',
            'product_sku' => 'SKU-SHIP-9', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return $order->refresh();
    }

    private function makeReadyFulfillment(Order $order, string $key): Fulfillment
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $this->warehouse->id, $key);
        $owner = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship'] as $state) {
            $owner->transition($fulfillment, $state);
        }

        return $fulfillment->refresh();
    }

    private function makeAdminWith(string $permission): User
    {
        $admin = User::create([
            'name' => 'Ship Admin', 'email' => 'ship-admin-' . uniqid() . '@example.com',
            'password' => bcrypt('password'), 'type' => 'admin', 'is_active' => true,
        ]);
        if (Schema::hasTable('permissions')) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'api']
            );
            $admin->givePermissionTo($perm);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    // ------------------------------------------------------------------
    // P8-2 / F8-9: single DAG
    // ------------------------------------------------------------------

    public function test_enum_projects_the_model_dag(): void
    {
        foreach (ShipmentStatus::cases() as $case) {
            $fromEnum = array_map(fn ($c) => $c->value, $case->allowedTransitions());
            $fromModel = Shipment::allowedTransitions($case->value);
            sort($fromEnum);
            sort($fromModel);
            $this->assertSame($fromModel, $fromEnum, "DAG divergence at {$case->value}");
        }
    }

    public function test_unknown_status_transitions_nowhere(): void
    {
        $this->assertSame([], Shipment::allowedTransitions('ghost'));

        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p82-a');
        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment, [], null);

        try {
            app(ShipmentService::class)->updateStatus($shipment->id, 'ghost');
            $this->fail('unknown status must be refused');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('cannot transition', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // P8-6: update() hardening
    // ------------------------------------------------------------------

    /**
     * @dataProvider protectedFieldProvider
     */
    public function test_update_blocks_transition_owned_fields(string $field): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p86-' . $field);
        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment, [], null);

        try {
            app(ShipmentService::class)->update($shipment->id, [$field => 'delivered']);
            $this->fail("mass-update of {$field} must be blocked");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('transition-authority owned', $e->getMessage());
        }

        $this->assertEquals('label_created', $shipment->refresh()->status);
    }

    public static function protectedFieldProvider(): array
    {
        return [
            'status' => ['status'],
            'STATUS case variant' => ['STATUS'],
            'cancelled_at' => ['cancelled_at'],
            'cancelled_by' => ['cancelled_by'],
            'cancel_source' => ['cancel_source'],
            'cancel_reason' => ['cancel_reason'],
            'shipped_at' => ['shipped_at'],
            'delivered_at' => ['delivered_at'],
            'fulfillment_id' => ['fulfillment_id'],
            'order_id' => ['order_id'],
            'idempotency_key' => ['idempotency_key'],
        ];
    }

    public function test_update_allows_operational_fields_and_syncs_mirror(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p86-ok');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $updated = $service->update($shipment->id, [
            'courier' => 'LocalPost',
            'tracking_number' => 'P8-' . uniqid(),
            'notes' => 'gate note',
        ]);

        $this->assertEquals('LocalPost', $updated->courier);
        $this->assertEquals('label_created', $updated->status);

        $mirror = $order->refresh();
        $this->assertEquals('LocalPost', $mirror->courier_name);
        $this->assertEquals($updated->tracking_number, $mirror->tracking_number);
    }

    // ------------------------------------------------------------------
    // P8-7: completion guard + force path
    // ------------------------------------------------------------------

    public function test_manual_delivered_allowed_when_invariant_holds(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p87-a');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->dispatch($shipment->id);
        $service->markDelivered($shipment->id);

        $this->assertEquals('delivered', $order->refresh()->status);
    }

    public function test_manual_delivered_refused_with_open_fulfillment(): void
    {
        $order = $this->makeCompletedOrder();
        $this->makeReadyFulfillment($order, 'p87-b');

        try {
            app(OrderService::class)->changeOrderStatus(null, 'delivered', $order->id);
            $this->fail('open fulfillment must block manual delivered');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('every fulfillment delivered', $e->getMessage());
        }
        $this->assertEquals('completed', $order->refresh()->status);
    }

    public function test_manual_delivered_refused_when_unpaid(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p87-c');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->dispatch($shipment->id);

        // Payment voided after dispatch (disputed/voided authorization):
        // the fulfillment side is shippable but completion must refuse.
        $order->update(['payment_status' => Order::PAYMENT_STATUS_PENDING]);

        // Delivery is recorded (goods moved); only ORDER completion is
        // deferred while payment is voided — no exception, no half-order.
        $service->markDelivered($shipment->id);
        $this->assertEquals('delivered', $shipment->refresh()->status);
        $this->assertEquals('delivered', $fulfillment->refresh()->status);
        $this->assertEquals('completed', $order->refresh()->status);

        // The manual path refuses while payment is voided.
        try {
            app(OrderService::class)->changeOrderStatus(null, 'delivered', $order->id);
            $this->fail('unpaid order must not transition to delivered');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('payment must be successful', $e->getMessage());
        }

        // Recovery loop: once payment is restored, replaying delivery
        // completes the order without re-moving anything.
        $order->update(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);
        $service->markDelivered($shipment->id);
        $this->assertEquals('delivered', $order->refresh()->status);
    }

    public function test_manual_delivered_refused_with_zero_fulfillments(): void
    {
        $order = $this->makeCompletedOrder();

        try {
            app(OrderService::class)->changeOrderStatus(null, 'delivered', $order->id);
            $this->fail('zero fulfillments must block manual delivered');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('every fulfillment delivered', $e->getMessage());
        }
    }

    public function test_force_delivered_requires_permission_reason_and_actor(): void
    {
        $order = $this->makeCompletedOrder();

        // Unauthenticated force: refused (permission message is localized,
        // so only the loud refusal itself is asserted).
        try {
            app(OrderService::class)->changeOrderStatus(null, 'delivered', $order->id, true, 'recovery', null, true, [], false, false, true);
            $this->fail('unauthenticated force must be refused');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertNotEquals('delivered', $order->refresh()->status);
        }

        // Authenticated but unpermitted: refused.
        $plain = User::factory()->create(['type' => 'customer']);
        Sanctum::actingAs($plain);
        try {
            app(OrderService::class)->changeOrderStatus(null, 'delivered', $order->id, true, 'recovery', null, true, [], false, false, true);
            $this->fail('unpermitted force must be refused');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertNotEquals('delivered', $order->refresh()->status);
        }

        // Permitted but reasonless: refused.
        $admin = $this->makeAdminWith('update-order-status');
        Sanctum::actingAs($admin);
        try {
            app(OrderService::class)->changeOrderStatus(null, 'delivered', $order->id, true, null, null, true, [], false, false, true);
            $this->fail('reasonless force must be refused');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }
        $this->assertEquals('completed', $order->refresh()->status);
    }

    public function test_force_delivered_succeeds_and_is_audited(): void
    {
        $order = $this->makeCompletedOrder();
        $admin = $this->makeAdminWith('update-order-status');
        Sanctum::actingAs($admin);

        $result = app(OrderService::class)->changeOrderStatus(
            null, 'delivered', $order->id, true, 'service recovery #42', null, true, [], false, false, true
        );

        $this->assertNotFalse($result);
        $this->assertEquals('delivered', $order->refresh()->status);

        $history = OrderStatusHistory::where('order_id', $order->id)
            ->where('new_status', 'delivered')
            ->latest('id')
            ->first();
        $this->assertNotNull($history);
        $this->assertStringContainsString('service recovery #42', (string) $history->notes);
        $this->assertStringContainsString('force_delivered', (string) json_encode($history->metadata));
        $this->assertEquals($admin->id, (int) ($history->metadata['force_delivered_by'] ?? 0));
        $this->assertEquals($admin->id, (int) $history->changed_by);
    }

    public function test_shipment_delivery_never_uses_force_path(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p87-d');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->dispatch($shipment->id);
        $service->markDelivered($shipment->id);

        $histories = OrderStatusHistory::where('order_id', $order->id)
            ->where('new_status', 'delivered')
            ->get();
        foreach ($histories as $history) {
            $this->assertStringNotContainsString(
                'force_delivered',
                (string) json_encode($history->metadata)
            );
        }
    }

    // ------------------------------------------------------------------
    // P8-8: mirror discipline
    // ------------------------------------------------------------------

    public function test_mirror_tracks_transitions_and_never_drives_state(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p88-a');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, ['tracking_number' => 'P8-M1'], null);
        $this->assertEquals('label_created', $order->refresh()->shipment_status);
        $this->assertEquals('P8-M1', $order->refresh()->tracking_number);

        $service->dispatch($shipment->id);
        $this->assertEquals('picked_up', $order->refresh()->shipment_status);

        // The mirror is a read model: writing it directly moves nothing.
        $order->update(['shipment_status' => 'delivered']);
        $this->assertEquals('picked_up', $shipment->refresh()->status);
        $this->assertEquals('shipped', $fulfillment->refresh()->status);
        $this->assertEquals('completed', $order->refresh()->status);
    }

    public function test_mirror_never_wipes_operator_data_with_null(): void
    {
        $order = $this->makeCompletedOrder();
        $order->update(['tracking_number' => 'ADMIN-SET', 'courier_name' => 'AdminCourier']);
        $fulfillment = $this->makeReadyFulfillment($order, 'p88-b');

        // Shipment carries no tracking/courier: operator values survive.
        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment, [], null);
        app(ShipmentService::class)->dispatch($shipment->id);

        $mirror = $order->refresh();
        $this->assertEquals('picked_up', $mirror->shipment_status);
        $this->assertEquals('ADMIN-SET', $mirror->tracking_number);
        $this->assertEquals('AdminCourier', $mirror->courier_name);
    }

    // ------------------------------------------------------------------
    // Concurrency semantics (single-process) + bypass
    // ------------------------------------------------------------------

    public function test_shipment_chain_touches_no_inventory_or_payment(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p89-a');
        $service = app(ShipmentService::class);

        $stockBefore = $this->product->refresh()->stock_quantity;
        $reservedBefore = $this->product->refresh()->reserved_quantity;
        $paymentBefore = $order->payment_status;

        $shipment = $service->createForFulfillment($fulfillment, ['courier' => 'Local'], null);
        $service->dispatch($shipment->id);
        $service->markDelivered($shipment->id);
        $service->update($shipment->id, ['notes' => 'x']);

        $this->assertEquals($stockBefore, $this->product->refresh()->stock_quantity);
        $this->assertEquals($reservedBefore, $this->product->refresh()->reserved_quantity);
        $this->assertEquals($paymentBefore, $order->refresh()->payment_status);
        $this->assertEquals(Order::PAYMENT_STATUS_SUCCESS, $order->refresh()->payment_status);
    }

    public function test_sequential_double_create_second_loses(): void
    {
        // Single-process approximation of the creation race: the second
        // sequential attempt loses loudly at the application invariant
        // (a true lost race would additionally hit UNIQUE backstop).
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p89-b');
        $service = app(ShipmentService::class);

        $service->createForFulfillment($fulfillment, [], null);

        try {
            $service->createForFulfillment($fulfillment, [], 'late-key');
            $this->fail('second create must lose');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('active shipment', $e->getMessage());
        }
    }
}

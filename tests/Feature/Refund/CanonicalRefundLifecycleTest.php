<?php

declare(strict_types=1);

namespace Tests\Feature\Refund;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Inventory\OrderReservationService;
use App\Services\Refund\RefundService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\Review;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * Phase 10 unification — canonical refund lifecycle.
 *
 * Proves the single RefundService flow end to end on real MySQL:
 * request (PENDING) -> approve (APPROVED) / reject (REJECTED), with
 * server-authoritative amounts, over-refund protection, exactly-once side
 * effects, zero gateway money movement, zero wallet involvement and an
 * untouched payment status.
 */
class CanonicalRefundLifecycleTest extends CurrencyTestCase
{
    private const CUSTOMER_PREFIX = '/api/v1/refunds';

    private const ADMIN_PREFIX = '/api/v1/admin/refunds';

    protected User $customer;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // The refunds throttle (5/min) is a production fraud posture; tests
        // exercise multi-request flows and must not 429 mid-suite.
        RateLimiter::for('refunds', fn () => Limit::none());

        $this->seedCurrencyData();

        $this->customer = $this->createCustomer();

        foreach (['view-refunds', 'payments.refund'] as $permission) {
            Permission::findOrCreate($permission, 'api');
        }
        $this->admin = $this->createUserWithPermissions(['view-refunds', 'payments.refund'], 'admin');
        // Marvel request-controller gates (show/update/destroy admin branch)
        // ride on the super_admin role; the canonical service itself does
        // not depend on it.
        $this->admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'api'));

        $warehouse = Warehouse::create([
            'code' => 'WH-RF-' . uniqid(), 'name' => 'Refund WH', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'R-' . uniqid(),
            'barcode' => 'LOC-R-' . uniqid(), 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'Refund P', 'slug' => 'refund-p-' . uniqid(), 'sku' => 'SKU-RF-' . uniqid(),
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    protected function paidOrder(User $customer, float $total = 100.00, string $currency = 'KWD', array $overrides = []): Order
    {
        $flowColumns = \App\Services\OrderFlow\OrderFlowService::orderFlowColumnsAvailable()
            ? app(\App\Services\OrderFlow\OrderFlowService::class)->columnsForNewOrder('local', Order::ORDER_STATUS_COMPLETED)
            : [];

        $order = Order::create(array_merge([
            'user_id' => $customer->id,
            'name' => 'Refund Order',
            'user_phone' => '+201234567890',
            'user_email' => $customer->email,
            'address' => json_encode(['city' => 'Cairo']),
            'price' => $total,
            'total_price' => $total,
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            // Unmocked provider on purpose: the canonical flow must never
            // resolve a gateway, so approval succeeds with no adapter bound.
            'payment_gateway' => 'myfatoorah',
            'currency_code' => $currency,
            'catalog_currency_code' => $currency,
            'base_currency_code' => 'EGP',
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
        ], $flowColumns, $overrides));

        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_sku' => $this->product->sku,
            'product_quantity' => 2,
            'product_price' => $total / 2,
            'product_total_price' => $total,
        ]);

        app(OrderReservationService::class)->reserveForOrder($order->refresh());
        app(OrderReservationService::class)->commit($order->refresh());

        return $order->refresh();
    }

    protected function requestRefund(Order $order, User $customer, float $amount): Refund
    {
        Sanctum::actingAs($customer, ['*']);

        $response = $this->postJson(self::CUSTOMER_PREFIX, [
            'order_id' => $order->id,
            'amount' => $amount,
            'title' => 'Damaged item',
        ]);

        $response->assertStatus(201);

        // NOTE: never re-query latest()->first() — MySQL timestamps have
        // second precision, so same-second rows make latest() arbitrary.
        return Refund::query()->findOrFail($response->json('data.id'));
    }

    protected function approveRefund(int $refundId, ?string $note = null): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson(self::ADMIN_PREFIX . '/' . $refundId . '/approve', [
            'decision_note' => $note ?? 'QA approved',
        ])->assertStatus(200);
    }

    // ---------------- request ----------------

    /** @test */
    public function customer_can_request_refund_for_own_order(): void
    {
        $order = $this->paidOrder($this->customer);

        $refund = $this->requestRefund($order, $this->customer, 100.00);

        $this->assertSame('pending', $refund->status);
        $this->assertEquals(100.00, (float) $refund->amount);
        $this->assertSame('KWD', $refund->currency);
        $this->assertNull($refund->decided_by);

        // Request moves nothing: inventory stays committed, payment untouched.
        $this->assertSame(Order::INVENTORY_STATE_COMMITTED, $order->fresh()->inventory_state);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);
        $this->assertSame(48, $this->product->fresh()->stock_quantity);
    }

    /** @test */
    public function customer_cannot_request_refund_for_another_customers_order(): void
    {
        $order = $this->paidOrder($this->customer);
        $intruder = $this->createCustomer();
        Sanctum::actingAs($intruder, ['*']);

        $this->postJson(self::CUSTOMER_PREFIX, [
            'order_id' => $order->id,
            'amount' => 10.00,
            'title' => 'Intruder',
        ])->assertStatus(403);

        $this->assertSame(0, Refund::query()->where('order_id', $order->id)->count());
    }

    /** @test */
    public function invalid_amounts_are_rejected(): void
    {
        $order = $this->paidOrder($this->customer);
        Sanctum::actingAs($this->customer, ['*']);

        foreach ([0, -5.00, 100.01] as $amount) {
            $this->postJson(self::CUSTOMER_PREFIX, [
                'order_id' => $order->id,
                'amount' => $amount,
                'title' => 'Bad amount',
            ])->assertStatus(422);
        }

        $this->assertSame(0, Refund::query()->where('order_id', $order->id)->count());
    }

    /** @test */
    public function mismatched_currency_is_rejected(): void
    {
        $order = $this->paidOrder($this->customer);
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson(self::CUSTOMER_PREFIX, [
            'order_id' => $order->id,
            'amount' => 10.00,
            'currency' => 'USD',
            'title' => 'Wrong currency',
        ])->assertStatus(422);

        $this->assertSame(0, Refund::query()->where('order_id', $order->id)->count());
    }

    /** @test */
    public function unpaid_orders_cannot_be_refunded(): void
    {
        $order = $this->paidOrder($this->customer);
        $order->update(['payment_status' => Order::PAYMENT_STATUS_PENDING]);
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson(self::CUSTOMER_PREFIX, [
            'order_id' => $order->id,
            'amount' => 10.00,
            'title' => 'Unpaid',
        ])->assertStatus(422);
    }

    // ---------------- approval ----------------

    /** @test */
    public function admin_can_approve_full_refund_with_exactly_once_effects(): void
    {
        $order = $this->paidOrder($this->customer);
        $refund = $this->requestRefund($order, $this->customer, 100.00);

        $this->approveRefund((int) $refund->id);

        $fresh = $refund->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->admin->id, (int) $fresh->decided_by);
        $this->assertNotNull($fresh->decided_at);

        // Full refund: committed inventory restored exactly once.
        $this->assertSame(50, $this->product->fresh()->stock_quantity);
        $this->assertSame(Order::INVENTORY_STATE_RESTORED, $order->fresh()->inventory_state);

        // Payment domain untouched: no false REFUNDED marker, no lifecycle move.
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $order->fresh()->status);

        // Duplicate approval: fail-closed, no duplicate side effects.
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson(self::ADMIN_PREFIX . '/' . $refund->id . '/approve', [
            'decision_note' => 'again',
        ])->assertStatus(400);

        $this->assertSame(50, $this->product->fresh()->stock_quantity);
        $this->assertSame('approved', $refund->fresh()->status);
    }

    /** @test */
    public function admin_can_reject_without_side_effects(): void
    {
        $order = $this->paidOrder($this->customer);
        $refund = $this->requestRefund($order, $this->customer, 100.00);

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson(self::ADMIN_PREFIX . '/' . $refund->id . '/reject', [
            'decision_note' => 'Out of policy',
        ])->assertStatus(200);

        $fresh = $refund->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($this->admin->id, (int) $fresh->decided_by);

        $this->assertSame(48, $this->product->fresh()->stock_quantity);
        $this->assertSame(Order::INVENTORY_STATE_COMMITTED, $order->fresh()->inventory_state);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);

        // Rejected rows stay decided: approve-after-reject fails closed.
        $this->postJson(self::ADMIN_PREFIX . '/' . $refund->id . '/approve', [
            'decision_note' => 'too late',
        ])->assertStatus(400);
    }

    /** @test */
    public function partial_refunds_accumulate_and_cap_at_paid_total(): void
    {
        $order = $this->paidOrder($this->customer);

        $first = $this->requestRefund($order, $this->customer, 30.00);
        $this->approveRefund((int) $first->id);

        // Partial: accounting only — stock and reviews stay put.
        $this->assertSame(48, $this->product->fresh()->stock_quantity);
        $this->assertSame(Order::INVENTORY_STATE_COMMITTED, $order->fresh()->inventory_state);

        $second = $this->requestRefund($order, $this->customer, 20.00);
        $this->approveRefund((int) $second->id);

        $third = $this->requestRefund($order, $this->customer, 50.00);
        $this->approveRefund((int) $third->id);

        // Cumulative full: restoration now runs exactly once (50, not 52).
        $this->assertSame(50, $this->product->fresh()->stock_quantity);
        $this->assertSame(Order::INVENTORY_STATE_RESTORED, $order->fresh()->inventory_state);

        // Over-refund: the fourth request is refused at the gate.
        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson(self::CUSTOMER_PREFIX, [
            'order_id' => $order->id,
            'amount' => 1.00,
            'title' => 'Too much',
        ])->assertStatus(422);

        $remaining = app(RefundService::class)->remainingFor($order->fresh());
        $this->assertSame(0, $remaining['remaining_minor']);
    }

    /** @test */
    public function reviews_are_never_deleted_by_refunds(): void
    {
        // Schema limitation (pinned): reviews carry no order/item scope (no
        // order_id column), so no refund — partial or full — may determine
        // which reviews belong to it. Refunds leave reviews untouched.
        $order = $this->paidOrder($this->customer);
        Review::create([
            'user_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'comment' => 'Great product',
            'rating' => 5,
        ]);

        $partial = $this->requestRefund($order, $this->customer, 30.00);
        $this->approveRefund((int) $partial->id);
        $this->assertSame(1, Review::query()->where('product_id', $this->product->id)->count());

        $rest = $this->requestRefund($order, $this->customer, 70.00);
        $this->approveRefund((int) $rest->id);
        $this->assertSame(1, Review::query()->where('product_id', $this->product->id)->count());
    }

    /** @test */
    public function concurrent_overlapping_approvals_cannot_over_refund(): void
    {
        $order = $this->paidOrder($this->customer);

        $first = $this->requestRefund($order, $this->customer, 60.00);
        $second = $this->requestRefund($order, $this->customer, 60.00);

        $service = app(RefundService::class);
        $service->approve((int) $first->id, $this->admin->id, 'first');

        // Sequential second approval for the same money fails closed: the
        // locked re-check sees only 40 remaining for a 60 request.
        $this->expectException(\RuntimeException::class);
        $service->approve((int) $second->id, $this->admin->id, 'second');
    }

    // ---------------- gateway / wallet / payment safety ----------------

    /** @test */
    public function approval_never_touches_gateway_wallet_or_payment_status(): void
    {
        $order = $this->paidOrder($this->customer);
        $refund = $this->requestRefund($order, $this->customer, 100.00);

        // No wallets/balances tables exist in the migrated schema — the fact
        // that approval succeeds at all proves no wallet path executes.
        $this->assertFalse(Schema::hasTable('wallets'));

        $this->approveRefund((int) $refund->id);

        // No provider outcome recorded anywhere on the transaction ledger.
        $this->assertSame([], $order->transactions()->first()?->gateway_response['_refunds'] ?? []);

        // Payment status is NOT falsely marked refunded (no money moved).
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $order->fresh()->status);
    }

    /** @test */
    public function obsolete_direct_gateway_refund_route_is_gone(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/admin/payments/1/refund', [
            'amount' => 10.00,
            'idempotency_key' => 'dead-route',
        ])->assertStatus(404);
    }

    // ---------------- customer list ----------------

    /** @test */
    public function customer_list_returns_only_own_refunds_with_summary(): void
    {
        $other = $this->createCustomer();
        $mine = $this->paidOrder($this->customer);
        $theirs = $this->paidOrder($other);

        $pending = $this->requestRefund($mine, $this->customer, 20.00);
        $approved = $this->requestRefund($mine, $this->customer, 30.00);
        $this->approveRefund((int) $approved->id);
        $rejected = $this->requestRefund($mine, $this->customer, 10.00);
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson(self::ADMIN_PREFIX . '/' . $rejected->id . '/reject', ['decision_note' => 'no'])->assertStatus(200);

        $this->requestRefund($theirs, $other, 50.00);

        Sanctum::actingAs($this->customer, ['*']);
        $response = $this->getJson(self::CUSTOMER_PREFIX . '?limit=15')->assertStatus(200);

        $summary = $response->json('data.summary');
        $this->assertSame(3, $summary['total_refunds']);
        $this->assertSame(1, $summary['pending_refunds']);
        $this->assertSame(1, $summary['approved_refunds']);
        $this->assertSame(1, $summary['rejected_refunds']);
        $this->assertEquals(30.00, $summary['totals_by_currency']['KWD']['approved_amount']);

        // No cross-customer leakage.
        foreach ($response->json('data.data') as $row) {
            $this->assertNotSame($theirs->id, $row['order']['id'] ?? null);
        }
        $this->assertSame(3, $response->json('data.total'));
    }

    /** @test */
    public function customer_summary_groups_totals_per_currency(): void
    {
        $kwdOrder = $this->paidOrder($this->customer, 100.00, 'KWD');
        $usdOrder = $this->paidOrder($this->customer, 50.00, 'USD');

        $first = $this->requestRefund($kwdOrder, $this->customer, 100.00);
        $this->approveRefund((int) $first->id);
        $second = $this->requestRefund($usdOrder, $this->customer, 50.00);
        $this->approveRefund((int) $second->id);

        Sanctum::actingAs($this->customer, ['*']);
        $summary = $this->getJson(self::CUSTOMER_PREFIX)->assertStatus(200)->json('data.summary');

        $this->assertEquals(100.00, $summary['totals_by_currency']['KWD']['approved_amount']);
        $this->assertEquals(50.00, $summary['totals_by_currency']['USD']['approved_amount']);
        $this->assertArrayNotHasKey('UNKNOWN', $summary['totals_by_currency']);
    }

    // ---------------- admin authorization ----------------

    /** @test */
    public function admin_endpoints_enforce_authorization(): void
    {
        $order = $this->paidOrder($this->customer);

        // True guests first (before any actingAs in this test): the route
        // auth:sanctum layer rejects with 401.
        $this->postJson(self::ADMIN_PREFIX . '/1/approve', [])->assertStatus(401);
        $this->getJson(self::ADMIN_PREFIX)->assertStatus(401);
        $this->postJson(self::CUSTOMER_PREFIX, ['order_id' => $order->id, 'amount' => 1])->assertStatus(401);

        $refund = $this->requestRefund($order, $this->customer, 10.00);

        // Customer without grants: 403 on every admin surface.
        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson(self::ADMIN_PREFIX . '/' . $refund->id . '/approve', [])->assertStatus(403);
        $this->postJson(self::ADMIN_PREFIX . '/' . $refund->id . '/reject', [])->assertStatus(403);
        $this->getJson(self::ADMIN_PREFIX)->assertStatus(403);

        // Customer cannot decide via the legacy update route either.
        $this->patchJson(self::CUSTOMER_PREFIX . '/' . $refund->id, ['status' => 'approved'])->assertStatus(403);
        $this->assertSame('pending', $refund->fresh()->status);
    }

    /** @test */
    public function decided_refunds_cannot_be_deleted_but_pending_can(): void
    {
        $order = $this->paidOrder($this->customer);
        $decided = $this->requestRefund($order, $this->customer, 10.00);
        $this->approveRefund((int) $decided->id);

        Sanctum::actingAs($this->admin, ['*']);
        $this->deleteJson(self::CUSTOMER_PREFIX . '/' . $decided->id)->assertStatus(422);
        $this->assertNotNull(Refund::query()->find($decided->id));

        $pending = $this->requestRefund($order, $this->customer, 5.00);
        Sanctum::actingAs($this->admin, ['*']);
        $this->deleteJson(self::CUSTOMER_PREFIX . '/' . $pending->id)->assertStatus(200);
        $this->assertNull(Refund::query()->find($pending->id));
    }
}

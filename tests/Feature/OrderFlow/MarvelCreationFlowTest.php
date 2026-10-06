<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * P2-1 (decision D2): the Marvel creation door never yields a flow-less row.
 *
 * Pins the creation contract of OrderRepository::createOrder/createChildOrder:
 * - every creation INSERT carries the default-local flow columns and the
 *   stage mirror (status == current_status_id.code == mapped order_status),
 * - the legacy `order_status` choice is honored as a STARTING STAGE
 *   arrangement (COD/cash -> processing, full-wallet -> completed) inside
 *   the creation INSERT — never as a transition,
 * - fail-closed paths (frozen legacy lifecycle codes, no active flow)
 *   abort BEFORE the INSERT — no half-created/flow-less row can persist.
 *
 * NOTE on the tolerated BadMethodCallException: the legacy Marvel attach
 * (`$order->products()->attach(...)`) references a relation that no longer
 * exists on the app Order model — this door is unrouted and dormant. The
 * INSERT happens BEFORE that crash, which is exactly the contract under
 * test; the dead attach is out of P2-1 scope (no Marvel rebuild).
 */
class MarvelCreationFlowTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $customer;

    /** @var array<int, string> legacy lifecycle codes frozen at creation (D5) */
    private const FROZEN_LEGACY_CODES = ['order-refunded', 'order-failed', 'order-at-local-facility', 'order-xyz-bogus'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->customer = User::create([
            'name' => 'Marvel Creation Customer',
            'email' => 'marvel-creation-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function repo(): \Marvel\Database\Repositories\OrderRepository
    {
        return app(\Marvel\Database\Repositories\OrderRepository::class);
    }

    /**
     * Drive the protected creation path with a minimal request payload —
     * the exact code the (dormant) Marvel admin store door runs.
     */
    private function createOrderViaRepo(array $payload): Order
    {
        $request = Request::create('/api/orders', 'POST', $payload);

        $method = new \ReflectionMethod($this->repo(), 'createOrder');
        $method->setAccessible(true);

        return $method->invoke($this->repo(), $request);
    }

    private function basePayload(string $legacyOrderStatus): array
    {
        return [
            'tracking_number' => 'MC-' . Str::random(10),
            'order_status' => $legacyOrderStatus,
            'payment_status' => 'pending',
            'customer_id' => $this->customer->id,
            'amount' => 100,
            'sales_tax' => 0,
            'delivery_fee' => 0,
            'discount' => 0,
            'paid_total' => 100,
            'total' => 100,
            'products' => [],
            'language' => 'en',
        ];
    }

    private function localFlowId(): int
    {
        return (int) OrderFlow::query()->where('shipping_type', 'local')->where('is_active', true)->value('id');
    }

    private function statusId(string $code): int
    {
        return (int) OrderStatus::query()->where('code', $code)->value('id');
    }

    private function requireFlowColumns(): void
    {
        if (!Schema::hasColumn('orders', 'flow_id')) {
            $this->markTestSkipped('Flow columns not migrated (pre-flow migration window).');
        }
    }

    /**
     * Walk the genuine creation path; the INSERT lands before the dormant
     * legacy attach crashes, so the persisted row IS the contract proof.
     */
    private function createAndFetchInsertedOrder(array $payload): Order
    {
        $tracking = $payload['tracking_number'];

        try {
            $this->createOrderViaRepo($payload);
            $this->fail('The dormant legacy attach must crash after the INSERT (relation removed).');
        } catch (\BadMethodCallException $e) {
            // Expected: legacy $order->products() relation no longer exists.
        }

        $order = Order::query()->where('tracking_number', $tracking)->first();
        $this->assertNotNull($order, 'Creation INSERT must have persisted the order row.');

        return $order;
    }

    /** @test */
    public function parent_insert_carries_local_flow_and_pending_start(): void
    {
        $this->requireFlowColumns();

        $order = $this->createAndFetchInsertedOrder($this->basePayload('order-pending'));

        $this->assertSame('local', $order->shipping_type);
        $this->assertSame($this->localFlowId(), (int) $order->flow_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame($this->statusId('pending'), (int) $order->current_status_id);
    }

    /** @test */
    public function parent_insert_honors_cod_processing_start(): void
    {
        $this->requireFlowColumns();

        $order = $this->createAndFetchInsertedOrder($this->basePayload('order-processing'));

        // Legacy COD/cash semantics: historical `processing` start honored as
        // a starting-stage arrangement, never as a transition. (The legacy
        // `order_status` column is not fillable and was never persisted by
        // this creation path — unchanged.)
        $this->assertSame('processing', $order->status);
        $this->assertSame($this->statusId('processing'), (int) $order->current_status_id);
        $this->assertSame($this->localFlowId(), (int) $order->flow_id);
        $this->assertSame('local', $order->shipping_type);
    }

    /** @test */
    public function parent_insert_honors_full_wallet_completed_start(): void
    {
        $this->requireFlowColumns();

        $order = $this->createAndFetchInsertedOrder($this->basePayload('order-completed'));

        // Legacy full-wallet semantics: paid-at-creation completes instantly.
        $this->assertSame('completed', $order->status);
        $this->assertSame($this->statusId('completed'), (int) $order->current_status_id);
        $this->assertSame($this->localFlowId(), (int) $order->flow_id);
    }

    /** @test */
    public function frozen_legacy_lifecycle_codes_reject_before_insert(): void
    {
        $this->requireFlowColumns();

        foreach (self::FROZEN_LEGACY_CODES as $legacyCode) {
            $ordersBefore = Order::count();

            try {
                $this->createOrderViaRepo($this->basePayload($legacyCode));
                $this->fail("Legacy lifecycle code [{$legacyCode}] must be frozen at creation (decision D5).");
            } catch (\Marvel\Exceptions\MarvelBadRequestException $e) {
                $this->assertStringContainsString($legacyCode, $e->getMessage());
            }

            // Fail-closed BEFORE the INSERT: no half-created or flow-less row.
            $this->assertSame($ordersBefore, Order::count(), "Code [{$legacyCode}] must not persist any row.");
        }
    }

    /** @test */
    public function creation_aborts_when_no_active_local_flow(): void
    {
        $this->requireFlowColumns();

        $ordersBefore = Order::count();

        // Direct deactivation bypasses admin guardrails (guardrails protect
        // the admin door, not the resolver contract under test here).
        OrderFlow::query()->where('shipping_type', 'local')->update(['is_active' => false]);

        try {
            $this->createOrderViaRepo($this->basePayload('order-pending'));
            $this->fail('Creation must abort when no active local flow exists.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Fail-closed BEFORE the INSERT.
        $this->assertSame($ordersBefore, Order::count());

        OrderFlow::query()->where('shipping_type', 'local')->update(['is_active' => true]);
    }

    /** @test */
    public function child_shop_orders_carry_the_same_flow(): void
    {
        $this->requireFlowColumns();

        // This app's schema has no marketplace/shop concept (products carry
        // no shop_id), so the legacy grouping lands every product under one
        // null-shop child — the child INSERT contract is what matters.
        $product = Product::create([
            'name' => 'Shop Product ' . Str::random(4),
            'slug' => 'shop-product-' . Str::random(8),
            'price' => 50.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
        ]);

        $parent = Order::create([
            'tracking_number' => 'MC-P-' . Str::random(10),
            'user_id' => $this->customer->id,
            'status' => 'pending',
        ]);

        $payload = $this->basePayload('order-pending');
        $payload['products'] = [
            ['product_id' => $product->id, 'order_quantity' => 1, 'subtotal' => 50],
        ];

        // createOrder's dormant attach crashes before createChildOrder can
        // run, so drive the (public) child-creation path directly — the
        // same merge runs on the child INSERT there.
        $request = Request::create('/api/orders', 'POST', $payload);

        try {
            $this->repo()->createChildOrder($parent->id, $request);
            $this->fail('The dormant legacy attach must crash after the child INSERT.');
        } catch (\BadMethodCallException $e) {
            // Expected: legacy $order->products() relation no longer exists.
        }

        $child = Order::query()
            ->where('parent_id', $parent->id)
            ->first();

        $this->assertNotNull($child, 'Child (shop) order must be created for a grouped cart.');
        $this->assertSame($this->localFlowId(), (int) $child->flow_id);
        $this->assertSame('local', $child->shipping_type);
        $this->assertSame('pending', $child->status);
        $this->assertSame($this->statusId('pending'), (int) $child->current_status_id);
    }
}

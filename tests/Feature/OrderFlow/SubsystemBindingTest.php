<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * P4: Fulfillment, Shipment and Payment are operational subsystems — none
 * is a second order lifecycle.
 *
 * - Fulfillment: a full release→delivered operational cycle never writes
 *   the order lifecycle (status / current_status_id / flow_id); there is
 *   no fulfillment→order edge except via the canonical writer (none exists).
 * - Shipment: maybeCompleteOrder moves the order to delivered ONLY for
 *   completed + paid orders whose every fulfillment is delivered — through
 *   the canonical writer (completed→delivered terminal absorption).
 * - Payment: covered by OrderStatusLifecycleTest (markCodAsPaid /
 *   markCashierPaid complete exclusively via changeOrderStatus).
 */
class SubsystemBindingTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->customer = User::create([
            'name' => 'Binding Customer',
            'email' => 'binding-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'WH-B-' . Str::random(4),
            'name' => 'Binding Main',
            'status' => 'active',
            'is_default' => false,
        ]);
    }

    private function stockedProduct(Warehouse $warehouse, int $qty = 10): Product
    {
        $product = Product::create([
            'name' => 'Binding P',
            'slug' => 'binding-p-' . Str::random(8),
            'sku' => 'SKU-B-' . Str::random(6),
            'price' => 100,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => $qty,
            'reserved_quantity' => 0,
        ]);
        $location = Location::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'B-' . Str::random(4),
            'barcode' => 'LOC-B-' . Str::random(6),
            'name' => 'Bin',
            'type' => 'picking',
            'status' => 'active',
            'priority' => 1,
        ]);
        ProductLocation::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $qty,
            'allocated_hint' => 0,
        ]);

        return $product;
    }

    private function paidOrder(Product $product, int $qty = 2, string $status = 'completed'): Order
    {
        // The model creation backstop assigns the local flow; non-pending
        // starts are arranged directly (fixture setup, not a transition).
        $order = Order::create([
            'user_id' => $this->customer->id,
            'name' => 'Binding Order',
            'user_phone' => '01000000000',
            'user_email' => $this->customer->email,
            'price' => 100 * $qty,
            'total_price' => 100 * $qty,
            'status' => $status,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'product_quantity' => $qty,
            'product_price' => 100,
            'product_total_price' => 100 * $qty,
        ]);

        return $order->refresh();
    }

    private function lifecycleSnapshot(Order $order): array
    {
        $fresh = $order->fresh();

        return [
            'status' => $fresh->status,
            'flow_id' => (int) $fresh->flow_id,
            'current_status_id' => (int) $fresh->current_status_id,
        ];
    }

    /** @test */
    public function fulfillment_cycle_never_mutates_order_lifecycle(): void
    {
        $warehouse = $this->warehouse();
        $product = $this->stockedProduct($warehouse);
        $order = $this->paidOrder($product);

        $before = $this->lifecycleSnapshot($order);

        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $warehouse->id, 'bind-' . Str::random(8));

        $transitions = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'] as $state) {
            $transitions->transition($fulfillment->refresh(), $state);
        }

        $this->assertSame('delivered', $fulfillment->refresh()->status);
        // Fulfillment ran its entire operational machine; the order
        // lifecycle must be byte-identical — no second lifecycle exists.
        $this->assertSame($before, $this->lifecycleSnapshot($order));
    }

    /** @test */
    public function shipment_completes_order_only_when_all_fulfillments_delivered(): void
    {
        $warehouse = $this->warehouse();
        $product = $this->stockedProduct($warehouse);
        $order = $this->paidOrder($product);

        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $warehouse->id, 'bind-ship-' . Str::random(8));

        // Open fulfillment: the order must stay completed.
        $this->assertFalse(app(ShipmentService::class)->maybeCompleteOrder($order->id));
        $this->assertSame('completed', $order->fresh()->status);

        $transitions = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'] as $state) {
            $transitions->transition($fulfillment->refresh(), $state);
        }

        // Completed + paid + every fulfillment delivered → delivered via #1.
        $this->assertTrue(app(ShipmentService::class)->maybeCompleteOrder($order->id));
        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertSame(
            (int) \App\Models\OrderFlow\OrderStatus::query()->where('code', 'delivered')->value('id'),
            (int) $fresh->current_status_id
        );
    }

    /** @test */
    public function shipment_never_completes_unpaid_or_unfinished_orders(): void
    {
        $warehouse = $this->warehouse();
        $product = $this->stockedProduct($warehouse);

        // Not completed: stays put even with delivered fulfillment behind it.
        $processing = $this->paidOrder($product, 2, 'processing');
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($processing, $warehouse->id, 'bind-neg-' . Str::random(8));
        app(FulfillmentTransition::class)->transition($fulfillment->refresh(), 'picking');

        $this->assertFalse(app(ShipmentService::class)->maybeCompleteOrder($processing->id));
        $this->assertSame('processing', $processing->fresh()->status);

        // Digital-only (no fulfillments at all): owned by the payment path.
        $digital = $this->paidOrder($product);
        $this->assertFalse(Fulfillment::where('order_id', $digital->id)->exists());
        $this->assertFalse(app(ShipmentService::class)->maybeCompleteOrder($digital->id));
        $this->assertSame('completed', $digital->fresh()->status);
    }
}

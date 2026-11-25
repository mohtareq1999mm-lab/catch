<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Round 12 (C-01/C-02): shipment cancellation is local to the affected
 * fulfillment; returned shipment rows are immutable history.
 */
class ShipmentCancellationBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private function seedPaidOrder(): array
    {
        $warehouse = Warehouse::create([
            'code' => 'WH-CXL', 'name' => 'Cancellation Boundary WH',
            'status' => 'active', 'is_default' => true,
        ]);

        $product = Product::create([
            'name' => 'Boundary product', 'slug' => 'cxl-boundary-' . uniqid(),
            'sku' => 'SKU-CXL-' . uniqid(), 'price' => 100,
            'product_type' => 'simple', 'status' => true,
            'stock_quantity' => 10, 'reserved_quantity' => 0, 'sold_quantity' => 2,
            'in_stock' => true,
        ]);

        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'CXL order',
            'user_email' => $user->email, 'user_phone' => '01000000000',
            'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'shipped',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => 'Boundary product',
            'product_sku' => $product->sku, 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return [$warehouse, $product, $order->refresh()];
    }

    private function makeFulfillment(Order $order, Warehouse $warehouse, string $number): Fulfillment
    {
        return Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'fulfillment_number' => $number,
            'status' => 'ready_to_ship',
        ]);
    }

    private function makeShipment(Order $order, Fulfillment $fulfillment, string $status = 'label_created'): Shipment
    {
        return Shipment::create([
            'order_id' => $order->id,
            'fulfillment_id' => $fulfillment->id,
            'courier' => 'Local',
            'status' => $status,
        ]);
    }

    private function normalizedAttributes($model): array
    {
        $attributes = $model->getAttributes();
        ksort($attributes);

        return $attributes;
    }

    public function test_cancelling_a_shipment_does_not_cancel_fulfillment_release_inventory_or_change_payment(): void
    {
        [$warehouse, $product, $order] = $this->seedPaidOrder();
        $fulfillment = $this->makeFulfillment($order, $warehouse, 'CXL-F1');
        $shipment = $this->makeShipment($order, $fulfillment);

        $fulfillmentBefore = $this->normalizedAttributes($fulfillment->fresh());
        $productBefore = $this->normalizedAttributes($product->fresh());
        $orderBefore = $order->only([
            'status', 'payment_status', 'fulfillment_status', 'inventory_state', 'inventory_restored_at',
        ]);

        $cancelled = app(ShipmentService::class)->cancelShipment($shipment->id, 'carrier could not pick up');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('carrier could not pick up', $cancelled->cancel_reason);

        // Fulfillment, inventory and payment/order lifecycle are untouched.
        $this->assertSame($fulfillmentBefore, $this->normalizedAttributes($fulfillment->fresh()));
        $this->assertSame($productBefore, $this->normalizedAttributes($product->fresh()));
        $this->assertSame($orderBefore, $order->fresh()->only(array_keys($orderBefore)));

        // Existing cancellation semantics remain: only the shipment mirror moves.
        $this->assertSame('cancelled', $order->fresh()->shipment_status);
    }

    public function test_cancellation_is_scoped_to_the_affected_fulfillment_only(): void
    {
        [$warehouse, , $order] = $this->seedPaidOrder();
        $affected = $this->makeFulfillment($order, $warehouse, 'CXL-F-A');
        $sibling = $this->makeFulfillment($order, $warehouse, 'CXL-F-B');
        $affectedShipment = $this->makeShipment($order, $affected);
        $siblingShipment = $this->makeShipment($order, $sibling, 'picked_up');

        $siblingBefore = $this->normalizedAttributes($sibling->fresh());
        $siblingShipmentBefore = $this->normalizedAttributes($siblingShipment->fresh());

        app(ShipmentService::class)->cancelShipment($affectedShipment->id, 'wrong label');

        $this->assertSame('cancelled', $affectedShipment->fresh()->status);
        $this->assertSame('ready_to_ship', $affected->fresh()->status);
        $this->assertSame($siblingBefore, $this->normalizedAttributes($sibling->fresh()));
        $this->assertSame($siblingShipmentBefore, $this->normalizedAttributes($siblingShipment->fresh()));
    }

    public function test_cancellation_creates_no_replacement_shipment_or_link(): void
    {
        [$warehouse, , $order] = $this->seedPaidOrder();
        $fulfillment = $this->makeFulfillment($order, $warehouse, 'CXL-F2');
        $shipment = $this->makeShipment($order, $fulfillment);

        app(ShipmentService::class)->cancelShipment($shipment->id, 'voided label');

        $this->assertSame(1, Shipment::where('fulfillment_id', $fulfillment->id)->count());
        $this->assertSame('ready_to_ship', $fulfillment->fresh()->status);
        $this->assertFalse(Schema::hasTable('shipment_replacements'));
        foreach (['replacement_shipment_id', 'replaces_shipment_id', 'replaced_by_shipment_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('shipments', $column));
        }
    }

    public function test_returned_shipment_row_is_immutable_and_cannot_be_deleted(): void
    {
        [$warehouse, , $order] = $this->seedPaidOrder();
        $fulfillment = $this->makeFulfillment($order, $warehouse, 'CXL-F3');
        $shipment = $this->makeShipment($order, $fulfillment, 'returned');
        $before = $this->normalizedAttributes($shipment->fresh());
        $expectedMessage = __('wms.shipment.returned_immutable', ['shipment' => $shipment->id]);

        try {
            $shipment->update(['notes' => 'rewritten']);
            $this->fail('returned shipment must not be rewritten');
        } catch (\RuntimeException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }

        try {
            app(ShipmentService::class)->update($shipment->id, ['notes' => 'rewritten']);
            $this->fail('service update must not rewrite a returned shipment');
        } catch (\RuntimeException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }

        try {
            $shipment->delete();
            $this->fail('returned shipment must not be deleted');
        } catch (\RuntimeException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }

        $this->assertSame($before, $this->normalizedAttributes($shipment->fresh()));
        $this->assertSame(1, Shipment::whereKey($shipment->id)->count());
    }
}

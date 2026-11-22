<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\DTOs\Shipping\CarrierShipment;
use App\Enums\Shipping\CarrierCapability;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Shipment\CarrierGatewayFactory;
use App\Services\Shipment\Contracts\CancelsCarrierShipments;
use App\Services\Shipment\Contracts\CreatesCarrierShipments;
use App\Services\Shipment\Providers\InternalDeliveryGateway;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\Support\Shipping\RecordingShipmentService;
use Tests\TestCase;

/**
 * Internal local delivery is a provider behind the #133 seam: it resolves from
 * deployment config, never loads external provider secrets, and every action
 * goes through ShipmentService instead of a second shipment lifecycle.
 */
class InternalDeliveryGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Canonical order events force the pusher connection; redirect that
        // connection to the log driver so the test never reaches the network.
        config(['broadcasting.connections.pusher.driver' => 'log']);

        $this->warehouse = Warehouse::create([
            'code' => 'WH-INT', 'name' => 'Internal', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'INT-01',
            'barcode' => 'LOC-INT-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'Internal product', 'slug' => 'p-int-' . uniqid(), 'sku' => 'SKU-INT-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    public function test_factory_resolves_internal_mode_without_external_credentials(): void
    {
        $factory = app(CarrierGatewayFactory::class);
        $status = $factory->status('internal');

        $this->assertSame('internal', $status['mode']);
        $this->assertTrue($status['enabled']);
        $this->assertContains(CarrierCapability::CREATE_SHIPMENT->value, $status['verified_capabilities']);
        $this->assertNotContains(CarrierCapability::WEBHOOK_VERIFICATION->value, $status['verified_capabilities']);

        $gateway = $factory->make('internal', capability: CarrierCapability::CREATE_SHIPMENT);

        $this->assertInstanceOf(InternalDeliveryGateway::class, $gateway);
        $this->assertInstanceOf(CreatesCarrierShipments::class, $gateway);
        $this->assertInstanceOf(CancelsCarrierShipments::class, $gateway);
        $this->assertSame('internal', $gateway->context()->providerCode);
        $this->assertSame('internal', $gateway->context()->mode);

        $source = (string) file_get_contents(__DIR__ . '/../../../config/shipping.php');
        $this->assertStringContainsString(InternalDeliveryGateway::class, $source);
        $this->assertStringContainsString("'mode' => 'internal'", $source);
        foreach ([
            'SHIPPING_INTERNAL_API_KEY',
            'SHIPPING_INTERNAL_API_SECRET',
            'SHIPPING_INTERNAL_WEBHOOK_SECRET',
        ] as $variable) {
            $this->assertStringNotContainsString($variable, $source);
        }

        $block = config('shipping.providers.internal.modes.internal');
        $this->assertIsArray($block);
        $this->assertArrayNotHasKey('api_key', $block);
        $this->assertArrayNotHasKey('api_secret', $block);
        $this->assertArrayNotHasKey('webhook_secret', $block);
        $this->assertArrayNotHasKey('secret', $block['webhook']);
    }

    public function test_create_delegates_to_canonical_service_and_creates_no_inbox_row(): void
    {
        $recorder = $this->bindRecordingService();
        $fulfillment = $this->makeReadyFulfillment('int-create');
        $gateway = $this->makeGateway();

        $result = $gateway->createShipment([
            'fulfillment_id' => $fulfillment->id,
            'idempotency_key' => 'int-create-key',
            'tracking_number' => 'INT-1',
        ]);

        $this->assertInstanceOf(CarrierShipment::class, $result);
        $this->assertSame('internal', $result->providerCode);
        $this->assertSame('internal', $result->mode);
        $this->assertSame(['createForFulfillment'], array_column($recorder->calls, 'method'));
        $this->assertSame($fulfillment->id, $recorder->calls[0]['args']['fulfillment_id']);

        $shipmentId = (int) $result->externalIds['shipment_id'];
        $shipment = Shipment::findOrFail($shipmentId);
        $this->assertSame('label_created', $shipment->status);
        $this->assertSame('INT-1', $shipment->tracking_number);
        $this->assertSame(1, Shipment::where('fulfillment_id', $fulfillment->id)->count());
        $this->assertNoExternalInboxRows();
    }

    public function test_cancel_delegates_to_canonical_service_without_a_parallel_lifecycle(): void
    {
        $recorder = $this->bindRecordingService();
        $fulfillment = $this->makeReadyFulfillment('int-cancel');
        $gateway = $this->makeGateway();
        $created = $gateway->createShipment(['fulfillment_id' => $fulfillment->id]);
        $recorder->calls = [];

        $cancelled = $gateway->cancelShipment((string) $created->externalIds['shipment_id']);

        $this->assertInstanceOf(CarrierShipment::class, $cancelled);
        $this->assertSame(['cancelShipment'], array_column($recorder->calls, 'method'));
        $this->assertSame('internal', $recorder->calls[0]['args']['cancel_source']);
        $this->assertSame(__('wms.shipment.internal_cancel_reason'), $recorder->calls[0]['args']['reason']);

        $shipment = Shipment::findOrFail((int) $created->externalIds['shipment_id']);
        $this->assertSame('cancelled', $shipment->status);
        $this->assertNotNull($shipment->cancelled_at);
        $this->assertSame('internal', $shipment->cancel_source);
        $this->assertSame(1, Shipment::where('fulfillment_id', $fulfillment->id)->count());
        $this->assertNoExternalInboxRows();
    }

    public function test_dispatch_and_delivery_delegate_to_canonical_service(): void
    {
        $recorder = $this->bindRecordingService();
        $fulfillment = $this->makeReadyFulfillment('int-deliver');
        $gateway = $this->makeGateway();
        $created = $gateway->createShipment(['fulfillment_id' => $fulfillment->id]);
        $shipmentId = (string) $created->externalIds['shipment_id'];
        $recorder->calls = [];

        $gateway->dispatchShipment($shipmentId);
        $this->assertSame('picked_up', Shipment::findOrFail((int) $shipmentId)->status);
        $this->assertSame(['dispatch'], array_column($recorder->calls, 'method'));

        $gateway->markShipmentDelivered($shipmentId);
        $this->assertSame('delivered', Shipment::findOrFail((int) $shipmentId)->status);
        $this->assertSame(['dispatch', 'markDelivered'], array_column($recorder->calls, 'method'));
        $this->assertNotNull(Shipment::findOrFail((int) $shipmentId)->shipped_at);
        $this->assertNotNull(Shipment::findOrFail((int) $shipmentId)->delivered_at);

        $this->assertSame(1, Shipment::where('fulfillment_id', $fulfillment->id)->count());
        $this->assertSame('delivered', $fulfillment->refresh()->status);
        $this->assertNoExternalInboxRows();
    }

    public function test_invalid_external_shipment_id_is_rejected_before_any_canonical_call(): void
    {
        $recorder = $this->bindRecordingService();
        $gateway = $this->makeGateway();

        try {
            $gateway->cancelShipment('not-a-shipment-id');
            $this->fail('An invalid external shipment id must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('shipment id', $exception->getMessage());
        }

        $this->assertSame([], $recorder->calls);
        $this->assertNoExternalInboxRows();
    }

    private function bindRecordingService(): RecordingShipmentService
    {
        $recorder = app(RecordingShipmentService::class);
        app()->instance(ShipmentService::class, $recorder);

        return $recorder;
    }

    private function makeGateway(): InternalDeliveryGateway
    {
        $gateway = app(CarrierGatewayFactory::class)->make('internal');
        $this->assertInstanceOf(InternalDeliveryGateway::class, $gateway);

        return $gateway;
    }

    private function makeReadyFulfillment(string $key): Fulfillment
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'Internal product',
            'product_sku' => 'SKU-INT-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouse->id, $key);
        $transitions = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship'] as $state) {
            $transitions->transition($fulfillment, $state);
        }

        return $fulfillment->refresh();
    }

    private function assertNoExternalInboxRows(): void
    {
        foreach (['payment_webhook_events', 'shipping_webhook_events'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), "No external inbox rows expected in {$table}.");
            }
        }
    }
}

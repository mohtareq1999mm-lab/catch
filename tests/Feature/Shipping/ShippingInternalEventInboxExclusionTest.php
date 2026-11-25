<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Shipment\CarrierGatewayFactory;
use App\Services\Shipment\Providers\InternalDeliveryGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

class ShippingInternalEventInboxExclusionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_160';

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.connections.pusher.driver' => 'log']);
    }

    public function test_internal_provider_webhook_is_rejected_and_creates_no_inbox_row(): void
    {
        config([
            'shipping.providers.internal.mode' => 'internal',
            'shipping.providers.internal.modes.internal.enabled' => true,
        ]);

        $payload = [
            'id' => 'evt_internal_160',
            'type' => 'shipment.status_changed',
            'status' => 'delivered',
            'occurred_at' => '2026-11-23T10:00:00Z',
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/api/v1/general/shipping/webhooks/internal',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type' => 'application/json',
                'X-Shipping-Signature' => 'v1=' . hash_hmac('sha256', $raw, self::SECRET),
            ]),
            $raw,
        );

        $response->assertStatus(503);
        $this->assertSame(0, ShippingWebhookEvent::count());
    }

    public function test_internal_delivery_actions_do_not_create_external_inbox_rows(): void
    {
        $warehouse = Warehouse::create([
            'code' => 'WH-INT-160', 'name' => 'Internal 160', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'INT-160-01',
            'barcode' => 'LOC-INT-160-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $product = Product::create([
            'name' => 'Internal product', 'slug' => 'p-int-160-' . Str::lower(Str::random(6)), 'sku' => 'SKU-INT-160',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);

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
            'product_id' => $product->id, 'product_name' => 'Internal product',
            'product_sku' => 'SKU-INT-160', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $warehouse->id, 'int-160-inbox');
        $transitions = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship'] as $state) {
            $transitions->transition($fulfillment, $state);
        }

        $gateway = app(CarrierGatewayFactory::class)->make('internal');
        $this->assertInstanceOf(InternalDeliveryGateway::class, $gateway);

        $created = $gateway->createShipment(['fulfillment_id' => $fulfillment->refresh()->id, 'idempotency_key' => 'int-160-create']);
        $shipmentId = (string) $created->externalIds['shipment_id'];

        $gateway->dispatchShipment($shipmentId, 'internal dispatch');
        $gateway->markShipmentDelivered($shipmentId, 'internal delivered');

        $shipment = Shipment::findOrFail((int) $shipmentId);
        $this->assertSame('delivered', $shipment->status);
        $this->assertSame('internal', $shipment->provider_code);
        $this->assertSame(0, ShippingWebhookEvent::count(), 'Internal delivery actions must not create external shipping webhook inbox rows.');
    }
}

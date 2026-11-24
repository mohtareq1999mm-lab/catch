<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ProviderEventExceptionType;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Shipment\CarrierGatewayFactory;
use App\Services\Shipment\ShippingProviderEventProcessor;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class StatusMapVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.default_provider' => 'test_carrier',
            'shipping.providers.test_carrier' => [
                'adapter' => \App\Services\Shipment\Providers\NullCarrierGateway::class,
                'mode' => 'sandbox',
                'account_code' => 'test-account',
                'service_level' => 'standard',
                'channels' => ['web'],
                'geographies' => ['EG'],
                'modes' => [
                    'sandbox' => [
                        'enabled' => true,
                        'credentials' => ['api_key' => 'sandbox-key'],
                        'status_map' => [
                            'version' => 'v9',
                            'statuses' => [
                                'label_created' => 'label_created',
                            ],
                        ],
                    ],
                    'production' => ['enabled' => false, 'credentials' => [], 'status_map' => ['version' => 'v9', 'statuses' => []]],
                ],
            ],
        ]);
    }

    private function createShipment(): Shipment
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Order',
            'user_email' => $user->email,
            'user_phone' => '01000000000',
            'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);

        return app(ShipmentService::class)->create(['order_id' => $order->id]);
    }

    private function inbox(Shipment $shipment, string $rawStatus): ShippingWebhookEvent
    {
        return ShippingWebhookEvent::create([
            'provider_code' => 'test_carrier',
            'account_code' => 'test-account',
            'mode' => 'sandbox',
            'event_key' => 'evt-' . Str::random(8),
            'shipment_id' => $shipment->id,
            'tracking_number' => $shipment->tracking_number,
            'raw_status' => $rawStatus,
            'signature_valid' => true,
            'payload_hash' => hash('sha256', $rawStatus),
            'redacted_payload' => ['status' => $rawStatus],
            'received_at' => now(),
            'processed_at' => null,
        ]);
    }

    public function test_unknown_raw_status_creates_exception_and_no_transition(): void
    {
        $shipment = $this->createShipment();
        $webhook = $this->inbox($shipment, 'brand_new_unknown_status');
        $timelineBefore = OrderTrackingEvent::where('order_id', $shipment->order_id)->count();

        $event = app(ShippingProviderEventProcessor::class)->process($webhook);

        $this->assertNull($event->mapped_status);
        $this->assertSame(ProviderEventExceptionType::UNKNOWN_STATUS->value, $event->exception_classification);
        $this->assertSame('v9', $event->mapping_version);
        $this->assertNull($event->applied_at);
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame('brand_new_unknown_status', $event->raw_status);
        $this->assertSame('brand_new_unknown_status', $webhook->fresh()->raw_status);
        $this->assertSame($timelineBefore, OrderTrackingEvent::where('order_id', $shipment->order_id)->count());
        $this->assertSame(0, OrderTrackingEvent::where('new_status', 'brand_new_unknown_status')->count());

        $exception = ShipmentException::where('provider_event_id', $event->id)->first();
        $this->assertNotNull($exception);
        $this->assertSame(ProviderEventExceptionType::UNKNOWN_STATUS->value, $exception->type);
    }

    public function test_mapping_version_is_preserved_on_event_and_shipment(): void
    {
        $shipment = $this->createShipment();
        $this->assertSame('v9', $shipment->mapping_version);

        $webhook = $this->inbox($shipment, 'label_created');
        $event = app(ShippingProviderEventProcessor::class)->process($webhook);

        $this->assertSame('v9', $event->mapping_version);
        $this->assertSame('label_created', $event->mapped_status);
        $this->assertSame('label_created', $event->raw_status);
        $this->assertSame('label_created', $webhook->fresh()->raw_status);
        $this->assertSame('v9', $shipment->fresh()->mapping_version);
    }
}

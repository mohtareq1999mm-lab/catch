<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Shipment\ShipmentService;
use App\Services\Shipment\ShippingProviderEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\Support\Shipping\RecordingShipmentService;
use Tests\TestCase;

/**
 * #151: raw provider evidence is processed separately from the canonical
 * order timeline. Only a known, unambiguous, versioned mapping may reach the
 * canonical shipment authority; every other outcome becomes an exception.
 */
class ShippingProviderEventProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.connections.pusher.driver' => 'log',
            'shipping.providers.test_carrier.mode' => 'sandbox',
            'shipping.providers.test_carrier.modes.sandbox.status_map' => [
                'version' => 7,
                'status_map_owner' => 'shipping-platform',
                'statuses' => [
                    'label_created' => ShipmentStatus::LABEL_CREATED->value,
                    'in_transit' => ShipmentStatus::IN_TRANSIT->value,
                    'lost' => ShipmentStatus::RETURNED->value,
                    'damaged' => ShipmentStatus::FAILED_DELIVERY->value,
                ],
            ],
        ]);
    }

    public function test_known_status_is_recorded_with_raw_status_and_mapping_version_then_transitions(): void
    {
        $shipment = $this->createShipment();
        $timelineBefore = DB::table('order_tracking_events')->count();
        $webhook = $this->createInboxRow($shipment, 'label_created', 'evt_known_151');
        $recorder = $this->bindRecordingService();

        $event = $this->processor()->process($webhook);

        $this->assertSame($webhook->id, $event->webhook_event_id);
        $this->assertSame('label_created', $event->raw_status);
        $this->assertSame('7', $event->mapping_version);
        $this->assertSame(ShipmentStatus::LABEL_CREATED->value, $event->mapped_status);
        $this->assertNull($event->exception_classification);
        $this->assertSame('shipping_webhook_event:'.$webhook->id, $event->payload_reference);
        $this->assertSame(
            hash('sha256', json_encode($webhook->redacted_payload, JSON_THROW_ON_ERROR)),
            $event->payload_hash,
        );
        $this->assertSame(ShipmentStatus::LABEL_CREATED->value, $shipment->fresh()->status);
        $this->assertSame([
            ['method' => 'updateStatus', 'args' => [
                'shipment_id' => $shipment->id,
                'status' => ShipmentStatus::LABEL_CREATED->value,
            ]],
        ], $recorder->calls);
        $this->assertSame($timelineBefore, DB::table('order_tracking_events')->count());
        $this->assertSame(0, ShipmentException::count());

        // Replaying the same accepted delivery never creates a second event
        // and never asks the authority to transition again.
        $again = $this->processor()->process($webhook->fresh());
        $this->assertSame($event->id, $again->id);
        $this->assertSame(1, ShippingProviderEvent::count());
        $this->assertCount(1, $recorder->calls);
    }

    public function test_unknown_status_creates_internal_exception_without_any_transition(): void
    {
        $shipment = $this->createShipment();
        $timelineBefore = DB::table('order_tracking_events')->count();
        $webhook = $this->createInboxRow($shipment, 'carrier_invents_a_new_state', 'evt_unknown_151');
        $recorder = $this->bindRecordingService();

        $event = $this->processor()->process($webhook);

        $this->assertSame('carrier_invents_a_new_state', $event->raw_status);
        $this->assertSame('7', $event->mapping_version);
        $this->assertNull($event->mapped_status);
        $this->assertSame(ProviderEventExceptionType::UNKNOWN_STATUS->value, $event->exception_classification);
        $this->assertNull($event->applied_at);

        $exception = ShipmentException::firstOrFail();
        $this->assertSame(ProviderEventExceptionType::UNKNOWN_STATUS->value, $exception->type);
        $this->assertSame('high', $exception->severity);
        $this->assertSame('open', $exception->status);
        $this->assertSame($event->id, $exception->provider_event_id);
        $this->assertSame($shipment->id, $exception->shipment_id);

        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame([], $recorder->calls);
        $this->assertSame($timelineBefore, DB::table('order_tracking_events')->count());
    }

    public function test_lost_and_damaged_are_never_mapped_to_canonical_statuses(): void
    {
        foreach (['lost', 'damaged'] as $rawStatus) {
            $shipment = $this->createShipment();
            $webhook = $this->createInboxRow($shipment, $rawStatus, 'evt_'.$rawStatus.'_151');
            $recorder = $this->bindRecordingService();

            $event = $this->processor()->process($webhook);

            $this->assertNull($event->mapped_status);
            $this->assertSame(ProviderEventExceptionType::LOST_OR_DAMAGED->value, $event->exception_classification);
            $this->assertSame('pending', $shipment->fresh()->status);
            $this->assertSame([], $recorder->calls);
            $this->assertSame(
                ProviderEventExceptionType::LOST_OR_DAMAGED->value,
                ShipmentException::where('provider_event_id', $event->id)->firstOrFail()->type,
            );
        }
    }

    public function test_unregistered_provider_fails_closed_without_transition(): void
    {
        $shipment = $this->createShipment();
        $webhook = $this->createInboxRow($shipment, 'in_transit', 'evt_unregistered_151', 'not_registered');
        $recorder = $this->bindRecordingService();

        $event = $this->processor()->process($webhook);

        $this->assertNull($event->mapped_status);
        $this->assertSame('', $event->mapping_version);
        $this->assertSame(ProviderEventExceptionType::UNREGISTERED_PROVIDER->value, $event->exception_classification);
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame([], $recorder->calls);
    }

    private function processor(): ShippingProviderEventProcessor
    {
        return app(ShippingProviderEventProcessor::class);
    }

    private function bindRecordingService(): RecordingShipmentService
    {
        $recorder = app(RecordingShipmentService::class);
        app()->instance(ShipmentService::class, $recorder);

        return $recorder;
    }

    private function createShipment(): Shipment
    {
        $user = User::create([
            'name' => 'Provider Event User',
            'email' => 'provider-event-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Provider Event Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);

        return Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    private function createInboxRow(
        Shipment $shipment,
        string $rawStatus,
        string $eventKey,
        string $providerCode = 'test_carrier',
    ): ShippingWebhookEvent {
        return ShippingWebhookEvent::create([
            'provider_code' => $providerCode,
            'mode' => 'sandbox',
            'account_code' => 'test-account',
            'webhook_version' => 'v1',
            'event_key' => $eventKey,
            'event_type' => 'shipment.status_changed',
            'provider_shipment_ref' => 'carrier-ref-151',
            'shipment_id' => $shipment->id,
            'redacted_payload' => [
                'status' => $rawStatus,
                'event_type' => 'shipment.status_changed',
            ],
            'raw_status' => $rawStatus,
            'status' => 'received',
            'received_at' => now(),
            'occurred_at' => now(),
        ]);
    }
}

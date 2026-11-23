<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
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

class ShipmentProviderEventProtectionTest extends TestCase
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
                    'picked_up' => ShipmentStatus::PICKED_UP->value,
                    'in_transit' => ShipmentStatus::IN_TRANSIT->value,
                    'delivered' => ShipmentStatus::DELIVERED->value,
                    'cancelled' => ShipmentStatus::CANCELLED->value,
                    'returned' => ShipmentStatus::RETURNED->value,
                ],
            ],
        ]);
    }

    public function test_terminal_reversal_attempt_is_retained_as_investigation_without_transition(): void
    {
        $shipment = $this->createShipment(ShipmentStatus::DELIVERED->value);
        $webhook = $this->createInboxRow($shipment, 'in_transit', 'evt_terminal_reversal_154');
        $recorder = $this->bindRecordingService();
        $timelineBefore = DB::table('order_tracking_events')->count();

        $event = $this->processor()->process($webhook);

        $this->assertSame(ShipmentStatus::DELIVERED->value, $shipment->fresh()->status);
        $this->assertNull($event->applied_at);
        $this->assertSame(ProviderEventExceptionType::TERMINAL_STATE_REVERSAL->value, $event->exception_classification);
        $this->assertSame([], $recorder->calls);
        $this->assertSame($timelineBefore, DB::table('order_tracking_events')->count());

        $exception = ShipmentException::where('provider_event_id', $event->id)->firstOrFail();
        $this->assertSame(ProviderEventExceptionType::TERMINAL_STATE_REVERSAL->value, $exception->type);
        $this->assertSame(ShipmentExceptionSeverity::HIGH->value, $exception->severity);
        $this->assertSame(ShipmentExceptionStatus::OPEN->value, $exception->status);
    }

    public function test_duplicate_provider_event_identity_is_stored_once_and_not_reprocessed(): void
    {
        $shipment = $this->createShipment(ShipmentStatus::PENDING->value);
        $webhook = $this->createInboxRow($shipment, 'label_created', 'evt_duplicate_154');

        $first = $this->processor()->process($webhook);
        $second = $this->processor()->process($webhook->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShippingProviderEvent::count());
        $this->assertSame(1, ShippingProviderEvent::whereNotNull('applied_at')->count());
        $this->assertSame(ShipmentStatus::LABEL_CREATED->value, $shipment->fresh()->status);
    }

    public function test_out_of_order_older_event_is_retained_without_reapplying_transition(): void
    {
        $shipment = $this->createShipment(ShipmentStatus::IN_TRANSIT->value);
        $older = $this->createInboxRow($shipment, 'picked_up', 'evt_older_154', 'test_carrier', 'sandbox', now()->subMinutes(5), now()->subMinutes(5));

        $event = $this->processor()->process($older);

        $this->assertSame(ShipmentStatus::IN_TRANSIT->value, $shipment->fresh()->status);
        $this->assertNull($event->applied_at);
        $this->assertSame(ProviderEventExceptionType::OUT_OF_ORDER_EVENT->value, $event->exception_classification);
        $this->assertSame(1, ShipmentException::where('provider_event_id', $event->id)->count());
        $this->assertSame(0, ShippingProviderEvent::whereNotNull('applied_at')->count());
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

    private function createShipment(string $status = ShipmentStatus::PENDING->value): Shipment
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
            'status' => $status,
            'provider_code' => 'test_carrier',
            'provider_mode' => 'sandbox',
        ]);
    }

    private function createInboxRow(
        Shipment $shipment,
        string $rawStatus,
        string $eventKey,
        string $providerCode = 'test_carrier',
        string $mode = 'sandbox',
        $occurredAt = null,
        $receivedAt = null,
    ): ShippingWebhookEvent {
        return ShippingWebhookEvent::create([
            'provider_code' => $providerCode,
            'mode' => $mode,
            'account_code' => 'test-account',
            'webhook_version' => 'v1',
            'event_key' => $eventKey,
            'event_type' => 'shipment.status_changed',
            'provider_shipment_ref' => 'carrier-ref-154',
            'shipment_id' => $shipment->id,
            'redacted_payload' => [
                'status' => $rawStatus,
                'event_type' => 'shipment.status_changed',
            ],
            'raw_status' => $rawStatus,
            'status' => 'received',
            'received_at' => $receivedAt ?? now(),
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}

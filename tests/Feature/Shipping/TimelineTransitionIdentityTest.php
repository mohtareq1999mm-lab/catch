<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentNotification;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Shipment\ShipmentNotificationRecorder;
use App\Services\Shipment\ShipmentService;
use App\Services\Shipment\ShippingProviderEventProcessor;
use App\Services\Shipment\ShippingWebhookIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class TimelineTransitionIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_event_persists_shipment_link_and_existing_rows_remain_valid_with_null(): void
    {
        [$order, $shipment] = $this->fixture();

        // Existing/legacy row shape with no shipment link must remain valid.
        $legacyId = DB::table('order_tracking_events')->insertGetId([
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => 'system',
            'old_status' => 'pending',
            'new_status' => 'label_created',
            'customer_visible' => true,
            'customer_label_key' => 'tracking.shipment.label_created',
            'source' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacy = OrderTrackingEvent::query()->findOrFail($legacyId);
        $this->assertNull($legacy->shipment_id);

        $shipment->forceFill(['status' => 'label_created'])->save();
        app(ShipmentService::class)->updateStatus($shipment->id, 'picked_up');

        $timeline = OrderTrackingEvent::query()
            ->where('order_id', $order->id)
            ->where('new_status', 'picked_up')
            ->firstOrFail();

        $this->assertSame($shipment->id, (int) $timeline->shipment_id);
    }

    public function test_repeated_statuses_produce_separate_notifications_keyed_to_timeline_event(): void
    {
        [$order, $shipment] = $this->fixture();
        $shipment->forceFill(['status' => 'label_created'])->save();

        $service = app(ShipmentService::class);
        $service->updateStatus($shipment->id, 'picked_up');
        $service->updateStatus($shipment->id, 'in_transit');
        $service->updateStatus($shipment->id, 'out_for_delivery');
        $service->updateStatus($shipment->id, 'failed_delivery');
        $service->updateStatus($shipment->id, 'out_for_delivery'); // repeated status, new transition

        $events = OrderTrackingEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'shipment.status_changed')
            ->orderBy('id')
            ->get();

        $this->assertCount(5, $events);
        $this->assertSame(
            ['picked_up', 'in_transit', 'out_for_delivery', 'failed_delivery', 'out_for_delivery'],
            $events->pluck('new_status')->all()
        );
        $this->assertCount(5, $events->pluck('id')->unique());

        // 3 channels per timeline event for registered user
        $this->assertSame(15, ShipmentNotification::query()->count());
        foreach ($events as $timeline) {
            $this->assertSame(3, ShipmentNotification::query()->where('timeline_event_id', $timeline->id)->count());
        }
    }

    public function test_duplicate_webhook_delivery_does_not_create_duplicate_notifications(): void
    {
        [$order, $shipment] = $this->fixture();

        $timelineEventId = DB::table('order_tracking_events')->insertGetId([
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => 'system',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'customer_visible' => true,
            'customer_label_key' => 'tracking.shipment.in_transit',
            'source' => 'webhook',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new ShipmentStatusChanged(
            order: $order,
            oldStatus: 'pending',
            newStatus: 'in_transit',
            timelineEventId: $timelineEventId,
            shipmentId: $shipment->id,
            orderId: $order->id,
            source: 'webhook',
        );

        $recorder = app(ShipmentNotificationRecorder::class);
        $recorder->record($event);
        $recorder->record($event); // duplicate delivery/replay

        $this->assertSame(3, ShipmentNotification::query()->where('timeline_event_id', $timelineEventId)->count());
        $this->assertSame(
            1,
            ShipmentNotification::query()
                ->where('timeline_event_id', $timelineEventId)
                ->where('channel', 'email')
                ->where('recipient_type', 'user')
                ->where('recipient_id', (string) $order->user_id)
                ->count()
        );
    }

    public function test_notification_dedupe_is_enforced_at_database_boundary(): void
    {
        [$order, $shipment] = $this->fixture();
        $timelineEventId = DB::table('order_tracking_events')->insertGetId([
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => 'system',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'customer_visible' => true,
            'customer_label_key' => 'tracking.shipment.in_transit',
            'source' => 'webhook',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ShipmentNotification::query()->create([
            'shipment_id' => $shipment->id,
            'timeline_event_id' => $timelineEventId,
            'channel' => 'email',
            'recipient_type' => 'user',
            'recipient_id' => (string) $order->user_id,
            'status' => 'pending',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ShipmentNotification::query()->create([
            'shipment_id' => $shipment->id,
            'timeline_event_id' => $timelineEventId,
            'channel' => 'email',
            'recipient_type' => 'user',
            'recipient_id' => (string) $order->user_id,
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_webhook_ingress_does_not_create_duplicate_notifications(): void
    {
        config([
            'broadcasting.connections.pusher.driver' => 'log',
            'shipping.providers.null.mode' => 'sandbox',
            'shipping.providers.null.modes.sandbox.enabled' => true,
            'shipping.providers.null.modes.sandbox.webhook.secret' => 'whsec_157',
            'shipping.providers.null.modes.sandbox.webhook.version' => 'v1',
            'shipping.providers.null.modes.sandbox.status_map' => [
                'version' => 157,
                'status_map_owner' => 'shipping-platform',
                'statuses' => [
                    'label_created' => ShipmentStatus::LABEL_CREATED->value,
                ],
            ],
        ]);

        [$order, $shipment] = $this->fixture();
        $shipment->forceFill([
            'provider_code' => 'null',
            'provider_mode' => 'sandbox',
        ])->save();

        $payload = [
            'id' => 'evt_157_duplicate',
            'event_type' => 'shipment.status_changed',
            'status' => 'label_created',
            'occurred_at' => '2026-11-24T10:00:00Z',
            'metadata' => ['shipment_uuid' => $shipment->uuid],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['x-shipping-signature' => 'v1=' . hash_hmac('sha256', $raw, 'whsec_157')];

        $ingest = app(ShippingWebhookIngestService::class);
        $first = $ingest->ingest('null', '127.0.0.1', $raw, $headers);
        $second = $ingest->ingest('null', '127.0.0.1', $raw, $headers);

        $this->assertTrue($first->outcome->success());
        $this->assertSame(\App\Enums\Shipping\ShippingWebhookIngestOutcome::DUPLICATE, $second->outcome);

        $webhook = ShippingWebhookEvent::query()->firstOrFail();
        $processor = app(ShippingProviderEventProcessor::class);
        $processor->process($webhook);
        $processor->process($webhook); // replay processing of same accepted inbox row

        $timeline = OrderTrackingEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'shipment.status_changed')
            ->where('new_status', ShipmentStatus::LABEL_CREATED->value)
            ->firstOrFail();

        $this->assertSame(1, OrderTrackingEvent::query()->where('order_id', $order->id)->where('new_status', ShipmentStatus::LABEL_CREATED->value)->count());
        $this->assertSame(3, ShipmentNotification::query()->where('timeline_event_id', $timeline->id)->count());
        $this->assertSame(ShipmentStatus::LABEL_CREATED->value, $shipment->fresh()->status);
    }

    /** @return array{0: Order, 1: Shipment} */
    private function fixture(): array
    {
        $user = User::create([
            'name' => 'Timeline Identity User',
            'email' => 'timeline-identity-' . Str::lower(Str::random(8)) . '@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Timeline Identity Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'shipment_status' => 'pending',
            'tracking_number' => 'TRK-' . Str::upper(Str::random(8)),
            'courier_name' => 'DHL',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'tracking_number' => $order->tracking_number,
            'courier' => 'DHL',
        ]);

        return [$order, $shipment];
    }
}

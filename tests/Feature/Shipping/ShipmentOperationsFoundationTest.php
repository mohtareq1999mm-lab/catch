<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\DeliveryRepresentative;
use App\Models\Shipping\ShipmentAssignment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Models\Shipping\ShipmentNotification;
use App\Models\Shipping\ShipmentPod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Tests\TestCase;

class ShipmentOperationsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_active_assignment_per_shipment_is_database_enforced(): void
    {
        $shipment = $this->createShipment();
        $repA = DeliveryRepresentative::create(['name' => 'Rep A', 'phone' => '100', 'is_active' => true]);
        $repB = DeliveryRepresentative::create(['name' => 'Rep B', 'phone' => '200', 'is_active' => true]);

        ShipmentAssignment::create([
            'shipment_id' => $shipment->id,
            'delivery_representative_id' => $repA->id,
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        ShipmentAssignment::create([
            'shipment_id' => $shipment->id,
            'delivery_representative_id' => $repB->id,
            'status' => 'active',
            'assigned_at' => now(),
        ]);
    }

    public function test_assignment_history_allows_non_active_rows_for_same_shipment(): void
    {
        $shipment = $this->createShipment();
        $repA = DeliveryRepresentative::create(['name' => 'Rep A', 'phone' => '100', 'is_active' => true]);
        $repB = DeliveryRepresentative::create(['name' => 'Rep B', 'phone' => '200', 'is_active' => true]);

        ShipmentAssignment::create([
            'shipment_id' => $shipment->id,
            'delivery_representative_id' => $repA->id,
            'status' => 'inactive',
            'assigned_at' => now()->subHour(),
            'unassigned_at' => now()->subMinutes(30),
        ]);

        $active = ShipmentAssignment::create([
            'shipment_id' => $shipment->id,
            'delivery_representative_id' => $repB->id,
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        $this->assertDatabaseHas('shipment_assignments', ['id' => $active->id, 'status' => 'active']);
        $this->assertSame(2, ShipmentAssignment::where('shipment_id', $shipment->id)->count());
    }

    public function test_only_one_successful_pod_per_shipment_is_database_enforced(): void
    {
        $shipment = $this->createShipment();

        ShipmentPod::create([
            'shipment_id' => $shipment->id,
            'status' => 'successful',
            'recipient_name' => 'Recipient',
            'captured_at' => now(),
            'file_reference' => 'pods/pod-1.jpg',
        ]);

        $this->expectException(QueryException::class);

        ShipmentPod::create([
            'shipment_id' => $shipment->id,
            'status' => 'successful',
            'recipient_name' => 'Recipient 2',
            'captured_at' => now(),
            'file_reference' => 'pods/pod-2.jpg',
        ]);
    }

    public function test_failed_pod_does_not_block_successful_pod(): void
    {
        $shipment = $this->createShipment();

        ShipmentPod::create([
            'shipment_id' => $shipment->id,
            'status' => 'failed',
            'recipient_name' => 'Recipient',
            'captured_at' => now()->subMinute(),
            'file_reference' => 'pods/failed.jpg',
        ]);

        $ok = ShipmentPod::create([
            'shipment_id' => $shipment->id,
            'status' => 'successful',
            'recipient_name' => 'Recipient',
            'captured_at' => now(),
            'file_reference' => 'pods/success.jpg',
        ]);

        $this->assertDatabaseHas('shipment_pods', ['id' => $ok->id, 'status' => 'successful']);
    }

    public function test_delivery_attempt_number_is_unique_per_shipment(): void
    {
        $shipment = $this->createShipment();

        ShipmentDeliveryAttempt::create([
            'shipment_id' => $shipment->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'attempted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        ShipmentDeliveryAttempt::create([
            'shipment_id' => $shipment->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'attempted_at' => now(),
        ]);
    }

    public function test_shipment_notifications_are_separate_from_legacy_order_notifications(): void
    {
        $this->assertTrue(Schema::hasTable('shipment_notifications'));
        $this->assertTrue(Schema::hasTable('order_notifications'));
        $this->assertNotSame(
            Schema::getColumnListing('order_notifications'),
            Schema::getColumnListing('shipment_notifications')
        );
    }

    public function test_shipment_notification_dedupes_by_timeline_event_channel_and_recipient(): void
    {
        $shipment = $this->createShipment();
        $timelineEventId = DB::table('order_tracking_events')->insertGetId([
            'order_id' => $shipment->order_id,
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => 'system',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'customer_visible' => true,
            'customer_label_key' => 'tracking.shipment.in_transit',
            'source' => 'direct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ShipmentNotification::create([
            'shipment_id' => $shipment->id,
            'timeline_event_id' => $timelineEventId,
            'channel' => 'sms',
            'recipient_type' => 'user',
            'recipient_id' => '42',
            'status' => 'pending',
        ]);

        $this->expectException(QueryException::class);

        ShipmentNotification::create([
            'shipment_id' => $shipment->id,
            'timeline_event_id' => $timelineEventId,
            'channel' => 'sms',
            'recipient_type' => 'user',
            'recipient_id' => '42',
            'status' => 'pending',
        ]);
    }

    public function test_shipment_notification_allows_same_timeline_event_for_different_recipient(): void
    {
        $shipment = $this->createShipment();
        $timelineEventId = DB::table('order_tracking_events')->insertGetId([
            'order_id' => $shipment->order_id,
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => 'system',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'customer_visible' => true,
            'customer_label_key' => 'tracking.shipment.in_transit',
            'source' => 'direct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ShipmentNotification::create([
            'shipment_id' => $shipment->id,
            'timeline_event_id' => $timelineEventId,
            'channel' => 'sms',
            'recipient_type' => 'user',
            'recipient_id' => '42',
            'status' => 'pending',
        ]);

        $other = ShipmentNotification::create([
            'shipment_id' => $shipment->id,
            'timeline_event_id' => $timelineEventId,
            'channel' => 'sms',
            'recipient_type' => 'user',
            'recipient_id' => '43',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('shipment_notifications', ['id' => $other->id, 'recipient_id' => '43']);
    }

    public function test_shipment_status_changed_records_deduped_shipment_notifications_by_timeline_event(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Notify User',
            'email' => 'notify-user@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-' . Str::upper(Str::random(8)),
            'user_id' => $user->id,
        ]);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);

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
            'source' => 'direct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new \App\Events\Shipment\ShipmentStatusChanged(
            order: $order,
            oldStatus: 'pending',
            newStatus: 'in_transit',
            timelineEventId: $timelineEventId,
            shipmentId: $shipment->id,
            orderId: $order->id,
            source: 'direct',
        );

        app(\App\Services\Shipment\ShipmentNotificationRecorder::class)->record($event);
        app(\App\Services\Shipment\ShipmentNotificationRecorder::class)->record($event);

        $this->assertSame(3, ShipmentNotification::query()->where('timeline_event_id', $timelineEventId)->count());
        $this->assertSame(0, DB::table('order_notifications')->count());
    }

    public function test_legacy_order_notification_behavior_is_unchanged(): void
    {
        $shipment = $this->createShipment();

        DB::table('users')->insertOrIgnore(['id'=>1,'name'=>'U','email'=>'u1@example.test','password'=>'x','created_at'=>now(),'updated_at'=>now()]);
        DB::table('order_notifications')->insert([
            'order_id' => $shipment->order_id,
            'user_id' => 1,
            'event_type' => 'order_confirmed',
            'channel' => 'email',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('order_notifications', [
            'order_id' => $shipment->order_id,
            'event_type' => 'order_confirmed',
            'channel' => 'email',
        ]);

        $this->assertSame(0, DB::table('shipment_notifications')->count());
    }

    private function createShipment(): Shipment
    {
        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-' . Str::upper(Str::random(8)),
        ]);

        return Shipment::query()->create([
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }
}

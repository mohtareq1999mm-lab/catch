<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\GuestIdentity;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentNotification;
use App\Services\Shipment\ShipmentNotificationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Tests\TestCase;

class GuestShipmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        Config::set('services.shipment_notifications.recipient_secret', 'test-recipient-secret');
    }

    public function test_guest_order_records_pending_email_and_sms_intents_for_approved_milestones(): void
    {
        $order = $this->createGuestOrder();
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);

        foreach (['picked_up', 'out_for_delivery', 'delivered', 'failed_delivery', 'delayed'] as $status) {
            $timelineEventId = $this->timelineEvent($order->id, 'pending', $status);
            $event = $this->event($order, $shipment, $timelineEventId, 'pending', $status);

            app(ShipmentNotificationRecorder::class)->record($event);
        }

        $this->assertSame(10, ShipmentNotification::query()->count());
        $this->assertSame(5, ShipmentNotification::query()->where('channel', 'email')->count());
        $this->assertSame(5, ShipmentNotification::query()->where('channel', 'sms')->count());
        $this->assertSame(0, ShipmentNotification::query()->where('channel', 'push')->count());
        $this->assertTrue(ShipmentNotification::query()->where('recipient_type', 'guest_contact')->exists());
        $this->assertTrue(ShipmentNotification::query()->where('status', 'pending')->count() === 10);
    }

    public function test_missing_guest_contact_produces_skipped_record_with_reason(): void
    {
        $order = $this->createGuestOrder(['user_email' => null, 'user_phone' => '']);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineEventId = $this->timelineEvent($order->id, 'pending', 'picked_up');
        $event = $this->event($order, $shipment, $timelineEventId, 'pending', 'picked_up');

        app(ShipmentNotificationRecorder::class)->record($event);

        $rows = ShipmentNotification::query()->orderBy('channel')->get();
        $this->assertSame(2, $rows->count());
        $this->assertTrue($rows->every(fn ($r) => $r->status === 'skipped'));
        $this->assertSame(['missing_email', 'missing_phone'], $rows->pluck('failure_reason')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->recipient_contact_encrypted === null));
    }

    public function test_invalid_guest_contact_produces_skipped_record_with_reason(): void
    {
        $order = $this->createGuestOrder(['user_email' => 'not-an-email', 'user_phone' => 'abc']);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineEventId = $this->timelineEvent($order->id, 'pending', 'picked_up');
        $event = $this->event($order, $shipment, $timelineEventId, 'pending', 'picked_up');

        app(ShipmentNotificationRecorder::class)->record($event);

        $rows = ShipmentNotification::query()->orderBy('channel')->get();
        $this->assertSame(2, $rows->count());
        $this->assertTrue($rows->every(fn ($r) => $r->status === 'skipped'));
        $this->assertSame(['invalid_email', 'invalid_phone'], $rows->pluck('failure_reason')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->recipient_contact_encrypted === null));
    }

    public function test_guest_recipient_fingerprint_is_keyed_and_not_reversible(): void
    {
        $order = $this->createGuestOrder(['user_email' => 'guest@example.test', 'user_phone' => '']);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineEventId = $this->timelineEvent($order->id, 'pending', 'picked_up');
        $event = $this->event($order, $shipment, $timelineEventId, 'pending', 'picked_up');

        $recorder = app(ShipmentNotificationRecorder::class);
        $recorder->record($event);

        // Same order, timeline event and contact: only the secret changes.
        Config::set('services.shipment_notifications.recipient_secret', 'different-secret');
        $recorder->record($event);

        $rows = ShipmentNotification::query()
            ->where('timeline_event_id', $timelineEventId)
            ->where('channel', 'email')
            ->orderBy('id')
            ->get();

        $this->assertSame(2, $rows->count(), 'Changing only the secret must yield a distinct fingerprint row');
        foreach ($rows as $row) {
            $this->assertNotSame('guest@example.test', $row->recipient_id);
            $this->assertStringNotContainsString('guest@example.test', (string) $row->recipient_id);
            $this->assertSame(64, strlen((string) $row->recipient_id));
        }
        $this->assertNotSame($rows[0]->recipient_id, $rows[1]->recipient_id);
    }

    public function test_plaintext_contacts_never_appear_in_notification_payload_or_failure_reason(): void
    {
        $email = 'secret-guest@example.test';
        $phone = '+201001112233';
        $order = $this->createGuestOrder(['user_email' => $email, 'user_phone' => $phone]);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineEventId = $this->timelineEvent($order->id, 'pending', 'picked_up');
        $event = $this->event($order, $shipment, $timelineEventId, 'pending', 'picked_up');

        app(ShipmentNotificationRecorder::class)->record($event);

        $rawRows = DB::table('shipment_notifications')->orderBy('channel')->get();
        $this->assertSame(2, $rawRows->count());
        foreach ($rawRows as $rawRow) {
            $serializedRaw = json_encode((array) $rawRow);
            $this->assertStringNotContainsString($email, (string) $serializedRaw);
            $this->assertStringNotContainsString($phone, (string) $serializedRaw);
            $this->assertSame('pending', $rawRow->status);
            $this->assertNotNull($rawRow->recipient_contact_encrypted);
            $this->assertNotSame($email, $rawRow->recipient_contact_encrypted);
            $this->assertNotSame($phone, $rawRow->recipient_contact_encrypted);

            $decrypted = \Illuminate\Support\Facades\Crypt::decryptString((string) $rawRow->recipient_contact_encrypted);
            $this->assertTrue(in_array($decrypted, [$email, $phone], true));
        }

        foreach (ShipmentNotification::query()->get() as $row) {
            $serialized = json_encode($row->toArray());
            $this->assertStringNotContainsString($email, (string) $serialized);
            $this->assertStringNotContainsString($phone, (string) $serialized);
            $this->assertStringNotContainsString('secret-guest', (string) $serialized);
            $this->assertStringNotContainsString('recipient_contact_encrypted', (string) $serialized);
            $this->assertIsArray($row->payload);
            $this->assertSame($order->id, $row->payload['order_id']);
            $this->assertNull($row->failure_reason);
        }
    }

    public function test_guest_non_approved_milestone_records_no_guest_rows_and_leaves_legacy_notifications_unchanged(): void
    {
        $order = $this->createGuestOrder();
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $legacyBefore = DB::table('order_notifications')->count();
        $timelineEventId = $this->timelineEvent($order->id, 'pending', 'in_transit');
        $event = $this->event($order, $shipment, $timelineEventId, 'pending', 'in_transit');

        app(ShipmentNotificationRecorder::class)->record($event);

        $this->assertSame(0, ShipmentNotification::query()->count());
        $this->assertSame($legacyBefore, DB::table('order_notifications')->count());
    }

    public function test_missing_recipient_secret_fails_closed_in_non_local_env(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        Config::set('services.shipment_notifications.recipient_secret', '');

        $order = $this->createGuestOrder();
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineEventId = $this->timelineEvent($order->id, 'pending', 'picked_up');
        $event = $this->event($order, $shipment, $timelineEventId, 'pending', 'picked_up');

        app(ShipmentNotificationRecorder::class)->record($event);

        $rows = ShipmentNotification::query()->orderBy('channel')->get();
        $this->assertSame(2, $rows->count());
        $this->assertTrue($rows->every(fn ($r) => $r->status === 'skipped'));
        $this->assertSame(['missing_recipient_secret', 'missing_recipient_secret'], $rows->pluck('failure_reason')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->recipient_contact_encrypted === null));
        $serialized = json_encode($rows->toArray());
        $this->assertStringNotContainsString('guest@example.test', (string) $serialized);
        $this->assertStringNotContainsString('+201001112233', (string) $serialized);
    }

    public function test_missing_recipient_secret_fails_closed_without_app_key_fallback(): void
    {
        Config::set('app.env', 'local');
        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        Config::set('services.shipment_notifications.recipient_secret', '');

        $order = $this->createGuestOrder();
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineEventId = $this->timelineEvent($order->id, 'pending', 'picked_up');
        $event = $this->event($order, $shipment, $timelineEventId, 'pending', 'picked_up');

        app(ShipmentNotificationRecorder::class)->record($event);

        $rows = ShipmentNotification::query()->orderBy('channel')->get();
        $this->assertSame(2, $rows->count());
        $this->assertTrue($rows->every(fn ($r) => $r->status === 'skipped'));
        $this->assertSame(['missing_recipient_secret', 'missing_recipient_secret'], $rows->pluck('failure_reason')->all());
    }

    public function test_skipped_guest_fingerprints_do_not_collide_across_timeline_events(): void
    {
        $order = $this->createGuestOrder(['user_email' => null, 'user_phone' => '']);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $firstTimeline = $this->timelineEvent($order->id, 'pending', 'picked_up');
        $secondTimeline = $this->timelineEvent($order->id, 'picked_up', 'out_for_delivery');

        app(ShipmentNotificationRecorder::class)->record($this->event($order, $shipment, $firstTimeline, 'pending', 'picked_up'));
        app(ShipmentNotificationRecorder::class)->record($this->event($order, $shipment, $secondTimeline, 'picked_up', 'out_for_delivery'));

        $this->assertSame(4, ShipmentNotification::query()->count());
        $this->assertSame(2, ShipmentNotification::query()->where('timeline_event_id', $firstTimeline)->count());
        $this->assertSame(2, ShipmentNotification::query()->where('timeline_event_id', $secondTimeline)->count());
        $firstIds = ShipmentNotification::query()->where('timeline_event_id', $firstTimeline)->pluck('recipient_id')->sort()->values()->all();
        $secondIds = ShipmentNotification::query()->where('timeline_event_id', $secondTimeline)->pluck('recipient_id')->sort()->values()->all();
        $this->assertNotSame($firstIds, $secondIds);
    }

    public function test_legacy_registered_user_notification_behavior_is_unchanged(): void
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
        $timelineEventId = $this->timelineEvent($order->id, 'pending', 'in_transit');
        $event = $this->event($order, $shipment, $timelineEventId, 'pending', 'in_transit');

        app(ShipmentNotificationRecorder::class)->record($event);

        $this->assertSame(3, ShipmentNotification::query()->where('timeline_event_id', $timelineEventId)->count());
        $this->assertTrue(ShipmentNotification::query()->where('recipient_type', 'user')->where('recipient_id', (string) $user->id)->exists());
        $this->assertSame(0, DB::table('order_notifications')->count());
    }

    private function createGuestOrder(array $overrides = []): Order
    {
        $guest = GuestIdentity::query()->create([
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDay(),
        ]);

        $defaults = [
            'tracking_number' => 'TRK-' . Str::upper(Str::random(8)),
            'guest_id' => $guest->id,
            'user_id' => null,
            'user_email' => 'guest@example.test',
            'user_phone' => '+201001112233',
        ];

        return Order::query()->forceCreate(array_merge($defaults, $overrides));
    }

    private function timelineEvent(int $orderId, string $old, string $new): int
    {
        return DB::table('order_tracking_events')->insertGetId([
            'order_id' => $orderId,
            'event_type' => 'shipment.status_changed',
            'old_status' => $old,
            'new_status' => $new,
            'source' => 'direct',
            'actor_type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function event(Order $order, Shipment $shipment, int $timelineEventId, string $old, string $new): ShipmentStatusChanged
    {
        return new ShipmentStatusChanged(
            order: $order,
            oldStatus: $old,
            newStatus: $new,
            timelineEventId: $timelineEventId,
            shipmentId: $shipment->id,
            orderId: $order->id,
            source: 'direct',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\NotificationIdentityConflict;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientFingerprint;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentNotification;
use App\Services\Notification\RecipientIdentityService;
use App\Services\Shipment\ShipmentNotificationRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class StableRecipientIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_links_every_channel_to_one_stable_recipient(): void
    {
        $user = $this->activeUser('stable@example.test');
        $event = $this->event($user, 'in_transit');

        $this->recorder()->record($event);

        $rows = ShipmentNotification::query()
            ->where('timeline_event_id', $event->timelineEventId)
            ->get();

        $this->assertCount(3, $rows);
        $recipientIds = $rows->pluck('notification_recipient_id')->unique()->values();
        $this->assertCount(1, $recipientIds);
        $this->assertNotNull($recipientIds->first());

        $recipient = NotificationRecipient::query()->findOrFail($recipientIds->first());
        $this->assertSame('user', $recipient->recipient_type);
        $this->assertSame('user:' . $user->id, $recipient->recipient_key);
        $this->assertSame($user->id, $recipient->user_id);
    }

    public function test_replaying_the_same_transition_stays_deduped_on_the_stable_recipient(): void
    {
        $user = $this->activeUser('replay@example.test');
        $event = $this->event($user, 'out_for_delivery');

        $this->recorder()->record($event);
        $this->recorder()->record($event);

        $this->assertSame(
            3,
            ShipmentNotification::query()->where('timeline_event_id', $event->timelineEventId)->count()
        );
        $this->assertSame(1, NotificationRecipient::query()->count());
    }

    public function test_fingerprint_rotation_keeps_the_same_stable_recipient(): void
    {
        $user = $this->activeUser('rotation@example.test');
        $identities = app(RecipientIdentityService::class);

        $first = $this->event($user, 'in_transit');
        $this->recorder()->record($first);

        $recipient = NotificationRecipient::query()
            ->where('recipient_type', 'user')
            ->where('recipient_key', 'user:' . $user->id)
            ->firstOrFail();
        $recipientId = (int) $recipient->id;

        $identities->rotateFingerprint($recipient, 'v1', str_repeat('a', 64));
        $identities->rotateFingerprint($recipient, 'v2', str_repeat('b', 64));

        $second = $this->event($user, 'delivered');
        $this->recorder()->record($second);

        $linked = ShipmentNotification::query()
            ->whereIn('timeline_event_id', [$first->timelineEventId, $second->timelineEventId])
            ->pluck('notification_recipient_id')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([$recipientId], $linked);
        $this->assertSame(1, NotificationRecipient::query()->count());
        $this->assertSame(
            ['v1', 'v2'],
            NotificationRecipientFingerprint::query()
                ->where('recipient_id', $recipientId)
                ->orderBy('key_version')
                ->pluck('key_version')
                ->all()
        );
    }

    public function test_stable_recipient_uniqueness_rejects_a_duplicate_channel_row_with_a_different_legacy_recipient(): void
    {
        $user = $this->activeUser('unique@example.test');
        $event = $this->event($user, 'in_transit');
        $this->recorder()->record($event);

        $emailRow = ShipmentNotification::query()
            ->where('timeline_event_id', $event->timelineEventId)
            ->where('channel', 'email')
            ->firstOrFail();

        $this->expectException(QueryException::class);

        ShipmentNotification::query()->create([
            'shipment_id' => $event->shipmentId,
            'timeline_event_id' => $event->timelineEventId,
            'channel' => 'email',
            'recipient_type' => 'user',
            'recipient_id' => '999999',
            'notification_recipient_id' => $emailRow->notification_recipient_id,
            'status' => 'pending',
        ]);
    }

    public function test_backfill_links_legacy_user_rows_and_leaves_guest_rows_unlinked(): void
    {
        $user = $this->activeUser('backfill@example.test');
        $order = $this->order($user);
        $timelineId = $this->timeline($order);

        $legacyUserId = DB::table('shipment_notifications')->insertGetId([
            'timeline_event_id' => $timelineId,
            'channel' => 'sms',
            'recipient_type' => 'user',
            'recipient_id' => (string) $user->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyGuestId = DB::table('shipment_notifications')->insertGetId([
            'timeline_event_id' => $timelineId,
            'channel' => 'sms',
            'recipient_type' => 'guest_contact',
            'recipient_id' => '+201000000000',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $recipient = NotificationRecipient::query()
            ->where('recipient_type', 'user')
            ->where('recipient_key', 'user:' . $user->id)
            ->firstOrFail();

        $this->assertSame(
            (int) $recipient->id,
            (int) DB::table('shipment_notifications')->where('id', $legacyUserId)->value('notification_recipient_id')
        );
        $this->assertNull(
            DB::table('shipment_notifications')->where('id', $legacyGuestId)->value('notification_recipient_id')
        );

        // The guest contact must never be copied into the canonical tables.
        $this->assertSame(0, NotificationRecipient::query()->where('recipient_type', 'guest_contact')->count());
        $this->assertStringNotContainsString(
            '201000000000',
            (string) json_encode(NotificationRecipient::query()->get()->toArray())
        );
    }

    public function test_ambiguous_user_identity_is_not_linked_or_auto_merged(): void
    {
        $user = $this->activeUser('ambiguous@example.test');
        $other = $this->activeUser('other@example.test');

        // Same stable key, wrong owner: resolution must surface a conflict
        // instead of silently merging the two identities.
        $conflicting = NotificationRecipient::query()->create([
            'recipient_type' => 'user',
            'recipient_key' => 'user:' . $user->id,
            'user_id' => $other->id,
            'status' => 'active',
        ]);

        $event = $this->event($user, 'in_transit');
        $this->recorder()->record($event);

        $this->assertSame(1, NotificationRecipient::query()->count());
        $this->assertSame($other->id, $conflicting->fresh()->user_id);

        $this->assertSame(
            3,
            ShipmentNotification::query()
                ->where('timeline_event_id', $event->timelineEventId)
                ->whereNull('notification_recipient_id')
                ->count()
        );

        $conflict = NotificationIdentityConflict::query()->firstOrFail();
        $this->assertSame('open', $conflict->status);
        $this->assertSame([(int) $conflicting->id], array_map('intval', $conflict->candidate_recipient_ids));
    }

    private function activeUser(string $email): User
    {
        return User::create([
            'name' => 'Stable Recipient User',
            'email' => $email,
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);
    }

    private function order(User $user, string $status = 'delivered'): Order
    {
        // Fixture orders are deliberately non-pending: the pending-order
        // unique index allows one pending order per user, and these tests
        // create several orders for the same recipient.
        return Order::query()->forceCreate([
            'tracking_number' => 'TRK-' . strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $user->id,
            'status' => $status,
        ]);
    }

    private function timeline(Order $order): int
    {
        return (int) DB::table('order_tracking_events')->insertGetId([
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'source' => 'direct',
            'actor_type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function event(User $user, string $newStatus): ShipmentStatusChanged
    {
        $order = $this->order($user);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineId = $this->timeline($order);

        return new ShipmentStatusChanged(
            order: $order,
            oldStatus: 'pending',
            newStatus: $newStatus,
            timelineEventId: $timelineId,
            shipmentId: (int) $shipment->id,
            orderId: (int) $order->id,
            source: 'direct',
        );
    }

    private function recorder(): ShipmentNotificationRecorder
    {
        return app(ShipmentNotificationRecorder::class);
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_11_24_000020_add_stable_recipient_to_shipment_notifications.php'
        );
    }
}

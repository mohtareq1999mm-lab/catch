<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\GuestIdentity;
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
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * #180: recipient identity is one canonical (channel, normalized contact).
 *
 * A fingerprint hash is the opaque normalized contact. The channel is kept as
 * part of the fingerprint identity so identical contact strings cannot merge
 * two channel identities. The empty channel is the legacy sentinel used by
 * pre-#180 callers and must keep resolving exactly as before.
 */
final class RecipientChannelCardinalityTest extends TestCase
{
    use RefreshDatabase;

    private function guest(string $salt): GuestIdentity
    {
        return GuestIdentity::query()->create([
            'token_hash' => hash('sha256', $salt),
            'expires_at' => now()->addDay(),
        ]);
    }

    private function activeUser(string $email): User
    {
        return User::create([
            'name' => 'Cardinality User',
            'email' => $email,
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);
    }

    private function alias(string $channel, string $hash): array
    {
        return [
            'channel' => $channel,
            'key_version' => 'v1',
            'fingerprint_hash' => $hash,
        ];
    }

    public function test_same_channel_and_contact_resolves_to_one_canonical_recipient(): void
    {
        $guest = $this->guest('same-channel');
        $alias = $this->alias('email', str_repeat('a', 64));
        $service = app(RecipientIdentityService::class);

        $first = $service->resolveRecipient([$alias], 'guest_contact', ['guest' => $guest]);
        $second = $service->resolveRecipient([$alias], 'guest_contact', ['guest' => $guest]);

        $this->assertSame('resolved', $first['status']);
        $this->assertSame('resolved', $second['status']);
        $this->assertSame($first['recipient']->id, $second['recipient']->id);
        $this->assertSame(1, NotificationRecipient::query()->count());
        $this->assertSame(1, NotificationRecipientFingerprint::query()->where('channel', 'email')->count());
        $this->assertSame(0, NotificationIdentityConflict::query()->count());
    }

    public function test_identical_contact_on_two_channels_creates_two_recipients_without_a_conflict(): void
    {
        $guest = $this->guest('cross-channel');
        $email = $this->alias('email', str_repeat('b', 64));
        $sms = $this->alias('sms', str_repeat('b', 64));
        $service = app(RecipientIdentityService::class);

        $emailRecipient = $service->resolveRecipient([$email], 'guest_contact', ['guest' => $guest]);
        $smsRecipient = $service->resolveRecipient([$sms], 'guest_contact', ['guest' => $guest]);

        $this->assertSame('resolved', $emailRecipient['status']);
        $this->assertSame('resolved', $smsRecipient['status']);
        $this->assertNotSame($emailRecipient['recipient']->id, $smsRecipient['recipient']->id);
        $this->assertSame(2, NotificationRecipientFingerprint::query()->count());
        $this->assertSame(1, NotificationRecipientFingerprint::query()->where('channel', 'email')->count());
        $this->assertSame(1, NotificationRecipientFingerprint::query()->where('channel', 'sms')->count());
        $this->assertSame(0, NotificationIdentityConflict::query()->count());
    }

    public function test_mixed_channel_alias_set_is_rejected_without_creating_a_recipient(): void
    {
        $guest = $this->guest('mixed-channel');

        try {
            app(RecipientIdentityService::class)->resolveRecipient([
                $this->alias('email', str_repeat('c', 64)),
                $this->alias('sms', str_repeat('d', 64)),
            ], 'guest_contact', ['guest' => $guest]);
            $this->fail('A mixed-channel alias set must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fingerprints', $e->errors());
        }

        $this->assertSame(0, NotificationRecipient::query()->count());
        $this->assertSame(0, NotificationRecipientFingerprint::query()->count());
    }

    public function test_channel_scoped_alias_is_unique_at_the_database_boundary(): void
    {
        $first = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create(
            ['recipient_id' => $first->id] + $this->alias('email', str_repeat('e', 64))
        );

        $second = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);

        $this->expectException(QueryException::class);
        NotificationRecipientFingerprint::query()->create(
            ['recipient_id' => $second->id] + $this->alias('email', str_repeat('e', 64))
        );
    }

    public function test_channel_scoped_user_contacts_do_not_collapse_into_one_account_recipient(): void
    {
        $user = $this->activeUser('cardinality@example.test');
        $hash = str_repeat('f', 64);
        $service = app(RecipientIdentityService::class);

        $email = $service->resolveRecipient([$this->alias('email', $hash)], 'user', ['user' => $user]);
        $sms = $service->resolveRecipient([$this->alias('sms', $hash)], 'user', ['user' => $user]);

        $this->assertSame('resolved', $email['status']);
        $this->assertSame('resolved', $sms['status']);
        $this->assertNotSame($email['recipient']->id, $sms['recipient']->id);
        $this->assertSame($user->id, (int) $email['recipient']->user_id);
        $this->assertSame($user->id, (int) $sms['recipient']->user_id);
        $this->assertNull($email['recipient']->recipient_key);
        $this->assertNull($sms['recipient']->recipient_key);
        $this->assertSame(0, NotificationIdentityConflict::query()->count());
    }

    public function test_resolve_for_recipient_key_does_not_merge_channel_scoped_contacts(): void
    {
        $user = $this->activeUser('stable-key-cardinality@example.test');
        $hash = str_repeat('9', 64);
        $service = app(RecipientIdentityService::class);
        $key = 'user:' . $user->id;

        $email = $service->resolveForRecipientKey(
            'user',
            $key,
            [$this->alias('email', $hash)],
            null,
            ['user' => $user],
        );
        $sms = $service->resolveForRecipientKey(
            'user',
            $key,
            [$this->alias('sms', $hash)],
            null,
            ['user' => $user],
        );

        $this->assertSame('resolved', $email['status']);
        $this->assertSame('resolved', $sms['status']);
        $this->assertNotSame($email['recipient']->id, $sms['recipient']->id);
        $this->assertNull($email['recipient']->recipient_key);
        $this->assertNull($sms['recipient']->recipient_key);
        $this->assertSame(0, NotificationIdentityConflict::query()->count());
    }

    public function test_recorder_dedupes_notifications_for_one_timeline_and_channel(): void
    {
        $user = $this->activeUser('fanout@example.test');
        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-180-' . uniqid(),
            'user_id' => $user->id,
            'user_email' => 'delivery@example.test',
            'user_phone' => '+201000000999',
        ]);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);

        $timelineEventId = DB::table('order_tracking_events')->insertGetId([
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'source' => 'direct',
            'actor_type' => 'system',
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
            source: 'direct',
        );

        $recorder = app(ShipmentNotificationRecorder::class);
        $recorder->record($event);
        $recorder->record($event);

        $this->assertSame(3, ShipmentNotification::query()->where('timeline_event_id', $timelineEventId)->count());
        $this->assertEqualsCanonicalizing(
            ['email', 'sms', 'push'],
            ShipmentNotification::query()->where('timeline_event_id', $timelineEventId)->pluck('channel')->all()
        );
        $this->assertSame(0, DB::table('order_notifications')->count());
    }
}

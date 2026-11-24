<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\NotificationIdentityConflict;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientFingerprint;
use App\Models\Shipping\ShipmentNotification;
use App\Services\Notification\RecipientIdentityService;
use App\Models\GuestIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class RecipientFingerprintAliasClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_fingerprint_alias_is_unique_per_key_version_and_fingerprint_hash(): void
    {
        $r1 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $r1->id,
            'key_version' => 'v1',
            'fingerprint_hash' => str_repeat('a', 64),
        ]);

        $r2 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $r2->id,
            'key_version' => 'v1',
            'fingerprint_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_fingerprint_alias_is_unique_per_recipient_and_key_version(): void
    {
        $r1 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $r1->id,
            'key_version' => 'v1',
            'fingerprint_hash' => str_repeat('a', 64),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $r1->id,
            'key_version' => 'v1',
            'fingerprint_hash' => str_repeat('b', 64),
        ]);
    }

    public function test_ambiguous_matches_create_audited_identity_conflict_case_with_actions(): void
    {
        $r1 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        $r2 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r1->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)]);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r2->id, 'key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)]);

        $service = app(RecipientIdentityService::class);
        $result = $service->resolveRecipient([
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)],
        ], 'guest_contact', ['channel' => 'email']);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertDatabaseHas('notification_identity_conflicts', ['status' => 'open']);
        $conflict = NotificationIdentityConflict::query()->firstOrFail();
        $this->assertEqualsCanonicalizing([$r1->id, $r2->id], $conflict->candidate_recipient_ids);

        $resolver = User::create(['name' => 'Resolver', 'email' => 'resolver@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);
        $service->resolveConflict($conflict, 'link', $resolver->id, 'same recipient');
        $this->assertSame('linked', $conflict->fresh()->status);
        $this->assertSame($resolver->id, $conflict->fresh()->resolved_by);
        $this->assertNotNull($conflict->fresh()->resolved_at);

        $conflict2 = NotificationIdentityConflict::query()->create([
            'channel' => 'sms', 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('c', 64),
            'candidate_recipient_ids' => [$r1->id, $r2->id], 'status' => 'open',
        ]);
        $service->resolveConflict($conflict2, 'split', $resolver->id, null);
        $this->assertSame('split', $conflict2->fresh()->status);

        $conflict3 = NotificationIdentityConflict::query()->create([
            'channel' => 'sms', 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('d', 64),
            'candidate_recipient_ids' => [$r1->id, $r2->id], 'status' => 'open',
        ]);
        $service->resolveConflict($conflict3, 'dismiss', $resolver->id, 'noise');
        $this->assertSame('dismissed', $conflict3->fresh()->status);
    }

    public function test_claiming_guest_order_preserves_existing_recipient_identity_and_no_resend(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);
        $guest = GuestIdentity::query()->create(['token_hash' => hash('sha256', 'g1'), 'expires_at' => now()->addDay()]);
        $recipient = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $recipient->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('f', 64),
        ]);

        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-CLAIM-1', 'guest_id' => $guest->id, 'user_id' => null,
            'status' => Order::ORDER_STATUS_PENDING, 'total_price' => 10, 'price' => 10,
            'fulfillment_type' => 'delivery', 'payment_method' => 'cod', 'shipping_method' => 'SCHEDULED',
            'address' => [], 'name' => 'G', 'user_phone' => '+201000000001',
        ]);

        DB::table('order_tracking_events')->insert([
            'order_id' => $order->id, 'event_type' => 'shipment.status_changed', 'old_status' => 'pending',
            'new_status' => 'picked_up', 'source' => 'direct', 'actor_type' => 'system', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $timelineId = (int) DB::table('order_tracking_events')->value('id');
        ShipmentNotification::query()->create([
            'shipment_id' => null, 'timeline_event_id' => $timelineId, 'channel' => 'email',
            'recipient_type' => 'guest_contact', 'recipient_id' => (string) $recipient->id,
            'status' => 'sent', 'dedupe_key' => 'k1',
        ]);

        app(RecipientIdentityService::class)->linkGuestRecipientsToUser($guest, $user);

        $recipient->refresh();
        $this->assertSame($guest->id, $recipient->guest_id);
        $this->assertSame($user->id, $recipient->user_id);
        $this->assertSame(1, ShipmentNotification::query()->where('timeline_event_id', $timelineId)->where('channel', 'email')->where('recipient_id', (string) $recipient->id)->count());
    }

    public function test_claiming_does_not_change_order_ownership_payment_records_or_shipment_state(): void
    {
        $user = User::create(['name' => 'U2', 'email' => 'u2@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);
        $guest = GuestIdentity::query()->create(['token_hash' => hash('sha256', 'g2'), 'expires_at' => now()->addDay()]);
        NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-CLAIM-2', 'guest_id' => $guest->id, 'user_id' => null,
            'status' => Order::ORDER_STATUS_PENDING, 'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'shipment_status' => Order::SHIPMENT_STATUS_PENDING,
            'total_price' => 10, 'price' => 10, 'fulfillment_type' => 'delivery', 'payment_method' => 'cod', 'shipping_method' => 'SCHEDULED',
            'address' => [], 'name' => 'G', 'user_phone' => '+201000000001',
        ]);
        $shipmentId = DB::table('shipments')->insertGetId([
            'order_id' => $order->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeOrder = $order->only(['user_id', 'payment_status', 'shipment_status', 'status']);
        $beforeShipment = (array) DB::table('shipments')->where('id', $shipmentId)->first();

        app(RecipientIdentityService::class)->linkGuestRecipientsToUser($guest, $user);

        $order->refresh();
        $afterShipment = (array) DB::table('shipments')->where('id', $shipmentId)->first();
        $this->assertSame($beforeOrder, $order->only(['user_id', 'payment_status', 'shipment_status', 'status']));
        $this->assertEquals($beforeShipment, $afterShipment);
    }
}

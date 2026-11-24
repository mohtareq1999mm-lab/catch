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
use Illuminate\Validation\ValidationException;
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
        ], 'guest_contact', ['channel' => 'email', 'email' => 'secret@example.test']);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertDatabaseHas('notification_identity_conflicts', ['status' => 'open']);
        $conflict = NotificationIdentityConflict::query()->firstOrFail();
        $this->assertEqualsCanonicalizing([$r1->id, $r2->id], $conflict->candidate_recipient_ids);
        $this->assertArrayNotHasKey('email', $conflict->metadata['context'] ?? []);

        $resolver = User::create(['name' => 'Resolver', 'email' => 'resolver@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);
        $service->resolveConflict($conflict, 'link', $resolver->id, 'same recipient', $r1->id);
        $this->assertSame('linked', $conflict->fresh()->status);
        $this->assertSame($resolver->id, $conflict->fresh()->resolved_by);
        $this->assertNotNull($conflict->fresh()->resolved_at);
        $this->assertSame($r1->id, $conflict->fresh()->metadata['resolution']['target_recipient_id']);

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

    public function test_duplicate_conflict_detection_is_deduped_for_same_open_candidate_set(): void
    {
        $r1 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        $r2 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r1->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)]);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r2->id, 'key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)]);
        $service = app(RecipientIdentityService::class);
        $aliases = [
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)],
        ];
        $a = $service->resolveRecipient($aliases, 'guest_contact', ['channel' => 'sms']);
        $b = $service->resolveRecipient($aliases, 'guest_contact', ['channel' => 'sms']);
        $this->assertSame('ambiguous', $a['status']);
        $this->assertSame($a['conflict']->id, $b['conflict']->id);
        $this->assertSame(1, NotificationIdentityConflict::query()->where('status', 'open')->count());
    }

    public function test_resolve_conflict_rejects_invalid_transition_and_is_idempotent_for_equivalent_request(): void
    {
        $r1 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        $r2 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        $conflict = NotificationIdentityConflict::query()->create([
            'channel' => 'email', 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('e', 64),
            'candidate_recipient_ids' => [$r1->id, $r2->id], 'status' => 'open', 'metadata' => [],
        ]);
        $service = app(RecipientIdentityService::class);
        $resolver = User::create(['name' => 'R', 'email' => 'r@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);

        $resolved = $service->resolveConflict($conflict, 'dismiss', $resolver->id, 'x');
        $same = $service->resolveConflict($resolved, 'dismiss', $resolver->id, 'x');
        $this->assertSame($resolved->id, $same->id);

        $this->expectException(ValidationException::class);
        $service->resolveConflict($resolved->fresh(), 'link', $resolver->id, 'different', $r1->id);
    }

    public function test_conflict_resolution_requires_active_resolver_and_honors_authorization_hook(): void
    {
        $conflict = NotificationIdentityConflict::query()->create([
            'channel' => 'email', 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64),
            'candidate_recipient_ids' => [1, 2], 'status' => 'open', 'metadata' => [],
        ]);
        $resolver = User::create(['name' => 'R', 'email' => 'resolver2@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);

        try {
            app(RecipientIdentityService::class)->resolveConflict($conflict->fresh(), 'dismiss', null, 'x');
            $this->fail('Missing resolver was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('resolved_by', $e->errors());
        }

        $denied = new RecipientIdentityService(function () {
            throw ValidationException::withMessages(['resolved_by' => 'Forbidden.']);
        });
        try {
            $denied->resolveConflict($conflict->fresh(), 'dismiss', $resolver->id, 'x');
            $this->fail('Authorization hook was bypassed.');
        } catch (ValidationException $e) {
            $this->assertSame('Forbidden.', $e->errors()['resolved_by'][0]);
        }
        $this->assertSame('open', $conflict->fresh()->status);
    }

    public function test_ambiguity_does_not_create_orphan_recipient_or_partial_aliases(): void
    {
        $existingA = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        $existingB = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $existingA->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('1', 64),
        ]);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $existingB->id, 'key_version' => 'v2', 'fingerprint_hash' => str_repeat('2', 64),
        ]);

        $service = app(RecipientIdentityService::class);
        $result = $service->resolveRecipient([
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('1', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('2', 64)],
            ['key_version' => 'v3', 'fingerprint_hash' => str_repeat('3', 64)],
        ], 'guest_contact', ['channel' => 'push']);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame(2, NotificationRecipient::query()->count());
        $this->assertDatabaseMissing('notification_recipient_fingerprints', ['key_version' => 'v3', 'fingerprint_hash' => str_repeat('3', 64)]);
        $this->assertDatabaseHas('notification_identity_conflicts', ['status' => 'open']);
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

    public function test_recipient_already_linked_to_different_user_creates_conflict_and_does_not_relink(): void
    {
        $user = User::create(['name' => 'U3', 'email' => 'u3@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);
        $other = User::create(['name' => 'U4', 'email' => 'u4@example.test', 'password' => bcrypt('password'), 'type' => 'user', 'is_active' => true]);
        $guest = GuestIdentity::query()->create(['token_hash' => hash('sha256', 'g3'), 'expires_at' => now()->addDay()]);
        $recipient = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id, 'user_id' => $other->id]);

        $updated = app(RecipientIdentityService::class)->linkGuestRecipientsToUser($guest, $user);

        $this->assertSame(0, $updated);
        $this->assertSame($other->id, $recipient->fresh()->user_id);
        $this->assertDatabaseHas('notification_identity_conflicts', ['status' => 'open']);
        $conflict = NotificationIdentityConflict::query()->latest('id')->firstOrFail();
        $this->assertSame('guest_claim_user_mismatch', $conflict->metadata['context']['reason'] ?? null);
    }
}

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
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class RecipientFingerprintAliasClaimTest extends TestCase
{
    use RefreshDatabase;

    private function guest(string $salt = 'g'): GuestIdentity
    {
        return GuestIdentity::query()->create([
            'token_hash' => hash('sha256', $salt),
            'expires_at' => now()->addDay(),
        ]);
    }

    private function activeUser(string $email): User
    {
        return User::create([
            'name' => 'Fingerprint User',
            'email' => $email,
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);
    }

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
        $guest = $this->guest('ambiguous');
        $r1 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
        $r2 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r1->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)]);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r2->id, 'key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)]);

        $service = app(RecipientIdentityService::class);
        $result = $service->resolveRecipient([
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)],
        ], 'guest_contact', ['guest' => $guest, 'channel' => 'email', 'email' => 'secret@example.test']);

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
        $guest = $this->guest('dedupe');
        $r1 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
        $r2 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r1->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)]);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r2->id, 'key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)]);
        $service = app(RecipientIdentityService::class);
        $aliases = [
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)],
        ];
        $context = ['guest' => $guest, 'channel' => 'sms'];
        $a = $service->resolveRecipient($aliases, 'guest_contact', $context);
        $b = $service->resolveRecipient($aliases, 'guest_contact', $context);
        $reversed = $service->resolveRecipient(array_reverse($aliases), 'guest_contact', $context);
        $this->assertSame('ambiguous', $a['status']);
        $this->assertSame($a['conflict']->id, $b['conflict']->id);
        $this->assertSame($a['conflict']->id, $reversed['conflict']->id);
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
        $guest = $this->guest('partial');
        $existingA = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
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
        ], 'guest_contact', ['guest' => $guest, 'channel' => 'push']);

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

    public function test_same_version_divergent_hashes_are_rejected_without_writes(): void
    {
        $guest = $this->guest('same-version');

        try {
            app(RecipientIdentityService::class)->resolveRecipient([
                ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
                ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('b', 64)],
            ], 'guest_contact', ['guest' => $guest]);
            $this->fail('Divergent hashes for one version were accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fingerprints', $e->errors());
        }

        $this->assertSame(0, NotificationRecipient::query()->count());
        $this->assertSame(0, NotificationRecipientFingerprint::query()->count());
        $this->assertSame(0, NotificationIdentityConflict::query()->count());
    }

    public function test_existing_same_version_hash_divergence_opens_conflict_without_query_exception(): void
    {
        $guest = $this->guest('existing-version');
        $recipient = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $recipient->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64),
        ]);

        $result = app(RecipientIdentityService::class)->resolveRecipient([
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('b', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('c', 64)],
        ], 'guest_contact', ['guest' => $guest]);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame('fingerprint_alias_divergence', $result['conflict']->metadata['reason'] ?? null);
        $this->assertDatabaseMissing('notification_recipient_fingerprints', ['key_version' => 'v2']);
        $this->assertDatabaseMissing('notification_recipient_fingerprints', ['fingerprint_hash' => str_repeat('b', 64)]);
        $this->assertSame(1, NotificationRecipient::query()->count());
    }

    public function test_resolve_requires_authoritative_context_and_authorization_hook(): void
    {
        $guest = $this->guest('authority');
        $recipient = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $recipient->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64),
        ]);
        $aliases = [['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)]];

        try {
            app(RecipientIdentityService::class)->resolveRecipient($aliases, 'guest_contact', ['guest_id' => $guest->id]);
            $this->fail('Raw guest_id context was accepted as authority.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('context', $e->errors());
        }

        $denied = new RecipientIdentityService(null, function () {
            throw ValidationException::withMessages(['context' => 'Lookup forbidden.']);
        });
        try {
            $denied->resolveRecipient($aliases, 'guest_contact', ['guest' => $guest]);
            $this->fail('Lookup authorization hook was bypassed.');
        } catch (ValidationException $e) {
            $this->assertSame('Lookup forbidden.', $e->errors()['context'][0]);
        }

        $this->assertSame(0, NotificationIdentityConflict::query()->count());
        $this->assertSame(1, NotificationRecipientFingerprint::query()->count());
    }

    public function test_foreign_recipient_identity_is_not_grafted_with_new_aliases(): void
    {
        $guest = $this->guest('foreign');
        $recipient = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'user_id' => $this->activeUser('foreign@example.test')->id]);
        NotificationRecipientFingerprint::query()->create([
            'recipient_id' => $recipient->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64),
        ]);

        $result = app(RecipientIdentityService::class)->resolveRecipient([
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)],
        ], 'guest_contact', ['guest' => $guest]);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame('recipient_ownership_mismatch', $result['conflict']->metadata['reason'] ?? null);
        $this->assertDatabaseMissing('notification_recipient_fingerprints', ['key_version' => 'v2']);
        $this->assertNull($recipient->fresh()->guest_id);
    }

    public function test_alias_rotation_keeps_the_same_recipient_and_adds_a_new_version(): void
    {
        $user = $this->activeUser('rotation@example.test');
        $service = app(RecipientIdentityService::class);

        $first = $service->resolveRecipient([
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
        ], 'user', ['user' => $user]);
        $rotated = $service->resolveRecipient([
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)],
        ], 'user', ['user' => $user]);

        $this->assertSame('resolved', $first['status']);
        $this->assertSame('resolved', $rotated['status']);
        $this->assertSame($first['recipient']->id, $rotated['recipient']->id);
        $this->assertSame(1, NotificationRecipient::query()->count());
        $this->assertSame(2, NotificationRecipientFingerprint::query()->where('recipient_id', $first['recipient']->id)->count());
    }

    public function test_link_moves_aliases_merges_source_and_writes_an_audit_row(): void
    {
        $resolver = $this->activeUser('link-auditor@example.test');
        $target = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        $source = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $target->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)]);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $source->id, 'key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)]);
        $conflict = NotificationIdentityConflict::query()->create([
            'candidate_recipient_ids' => [$target->id, $source->id], 'status' => 'open',
        ]);

        app(RecipientIdentityService::class)->resolveConflict($conflict, 'link', $resolver->id, 'dedupe', $target->id);

        $this->assertSame(2, NotificationRecipientFingerprint::query()->where('recipient_id', $target->id)->count());
        $this->assertSame(0, NotificationRecipientFingerprint::query()->where('recipient_id', $source->id)->count());
        $this->assertSame('merged', $source->fresh()->status);
        $this->assertSame('active', $target->fresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => NotificationIdentityConflict::class,
            'subject_id' => $conflict->id,
            'event' => 'notification_identity_conflict_link',
        ]);
    }

    public function test_link_rejects_candidates_owned_by_different_users_or_guests(): void
    {
        $resolver = $this->activeUser('link-reject@example.test');
        $userA = $this->activeUser('link-a@example.test');
        $userB = $this->activeUser('link-b@example.test');
        $target = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'user_id' => $userA->id, 'guest_id' => $this->guest('link-a')->id]);
        $source = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'user_id' => $userB->id, 'guest_id' => $this->guest('link-b')->id]);

        $userConflict = NotificationIdentityConflict::query()->create([
            'candidate_recipient_ids' => [$target->id, $source->id], 'status' => 'open',
        ]);

        try {
            app(RecipientIdentityService::class)->resolveConflict($userConflict, 'link', $resolver->id, null, $target->id);
            $this->fail('Different-user candidates were linked.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame('open', $userConflict->fresh()->status);

        $sharedUser = $this->activeUser('link-shared@example.test');
        $guestTarget = NotificationRecipient::query()->create([
            'recipient_type' => 'guest_contact', 'user_id' => $sharedUser->id, 'guest_id' => $this->guest('link-target')->id,
        ]);
        $guestSource = NotificationRecipient::query()->create([
            'recipient_type' => 'guest_contact', 'user_id' => $sharedUser->id, 'guest_id' => $this->guest('link-source')->id,
        ]);
        $guestConflict = NotificationIdentityConflict::query()->create([
            'candidate_recipient_ids' => [$guestTarget->id, $guestSource->id], 'status' => 'open',
        ]);

        try {
            app(RecipientIdentityService::class)->resolveConflict($guestConflict, 'link', $resolver->id, null, $guestTarget->id);
            $this->fail('Different-guest candidates were linked.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame('open', $guestConflict->fresh()->status);
    }

    public function test_resolution_note_rejects_contact_details_before_storage_or_audit(): void
    {
        $resolver = $this->activeUser('note@example.test');
        $conflict = NotificationIdentityConflict::query()->create([
            'candidate_recipient_ids' => [1, 2], 'status' => 'open',
        ]);

        try {
            app(RecipientIdentityService::class)->resolveConflict(
                $conflict, 'dismiss', $resolver->id, 'call +201000000001 or mail a@b.test'
            );
            $this->fail('Contact-like resolution note was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
        }

        $this->assertNull($conflict->fresh()->resolution_note);
        $this->assertSame('open', $conflict->fresh()->status);
        $this->assertSame(0, DB::table('activity_log')
            ->where('subject_type', NotificationIdentityConflict::class)
            ->where('subject_id', $conflict->id)
            ->count());
    }

    public function test_guest_claim_mismatch_conflicts_are_deduped_on_replay(): void
    {
        $user = $this->activeUser('claim-replay@example.test');
        $other = $this->activeUser('claim-owner@example.test');
        $guest = $this->guest('claim-replay');
        NotificationRecipient::query()->create([
            'recipient_type' => 'guest_contact', 'guest_id' => $guest->id, 'user_id' => $other->id,
        ]);
        $service = app(RecipientIdentityService::class);

        $service->linkGuestRecipientsToUser($guest, $user);
        $service->linkGuestRecipientsToUser($guest, $user);

        $conflict = NotificationIdentityConflict::query()->firstOrFail();
        $this->assertSame(1, NotificationIdentityConflict::query()->where('status', 'open')->count());
        $this->assertSame(1, DB::table('activity_log')
            ->where('subject_type', NotificationIdentityConflict::class)
            ->where('subject_id', $conflict->id)
            ->where('event', 'notification_identity_conflict_guest_claim_mismatch')
            ->count());
    }

    public function test_free_text_reason_and_source_are_redacted_in_metadata_and_audit(): void
    {
        $guest = $this->guest('pii');
        $r1 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'guest_id' => $guest->id]);
        $r2 = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact']);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r1->id, 'key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)]);
        NotificationRecipientFingerprint::query()->create(['recipient_id' => $r2->id, 'key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)]);

        $service = app(RecipientIdentityService::class);
        $result = $service->resolveRecipient([
            ['key_version' => 'v1', 'fingerprint_hash' => str_repeat('a', 64)],
            ['key_version' => 'v2', 'fingerprint_hash' => str_repeat('b', 64)],
        ], 'guest_contact', [
            'guest' => $guest,
            'channel' => 'sms',
            'reason' => 'call +201000000001',
            'source' => 'mail secret@example.test',
        ]);

        $conflict = $result['conflict'];
        $resolver = $this->activeUser('pii-resolver@example.test');
        $service->resolveConflict($conflict, 'dismiss', $resolver->id, 'reviewed');
        $audit = DB::table('activity_log')
            ->where('subject_type', NotificationIdentityConflict::class)
            ->where('subject_id', $conflict->id)
            ->where('event', 'notification_identity_conflict_dismiss')
            ->first();
        $this->assertNotNull($audit);

        $encoded = json_encode(['metadata' => $conflict->fresh()->metadata, 'audit' => $audit->properties]);
        $this->assertStringNotContainsString('secret@example.test', $encoded);
        $this->assertStringNotContainsString('+201000000001', $encoded);
        $this->assertStringContainsString('[REDACTED]', $encoded);
    }

    /**
     * Regression: stable-key-only resolution has no fingerprint alias to
     * anchor a conflict on. It must still record the ambiguity (instead of
     * crashing) and must reuse the open conflict on repeat lookups.
     */
    public function test_stable_key_only_ambiguity_is_recorded_without_fingerprint_aliases(): void
    {
        $requested = $this->activeUser('key-only-requested@example.test');
        $storedOwner = $this->activeUser('key-only-stored@example.test');

        $recipient = NotificationRecipient::query()->create([
            'recipient_type' => 'user',
            'recipient_key' => 'user:' . $requested->id,
            'user_id' => $storedOwner->id,
            'status' => 'active',
        ]);

        $service = app(RecipientIdentityService::class);
        $result = $service->resolveForRecipientKey(
            'user',
            'user:' . $requested->id,
            [],
            null,
            ['user' => $requested]
        );

        $this->assertSame('ambiguous', $result['status']);
        $conflict = $result['conflict'];
        $this->assertSame([(int) $recipient->id], array_map('intval', $conflict->candidate_recipient_ids));
        $this->assertNull($conflict->key_version);
        $this->assertNull($conflict->fingerprint_hash);
        $this->assertSame('recipient_ownership_mismatch', $conflict->metadata['reason'] ?? null);

        $again = $service->resolveForRecipientKey(
            'user',
            'user:' . $requested->id,
            [],
            null,
            ['user' => $requested]
        );

        $this->assertSame('ambiguous', $again['status']);
        $this->assertSame((int) $conflict->id, (int) $again['conflict']->id);
        $this->assertSame(1, NotificationIdentityConflict::query()->where('status', 'open')->count());
    }
}

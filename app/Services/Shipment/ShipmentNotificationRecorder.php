<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\Shipping\ShipmentNotification;
use App\Models\Shipping\ShipmentNotificationHistory;
use App\Services\Notification\RecipientIdentityService;
use App\Support\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;

class ShipmentNotificationRecorder
{
    private static ?bool $stableIdentityAvailable = null;

    public function __construct(private readonly RecipientIdentityService $identities)
    {
    }

    private const APPROVED_MILESTONES = [
        'picked_up',
        'out_for_delivery',
        'delivered',
        'failed_delivery',
        'delayed',
    ];

    /**
     * Record shipment notification intents for a canonical transition.
     * Dedupe is enforced by DB unique (timeline_event_id, channel, recipient_type, recipient_id)
     * and, when the canonical tables exist (#167), additionally by the stable
     * recipient identity. Registered users keep existing behavior (#146).
     * Guest orders record email+sms intents for approved milestones;
     * external guest delivery remains gated by #125's legal approval decision.
     */
    public function record(ShipmentStatusChanged $event): void
    {
        if (!$event->timelineEventId) {
            return;
        }

        $userId = $event->order->user_id ?? null;
        if ($userId) {
            $this->recordRegisteredUser($event, (string) $userId);
            return;
        }

        if ($event->order->guest_id !== null) {
            $this->recordGuest($event);
        }
    }

    private function recordRegisteredUser(ShipmentStatusChanged $event, string $userId): void
    {
        $stableRecipientId = $this->stableRecipientId($event);

        foreach (['email', 'sms', 'push'] as $channel) {
            $attributes = [
                'shipment_id' => $event->shipmentId,
                'timeline_event_id' => $event->timelineEventId,
                'channel' => $channel,
                'recipient_type' => 'user',
                'recipient_id' => $userId,
                'status' => 'pending',
                'source' => (string) ($event->source ?: 'direct'),
                'dedupe_key' => $this->dedupeKey((int) $event->timelineEventId, $channel, 'user', $userId),
                'payload' => $this->basePayload($event),
            ];

            if ($stableRecipientId !== null) {
                $attributes['notification_recipient_id'] = $stableRecipientId;
            }

            $this->storeIntent($attributes);
        }
    }

    /**
     * Resolve the canonical stable recipient for the order owner (#167).
     *
     * Ambiguous or non-authoritative identities deliberately return null: the
     * notification is still recorded with the legacy identity, but no stable
     * link is invented and no two recipients are auto-merged. Divergence is
     * surfaced by RecipientIdentityService as an audited conflict case.
     */
    private function stableRecipientId(ShipmentStatusChanged $event): ?int
    {
        if (!$this->stableIdentityAvailable()) {
            return null;
        }

        $user = $event->order->user;
        if (!$user instanceof User) {
            return null;
        }

        try {
            $result = $this->identities->resolveForRecipientKey(
                'user',
                'user:' . (int) $user->id,
                [],
                null,
                ['user' => $user]
            );
        } catch (ValidationException) {
            return null;
        }

        return $result['status'] === 'resolved'
            ? (int) $result['recipient']->id
            : null;
    }

    private function stableIdentityAvailable(): bool
    {
        // Only a positive probe is memoized: a long-running worker that booted
        // before the migration must recover once the tables exist instead of
        // permanently disabling stable links for its whole lifetime.
        if (self::$stableIdentityAvailable === true) {
            return true;
        }

        return self::$stableIdentityAvailable = Schema::hasTable('notification_recipients')
            && Schema::hasColumn('shipment_notifications', 'notification_recipient_id');
    }

    private function recordGuest(ShipmentStatusChanged $event): void
    {
        if (!in_array($event->newStatus, self::APPROVED_MILESTONES, true)) {
            return;
        }

        $secret = $this->recipientSecret();
        if ($secret === null) {
            $this->recordGuestSecretMissing($event);
            return;
        }

        $channels = [
            'email' => $this->normalizedEmail($event->order->user_email ?? null),
            'sms' => $this->normalizedPhone($event->order->user_phone ?? null),
        ];

        foreach ($channels as $channel => $contact) {
            if ($contact['status'] === 'missing' || $contact['status'] === 'invalid') {
                $this->storeIntent($this->guestSkippedRow($event, $channel, $contact['reason'], $secret));
                continue;
            }

            $fingerprint = $this->recipientFingerprint($channel, $contact['value'], (int) $event->timelineEventId, $secret);
            $encryptedContact = $this->encryptContact($contact['value']);
            if ($encryptedContact === null) {
                $this->storeIntent($this->guestSkippedRow($event, $channel, 'encryption_failed', $secret));
                continue;
            }

            $this->storeIntent([
                'shipment_id' => $event->shipmentId,
                'timeline_event_id' => $event->timelineEventId,
                'channel' => $channel,
                'recipient_type' => 'guest_contact',
                'recipient_id' => $fingerprint,
                'recipient_contact_encrypted' => $encryptedContact,
                'recipient_fingerprint_version' => 'v1',
                'status' => 'pending',
                'source' => (string) ($event->source ?: 'direct'),
                'dedupe_key' => $this->dedupeKey((int) $event->timelineEventId, $channel, 'guest_contact', $fingerprint),
                'payload' => $this->basePayload($event),
            ]);
        }
    }

    private function guestSkippedRow(ShipmentStatusChanged $event, string $channel, string $reason, string $secret): array
    {
        $fingerprint = $this->recipientFingerprint(
            $channel,
            'missing:' . $event->orderId . ':' . $channel . ':' . $reason,
            (int) $event->timelineEventId,
            $secret
        );

        return [
            'shipment_id' => $event->shipmentId,
            'timeline_event_id' => $event->timelineEventId,
            'channel' => $channel,
            'recipient_type' => 'guest_contact',
            'recipient_id' => $fingerprint,
            'recipient_contact_encrypted' => null,
            'recipient_fingerprint_version' => 'v1',
            'status' => 'skipped',
            'failure_reason' => $reason,
            'source' => (string) ($event->source ?: 'direct'),
            'dedupe_key' => $this->dedupeKey((int) $event->timelineEventId, $channel, 'guest_contact', $fingerprint),
            'payload' => $this->basePayload($event),
        ];
    }

    private function recordGuestSecretMissing(ShipmentStatusChanged $event): void
    {
        Log::error('Shipment guest notification recipient secret missing; skipping guest notification intents', [
            'order_id' => $event->orderId,
            'shipment_id' => $event->shipmentId,
            'timeline_event_id' => $event->timelineEventId,
            'new_status' => $event->newStatus,
        ]);

        foreach (['email', 'sms'] as $channel) {
            $recipientId = hash('sha256', 'guest:missing-secret:' . $channel . ':' . (int) $event->timelineEventId);
            $this->storeIntent([
                'shipment_id' => $event->shipmentId,
                'timeline_event_id' => $event->timelineEventId,
                'channel' => $channel,
                'recipient_type' => 'guest_contact',
                'recipient_id' => $recipientId,
                'recipient_contact_encrypted' => null,
                'recipient_fingerprint_version' => 'v1',
                'status' => 'skipped',
                'failure_reason' => 'missing_recipient_secret',
                'source' => (string) ($event->source ?: 'direct'),
                'dedupe_key' => $this->dedupeKey((int) $event->timelineEventId, $channel, 'guest_contact', $recipientId),
                'payload' => $this->basePayload($event),
            ]);
        }
    }

    private function storeIntent(array $attributes): void
    {
        try {
            $notification = ShipmentNotification::query()->create($attributes);
            // #183: every recorded intent opens its append-only history trail.
            ShipmentNotificationHistory::appendFor($notification, 'recorded', 0);
        } catch (QueryException $e) {
            if (UniqueViolation::detect($e)) {
                return;
            }
            throw $e;
        }
    }

    private function basePayload(ShipmentStatusChanged $event): array
    {
        return [
            'order_id' => $event->orderId,
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus,
            'source' => $event->source,
        ];
    }

    private function dedupeKey(int $timelineEventId, string $channel, string $recipientType, string $recipientId): string
    {
        return hash('sha256', implode('|', ['shipment-notification', $timelineEventId, $channel, $recipientType, $recipientId]));
    }

    private function recipientFingerprint(string $channel, string $normalizedContact, int $timelineEventId, string $secret): string
    {
        return hash_hmac(
            'sha256',
            'guest:v1:' . $channel . ':' . $timelineEventId . ':' . $normalizedContact,
            $secret
        );
    }

    private function recipientSecret(): ?string
    {
        $secret = trim((string) config('services.shipment_notifications.recipient_secret', ''));
        return $secret !== '' ? $secret : null;
    }

    /** Encrypt contact data; null tells the caller to fail closed instead of writing plaintext. */
    private function encryptContact(string $value): ?string
    {
        try {
            return Crypt::encryptString($value);
        } catch (\Throwable $e) {
            Log::warning('Shipment notification contact encryption failed', [
                'exception' => get_class($e),
            ]);
            return null;
        }
    }

    /** @return array{status:'ok'|'missing'|'invalid', value?:string, reason:string} */
    private function normalizedEmail(?string $email): array
    {
        $email = trim((string) $email);
        if ($email === '') {
            return ['status' => 'missing', 'reason' => 'missing_email'];
        }

        $normalized = strtolower($email);
        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'invalid', 'reason' => 'invalid_email'];
        }

        return ['status' => 'ok', 'value' => $normalized, 'reason' => 'ok'];
    }

    /** @return array{status:'ok'|'missing'|'invalid', value?:string, reason:string} */
    private function normalizedPhone(?string $phone): array
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return ['status' => 'missing', 'reason' => 'missing_phone'];
        }

        $normalized = preg_replace('/[\s\-\(\)]+/', '', $phone) ?? $phone;
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $normalized)) {
            return ['status' => 'invalid', 'reason' => 'invalid_phone'];
        }

        return ['status' => 'ok', 'value' => $normalized, 'reason' => 'ok'];
    }
}

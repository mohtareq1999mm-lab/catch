<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\Shipping\NotificationRecipient;
use App\Models\Shipping\ShipmentNotification;
use App\Support\UniqueViolation;
use Illuminate\Database\QueryException;

class ShipmentNotificationRecorder
{
    public function __construct(private readonly NotificationRecipientResolver $recipientResolver)
    {
    }

    /**
     * Record registered-user shipment notification intents for a canonical transition.
     * Guest and other recipient types remain outside this recorder's scope; resolver
     * failures propagate so no row is written with unresolved stable identity.
     * Dedupe is enforced by DB unique (timeline_event_id, channel, recipient_type, recipient_id)
     * and the stable recipient identity unique key.
     */
    public function record(ShipmentStatusChanged $event): void
    {
        if (!$event->timelineEventId) {
            return;
        }

        $userId = $event->order->user_id ?? null;
        if (!$userId) {
            return;
        }

        foreach (['email', 'sms', 'push'] as $channel) {
            $recipient = $this->resolveRecipient('user', (string) $userId);

            try {
                ShipmentNotification::query()->create([
                    'shipment_id' => $event->shipmentId,
                    'timeline_event_id' => $event->timelineEventId,
                    'channel' => $channel,
                    'recipient_type' => 'user',
                    'recipient_id' => (string) $userId,
                    'notification_recipient_id' => $recipient->id,
                    'status' => 'pending',
                    'payload' => [
                        'order_id' => $event->orderId,
                        'old_status' => $event->oldStatus,
                        'new_status' => $event->newStatus,
                        'source' => $event->source,
                    ],
                ]);
            } catch (QueryException $e) {
                if (UniqueViolation::detect($e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function resolveRecipient(string $type, string $key): NotificationRecipient
    {
        return $this->recipientResolver->resolve([
            'recipient_type' => $type,
            'recipient_key' => $key,
            'fingerprint' => hash('sha256', $type . ':' . $key),
            'fingerprint_version' => 'v1',
        ]);
    }
}

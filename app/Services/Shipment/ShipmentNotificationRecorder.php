<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\Shipping\ShipmentNotification;
use Illuminate\Database\QueryException;

class ShipmentNotificationRecorder
{
    /**
     * Record shipment notification intents for a canonical transition.
     * Dedupe is enforced by DB unique (timeline_event_id, channel, recipient_type, recipient_id).
     * Guest recipients are out of scope for #146; only registered order user is recorded.
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
            try {
                ShipmentNotification::query()->create([
                    'shipment_id' => $event->shipmentId,
                    'timeline_event_id' => $event->timelineEventId,
                    'channel' => $channel,
                    'recipient_type' => 'user',
                    'recipient_id' => (string) $userId,
                    'status' => 'pending',
                    'payload' => [
                        'order_id' => $event->orderId,
                        'old_status' => $event->oldStatus,
                        'new_status' => $event->newStatus,
                        'source' => $event->source,
                    ],
                ]);
            } catch (QueryException $e) {
                if ($this->isDuplicateKey($e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062;
    }
}

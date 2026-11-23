<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentManualCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentManualCorrectionService
{
    public function __construct(private readonly ShipmentService $shipments)
    {
    }

    public function correct(int $shipmentId, string $newStatus, string $reason, int $actorId): ShipmentManualCorrection
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Reason is required.']);
        }

        return DB::transaction(function () use ($shipmentId, $newStatus, $reason, $actorId): ShipmentManualCorrection {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);
            $oldStatus = (string) $shipment->status;

            $this->shipments->updateStatus($shipmentId, $newStatus, $reason, [
                'source' => 'manual_correction',
                'actor_id' => $actorId,
                'actor_type' => 'admin',
            ]);

            $timeline = OrderTrackingEvent::query()
                ->where('order_id', $shipment->order_id)
                ->where('event_type', 'shipment.status_changed')
                ->where('old_status', $oldStatus)
                ->where('new_status', $newStatus)
                ->where('source', 'manual_correction')
                ->latest('id')
                ->first();

            return ShipmentManualCorrection::create([
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'actor_id' => $actorId,
                'timeline_event_id' => $timeline?->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'metadata' => [
                    'source' => 'manual_correction',
                ],
            ]);
        });
    }
}

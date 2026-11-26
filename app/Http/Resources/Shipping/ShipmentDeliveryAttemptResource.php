<?php

declare(strict_types=1);

namespace App\Http\Resources\Shipping;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentDeliveryAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_assignment_id' => $this->shipment_assignment_id,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status,
            'attempted_at' => optional($this->attempted_at)->toISOString(),
            'failure_reason' => $this->failure_reason,
            'metadata' => $this->metadata,
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}

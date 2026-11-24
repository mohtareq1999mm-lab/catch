<?php

declare(strict_types=1);

namespace App\Http\Resources\Shipping;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentPodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_assignment_id' => $this->shipment_assignment_id,
            'shipment_delivery_attempt_id' => $this->shipment_delivery_attempt_id,
            'status' => $this->status,
            'recipient_name' => $this->recipient_name,
            'captured_at' => optional($this->captured_at)->toISOString(),
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}

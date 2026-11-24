<?php

declare(strict_types=1);

namespace App\Http\Resources\Shipping;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'delivery_representative_id' => $this->delivery_representative_id,
            'status' => $this->status,
            'assigned_at' => optional($this->assigned_at)->toISOString(),
            'unassigned_at' => optional($this->unassigned_at)->toISOString(),
            'notes' => $this->notes,
            'shipment' => $this->whenLoaded('shipment', fn () => [
                'id' => $this->shipment?->id,
                'status' => $this->shipment?->status,
                'tracking_number' => $this->shipment?->tracking_number,
                'order_id' => $this->shipment?->order_id,
            ]),
        ];
    }
}

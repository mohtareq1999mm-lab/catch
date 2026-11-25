<?php

declare(strict_types=1);

namespace App\Http\Resources\Tracking;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-shipment detail for authenticated owners and valid guest-token holders
 * only. Internal/provider identifiers, payloads, exceptions, settlement and
 * representative data are intentionally absent.
 */
final class CustomerShipmentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'estimated_delivery_at' => $this->estimated_delivery_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Tracking;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public order-number lookup serializer. Explicit allowlist: no internal id,
 * provider reference, item, price, settlement, exception or payload field can
 * reach the unauthenticated surface.
 */
final class PublicTrackingAggregateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{order_number:string,status:string,fulfillment_status:?string,created_at:?string,aggregate:array} $tracking */
        $tracking = $this->resource;
        $aggregate = $tracking['aggregate'];

        return [
            'order_number' => $tracking['order_number'],
            'status' => $aggregate['status'],
            'fulfillment_status' => $tracking['fulfillment_status'],
            'created_at' => $tracking['created_at'],
            'aggregate' => [
                'status' => $aggregate['status'],
                'eta' => $aggregate['eta']?->toIso8601String(),
                'delivered_at' => $aggregate['delivered_at']?->toIso8601String(),
                'has_shipments' => $aggregate['has_shipments'],
            ],
        ];
    }
}

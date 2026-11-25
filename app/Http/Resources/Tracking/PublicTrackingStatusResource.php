<?php

declare(strict_types=1);

namespace App\Http\Resources\Tracking;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Marvel\Database\Models\Order;

/** Presentational aggregate status; no payment, financial or actor payload. */
final class PublicTrackingStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{status:string,eta:?\Carbon\CarbonInterface,delivered_at:?\Carbon\CarbonInterface,has_shipments:bool} $aggregate */
        $aggregate = $this->resource;
        $status = $aggregate['status'];

        return [
            'status' => $status,
            'label' => $this->label($status),
            'description' => $this->description($status),
            'icon' => $this->icon($status),
            'color' => $this->color($status),
            'progress_percentage' => $this->progress($status),
        ];
    }

    private function label(string $status): string
    {
        return match ($status) {
            Order::ORDER_STATUS_PENDING => __('tracking.order.created'),
            Order::ORDER_STATUS_PROCESSING => __('tracking.order.processing'),
            Order::ORDER_STATUS_COMPLETED => __('tracking.order.completed'),
            Order::ORDER_STATUS_CANCELLED => __('tracking.order.cancelled'),
            'delivered', 'returned' => __('tracking.shipment.' . $status),
            default => \Illuminate\Support\Facades\Lang::has('tracking.shipment.' . $status)
                ? __('tracking.shipment.' . $status)
                : $status,
        };
    }

    private function description(string $status): string
    {
        $key = match ($status) {
            Order::ORDER_STATUS_CANCELLED => 'tracking.order.cancelled_description',
            Order::ORDER_STATUS_COMPLETED => 'tracking.order.completed_description',
            'delivered' => 'tracking.fulfillment.delivered_description',
            default => 'tracking.shipment.' . $status . '_description',
        };

        if (!\Illuminate\Support\Facades\Lang::has($key)) {
            return '';
        }

        $description = (string) __($key);

        // Interpolated copy carries amounts / cancellation codes. Never render
        // it on the unauthenticated surface.
        return preg_match('/:[a-zA-Z_]+/', $description) ? '' : $description;
    }

    private function icon(string $status): string
    {
        return match ($status) {
            'cancelled' => 'x-circle',
            'delivered' => 'truck',
            'failed_delivery' => 'alert-circle',
            'returned' => 'rotate-ccw',
            'pending' => 'clock',
            default => 'package',
        };
    }

    private function color(string $status): string
    {
        return match ($status) {
            'cancelled' => '#ef4444',
            'delivered' => '#059669',
            'failed_delivery' => '#dc2626',
            'returned' => '#f97316',
            default => '#3b82f6',
        };
    }

    private function progress(string $status): int
    {
        return match ($status) {
            'delivered' => 100,
            'out_for_delivery' => 75,
            'in_transit' => 60,
            'picked_up' => 50,
            'label_created' => 40,
            'processing' => 40,
            'completed' => 90,
            'pending' => 10,
            default => 0,
        };
    }
}

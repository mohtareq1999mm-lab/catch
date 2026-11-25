<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Collection;
use Marvel\Database\Models\Order;

/**
 * Round 12 customer-tracking aggregate.
 *
 * Read-only projection of the shipment rows. It never writes the order,
 * never invents a delivery date, and never selects provider/internal payload
 * columns. Public callers receive the aggregate; per-shipment rows are only
 * passed to Resources used by owner/guest-token authorised surfaces.
 */
final class TrackingAggregateService
{
    /** Highest precedence first (Round 12 §21). */
    private const OUTSTANDING_PRECEDENCE = [
        'failed_delivery',
        'delayed',
        'out_for_delivery',
        'in_transit',
        'picked_up',
        'label_created',
        'pending',
    ];

    /**
     * @return array{status:string,eta:?\Carbon\CarbonInterface,delivered_at:?\Carbon\CarbonInterface,has_shipments:bool}
     */
    public function forOrder(Order $order): array    {
        $shipments = $this->shipmentsFor($order);

        if ($shipments->isEmpty()) {
            // Legacy orders without shipment data keep their stored evidence.
            // created_at is never used to manufacture an ETA.
            return [
                'status' => (string) $order->status,
                'eta' => $order->estimated_delivery_at,
                'delivered_at' => $order->status === Order::ORDER_STATUS_DELIVERED
                    ? $order->actual_delivery_at
                    : null,
                'has_shipments' => false,
            ];
        }

        $nonCancelled = $shipments->where('status', '!=', 'cancelled');

        if ($nonCancelled->isEmpty()) {
            return $this->terminalResult('cancelled', null);
        }

        $outstanding = $nonCancelled->filter(
            fn (Shipment $shipment) => !in_array($shipment->status, ['delivered', 'returned'], true)
        );

        if ($outstanding->isEmpty()) {
            if ($nonCancelled->contains('status', 'returned')) {
                // Returned is terminal. Use genuine shipment-side evidence:
                // a returned delivery timestamp when present, otherwise the
                // shipment's own mutation time. Never the stale order mirror.
                $returnedAt = $nonCancelled
                    ->where('status', 'returned')
                    ->pluck('delivered_at')
                    ->filter()
                    ->max()
                    ?? $nonCancelled->max('updated_at');

                return $this->terminalResult('returned', $returnedAt);
            }

            $deliveredAt = $nonCancelled
                ->where('status', 'delivered')
                ->pluck('delivered_at')
                ->filter()
                ->max();

            return $this->terminalResult('delivered', $deliveredAt);
        }

        $status = 'pending';
        foreach (self::OUTSTANDING_PRECEDENCE as $candidate) {
            if ($outstanding->contains('status', $candidate)) {
                $status = $candidate;
                break;
            }
        }

        return [
            'status' => $status,
            'eta' => $this->eta($order, $outstanding, $status),
            'delivered_at' => null,
            'has_shipments' => true,
        ];
    }

    /**
     * Deterministic, whitelisted shipment rows for owner/token detail only.
     */
    public function shipmentsFor(Order $order): Collection
    {
        return Shipment::query()
            ->where('order_id', $order->id)
            ->select(['id', 'status', 'shipped_at', 'estimated_delivery_at', 'delivered_at', 'cancelled_at', 'updated_at'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{delivered_at:?string,message:string}|array{estimated_date:string,estimated_date_formatted:string,days_remaining:int,message:string}|null
     */
    public function estimatedDelivery(array $aggregate): ?array
    {
        if ($aggregate['status'] === 'cancelled' || $aggregate['status'] === 'returned') {
            return null;
        }

        if ($aggregate['status'] === 'delivered' && $aggregate['delivered_at'] !== null) {
            return [
                'delivered_at' => $aggregate['delivered_at']->toIso8601String(),
                'message' => __('tracking.delivery.delivered'),
            ];
        }

        $eta = $aggregate['eta'];
        if ($eta === null) {
            return null;
        }

        $daysRemaining = (int) max(0, now()->diffInDays($eta, false));

        return [
            'estimated_date' => $eta->toIso8601String(),
            'estimated_date_formatted' => $eta->format('Y-m-d'),
            'days_remaining' => $daysRemaining,
            'message' => $eta->isPast()
                ? __('tracking.delivery.overdue')
                : __('tracking.delivery.estimated_on', ['date' => $eta->format('Y-m-d')]),
        ];
    }

    /**
     * @return array{status:string,eta:?\Carbon\CarbonInterface,delivered_at:?\Carbon\CarbonInterface,has_shipments:bool}
     */
    private function result(string $status): array
    {
        return [
            'status' => $status,
            'eta' => null,
            'delivered_at' => null,
            'has_shipments' => true,
        ];
    }

    /**
     * @param ?\Carbon\CarbonInterface $terminalAt
     * @return array{status:string,eta:?\Carbon\CarbonInterface,delivered_at:?\Carbon\CarbonInterface,has_shipments:bool}
     */
    private function terminalResult(string $status, ?\Carbon\CarbonInterface $terminalAt): array
    {
        return [
            'status' => $status,
            'eta' => null,
            'delivered_at' => $terminalAt,
            'has_shipments' => true,
        ];
    }

    /**
     * An outstanding ETA is only trustworthy when every outstanding shipment
     * has one. A failed_delivery ETA must be newer than the failure event.
     */
    private function eta(Order $order, Collection $outstanding, string $status): ?\Carbon\CarbonInterface
    {
        if ($outstanding->contains(fn (Shipment $shipment) => $shipment->estimated_delivery_at === null)) {
            return null;
        }

        $eta = $outstanding->pluck('estimated_delivery_at')->max();
        if ($eta === null || $status !== 'failed_delivery') {
            return $eta;
        }

        $failureAt = \App\Models\OrderTrackingEvent::query()
            ->forOrder($order->id)
            ->where('event_type', 'shipment.status_changed')
            ->where('new_status', 'failed_delivery')
            ->max('event_timestamp');

        return $failureAt !== null && $eta->lessThanOrEqualTo($failureAt) ? null : $eta;
    }
}

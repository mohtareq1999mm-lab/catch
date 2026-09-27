<?php

namespace App\Services\OrderFlow;

use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;

/**
 * Single authority for the configurable Order Status / Shipping Flow system.
 *
 * Mental model:
 *   shipping_type  ->  flow  ->  ordered statuses  ->  order follows it
 *
 * - Local shipping is the default (null/empty input normalizes to local).
 * - Availability is fail-closed: a shipping type is available only when an
 *   ACTIVE flow exists for it.
 * - Transitions are linear: only the immediate successor in the flow is
 *   allowed for logistics steps. Financial milestones (completed/cancelled)
 *   keep their legacy semantics via union with the legacy transition map.
 * - orders.status remains the backward-compatible mirror of current_status_id.
 */
class OrderFlowService
{
    public const SHIPPING_LOCAL = 'local';

    public const SHIPPING_INTERNATIONAL = 'international';

    /** Shipping types the checkout accepts (availability still needs an active flow). */
    public const SUPPORTED_SHIPPING_TYPES = [
        self::SHIPPING_LOCAL,
        self::SHIPPING_INTERNATIONAL,
    ];

    /**
     * All codes orders.status may hold. MUST stay in sync with the widened
     * orders.status ENUM (see 2026_09_28_000002 + 2026_09_29_000001): every
     * code mirrorable into orders.status must exist in the ENUM.
     */
    public const ALL_STATUS_CODES = [
        'pending',
        'processing',
        'packed',
        'shipped',
        'in_transit',
        'arrived_at_destination_country',
        'customs_clearance',
        'customs_hold',
        'customs_cleared',
        'local_carrier',
        'out_for_delivery',
        'delivered',
        'failed_delivery',
        'returned',
        'completed',
        'cancelled',
        // Global vocabulary: ORDER-compatible display/planning steps that are
        // executable only inside custom flows (not in the seeded flows, so
        // the proven pending→processing→completed milestone chain is kept).
        'confirmed',
        'ready_to_ship',
        'ready_for_pickup',
        'picked_up',
        'export_processing',
        'import_processing',
    ];

    /**
     * Global catalog seed. Single source of truth for the migration seeder.
     *
     * @return array<int, array{code: string, name: string, description: string, is_active: bool}>
     */
    public static function catalogSeed(): array
    {
        return [
            ['code' => 'pending', 'name' => 'Pending', 'description' => 'Order created, awaiting processing', 'is_active' => true],
            ['code' => 'processing', 'name' => 'Processing', 'description' => 'Order is being prepared', 'is_active' => true],
            ['code' => 'packed', 'name' => 'Packed', 'description' => 'Order packed and ready to ship', 'is_active' => true],
            ['code' => 'shipped', 'name' => 'Shipped', 'description' => 'Order handed to the carrier', 'is_active' => true],
            ['code' => 'in_transit', 'name' => 'In Transit', 'description' => 'Shipment moving between hubs', 'is_active' => true],
            ['code' => 'arrived_at_destination_country', 'name' => 'Arrived at Destination Country', 'description' => 'International shipment arrived in the destination country', 'is_active' => true],
            ['code' => 'customs_clearance', 'name' => 'Customs Clearance', 'description' => 'Shipment under customs inspection', 'is_active' => true],
            ['code' => 'customs_hold', 'name' => 'Customs Hold', 'description' => 'Customs held the shipment for additional review (custom flows only)', 'is_active' => true],
            ['code' => 'customs_cleared', 'name' => 'Customs Cleared', 'description' => 'Customs released the shipment', 'is_active' => true],
            ['code' => 'export_processing', 'name' => 'Export Processing', 'description' => 'Shipment prepared for export (custom flows only)', 'is_active' => true],
            ['code' => 'import_processing', 'name' => 'Import Processing', 'description' => 'Shipment processed on import (custom flows only)', 'is_active' => true],
            ['code' => 'local_carrier', 'name' => 'Local Carrier', 'description' => 'Handed to the local last-mile carrier', 'is_active' => true],
            ['code' => 'out_for_delivery', 'name' => 'Out for Delivery', 'description' => 'Courier is delivering the order', 'is_active' => true],
            ['code' => 'delivered', 'name' => 'Delivered', 'description' => 'Order delivered to the customer', 'is_active' => true],
            ['code' => 'failed_delivery', 'name' => 'Failed Delivery', 'description' => 'A delivery attempt failed', 'is_active' => true],
            ['code' => 'returned', 'name' => 'Returned', 'description' => 'Order returned to sender', 'is_active' => true],
            ['code' => 'completed', 'name' => 'Completed', 'description' => 'Payment confirmed / order fulfilled', 'is_active' => true],
            ['code' => 'cancelled', 'name' => 'Cancelled', 'description' => 'Order cancelled', 'is_active' => true],
            // Global vocabulary (ORDER-compatible, custom-flows-only): kept
            // out of the seeded flows to preserve the proven milestone chain.
            ['code' => 'confirmed', 'name' => 'Confirmed', 'description' => 'Order confirmed, awaiting preparation (custom flows only)', 'is_active' => true],
            ['code' => 'ready_to_ship', 'name' => 'Ready to Ship', 'description' => 'Order ready to hand to the carrier (custom flows only)', 'is_active' => true],
            ['code' => 'ready_for_pickup', 'name' => 'Ready for Pickup', 'description' => 'Order ready for customer pickup (custom flows only)', 'is_active' => true],
            ['code' => 'picked_up', 'name' => 'Picked Up', 'description' => 'Shipment picked up from origin (custom flows only)', 'is_active' => true],
        ];
    }

    /**
     * Flow seed. Single source of truth for the migration seeder.
     *
     * @return array<int, array{code: string, name: string, shipping_type: string, is_default: bool, is_active: bool, statuses: array<int, string>}>
     */
    public static function flowsSeed(): array
    {
        return [
            [
                'code' => 'local',
                'name' => 'Local Flow',
                'shipping_type' => self::SHIPPING_LOCAL,
                'is_default' => true,
                'is_active' => true,
                'statuses' => ['pending', 'processing', 'packed', 'shipped', 'out_for_delivery', 'delivered'],
            ],
            [
                'code' => 'international',
                'name' => 'International Flow',
                'shipping_type' => self::SHIPPING_INTERNATIONAL,
                'is_default' => true,
                'is_active' => true,
                'statuses' => [
                    'pending',
                    'processing',
                    'packed',
                    'shipped',
                    'in_transit',
                    'arrived_at_destination_country',
                    'customs_clearance',
                    'customs_cleared',
                    'local_carrier',
                    'out_for_delivery',
                    'delivered',
                ],
            ],
        ];
    }

    public static function normalizeShippingType(?string $shippingType): string
    {
        $type = strtolower(trim((string) $shippingType));

        return $type === '' ? self::SHIPPING_LOCAL : $type;
    }

    public static function tablesAvailable(): bool
    {
        try {
            return Schema::hasTable('order_statuses')
                && Schema::hasTable('order_flows')
                && Schema::hasTable('order_flow_statuses');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function orderFlowColumnsAvailable(): bool
    {
        try {
            return self::tablesAvailable()
                && Schema::hasColumn('orders', 'shipping_type')
                && Schema::hasColumn('orders', 'flow_id')
                && Schema::hasColumn('orders', 'current_status_id');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fail-closed availability: only an ACTIVE flow makes a type available.
     *
     * @throws \InvalidArgumentException
     */
    public function resolveFlowForShippingType(?string $shippingType): OrderFlow
    {
        $type = self::normalizeShippingType($shippingType);

        if (!in_array($type, self::SUPPORTED_SHIPPING_TYPES, true)) {
            throw new \InvalidArgumentException(__('checkout.shipping_type_unsupported'));
        }

        $flow = OrderFlow::query()
            ->where('shipping_type', $type)
            ->where('is_active', true)
            ->first();

        if (!$flow) {
            throw new \InvalidArgumentException(__('checkout.shipping_type_unavailable'));
        }

        return $flow;
    }

    /** @return Collection<int, OrderStatus> ordered by sort_order */
    public function orderedStatuses(OrderFlow $flow): Collection
    {
        return $flow->statuses()->orderBy('order_flow_statuses.sort_order')->get();
    }

    public function firstStatus(OrderFlow $flow): OrderStatus
    {
        $first = $this->orderedStatuses($flow)->first();

        if (!$first) {
            throw new \RuntimeException(__('checkout.flow_not_configured'));
        }

        return $first;
    }

    public function nextStatus(OrderFlow $flow, string $fromCode): ?OrderStatus
    {
        $ordered = $this->orderedStatuses($flow)->values();
        $index = $ordered->search(fn (OrderStatus $s) => $s->code === $fromCode);

        if ($index === false) {
            return null;
        }

        return $ordered->get($index + 1);
    }

    public function statusIdForCode(string $code): ?int
    {
        return OrderStatus::query()->where('code', $code)->value('id');
    }

    /**
     * Assign shipping type + flow + first status to a new order.
     * The ONLY place that writes the three flow columns on creation.
     */
    public function assignFlowToOrder(Order $order, ?string $shippingType): Order
    {
        $flow = $this->resolveFlowForShippingType($shippingType);
        $first = $this->firstStatus($flow);

        $order->forceFill([
            'shipping_type' => $flow->shipping_type,
            'flow_id' => $flow->id,
            'current_status_id' => $first->id,
            'status' => $first->code,
        ])->save();

        return $order->fresh();
    }

    /**
     * Flow-side transition check. Union semantics with the legacy map:
     * anything the legacy map allows stays allowed; logistics steps
     * additionally require immediate-succession in the order's flow.
     *
     * Supervised exits (mirror the shipment machine, keep flows linear):
     * - completed: payment milestone, reachable from any non-terminal flow
     *   status (COD paid on delivery completes from packed/shipped/...).
     * - cancelled: universal exit EXCEPT from completed (legacy forbids
     *   cancelling paid orders; refunds keep status completed), delivered
     *   and cancelled (terminal).
     * - failed_delivery / returned: carrier exits around out_for_delivery.
     * Transitions INTO an inactive status are fail-closed (admin must
     * reorder/reactivate; the path never silently re-routes).
     */
    public function allowsFlowTransition(?Order $order, string $from, string $to): bool
    {
        if (!self::orderFlowColumnsAvailable() || !$order || !$order->flow_id) {
            return false;
        }

        if ($from === $to) {
            return true;
        }

        if (in_array($from, ['delivered', 'cancelled'], true)) {
            return false;
        }

        if ($to === 'cancelled') {
            return $from !== 'completed';
        }

        if ($to === 'completed') {
            return true;
        }

        if ($to === 'failed_delivery') {
            return $from === 'out_for_delivery';
        }

        if ($to === 'returned') {
            return in_array($from, ['failed_delivery', 'out_for_delivery'], true);
        }

        $flow = OrderFlow::query()->find($order->flow_id);
        if (!$flow) {
            return false;
        }

        $next = $this->nextStatus($flow, $from);

        return $next !== null && $next->code === $to && (bool) $next->is_active;
    }

    /**
     * Validate an admin-supplied ordered status id list for a flow.
     *
     * @param  array<int, int>  $statusIds
     * @return Collection<int, OrderStatus>
     *
     * @throws \InvalidArgumentException
     */
    public function validateFlowStatuses(array $statusIds): Collection
    {
        if (empty($statusIds)) {
            throw new \InvalidArgumentException(__('checkout.flow_statuses_required'));
        }

        $ids = array_map('intval', array_values($statusIds));
        if (count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException(__('checkout.flow_statuses_duplicate'));
        }

        $statuses = OrderStatus::query()->whereIn('id', $ids)->get()->keyBy('id');

        if ($statuses->count() !== count($ids)) {
            throw new \InvalidArgumentException(__('checkout.flow_statuses_unknown'));
        }

        foreach ($ids as $id) {
            if (!$statuses[$id]->is_active) {
                throw new \InvalidArgumentException(
                    __('checkout.flow_status_inactive', ['code' => $statuses[$id]->code])
                );
            }
        }

        return collect($ids)->map(fn (int $id) => $statuses[$id]);
    }

    public function flowMetadata(?Order $order, string $from, string $to): array
    {
        if (!$order || !$order->flow_id) {
            return ['from_code' => $from, 'to_code' => $to];
        }

        $flow = OrderFlow::query()->find($order->flow_id);

        return [
            'flow_id' => $order->flow_id,
            'flow_code' => $flow?->code,
            'from_code' => $from,
            'to_code' => $to,
        ];
    }
}

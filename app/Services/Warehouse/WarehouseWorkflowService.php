<?php

namespace App\Services\Warehouse;

use App\Models\Fulfillment\Fulfillment;
use App\Models\WarehouseWorkflowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse Workflow configuration authority (Q-02 global, Q-03 validated
 * skipping, Q-04 version pinning, Q-05 shipping gate, Q-07 mandatory
 * Order Received, Q-01 QC excluded).
 *
 * - No competing order state machine: all order writes stay in
 *   OrderService::changeOrderStatus via allowsFlowTransition.
 * - All fulfillment writes stay in FulfillmentTransition.
 * - Shipment creation gate only blocks new shipments; existing shipments
 *   keep their lifecycle; completion still requires shipment + delivery
 *   evidence via ShipmentService::maybeCompleteOrder.
 */
class WarehouseWorkflowService
{
    /**
     * Safe legacy defaults when nothing published yet.
     * All stages enabled = current official lifecycle.
     */
    public function defaults(): array
    {
        return [
            'picking_enabled' => true,
            'packing_enabled' => true,
            'shipping_enabled' => true,
            'order_received_enabled' => true,
        ];
    }

    public function current(): ?WarehouseWorkflowVersion
    {
        // Pre-migration (table missing) → legacy. Infra errors propagate
        // fail-closed (never silently fall back to legacy-allowed).
        if (! Schema::hasTable('warehouse_workflow_versions')) {
            return null;
        }

        return WarehouseWorkflowVersion::current();
    }

    public function currentOrDefaults(): array
    {
        $current = $this->current();

        if (! $current) {
            return $this->defaults() + ['version_id' => null, 'legacy' => true];
        }

        return [
            'picking_enabled' => (bool) $current->picking_enabled,
            'packing_enabled' => (bool) $current->packing_enabled,
            'shipping_enabled' => (bool) $current->shipping_enabled,
            'order_received_enabled' => (bool) $current->order_received_enabled,
            'version_id' => (int) $current->id,
            'legacy' => false,
        ];
    }

    public function history(int $perPage = 15)
    {
        return WarehouseWorkflowVersion::query()
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, 100)));
    }

    /**
     * Publish a new immutable version. Append-only so concurrent admins
     * never lose updates (both rows persist, current = latest id).
     *
     * @throws \RuntimeException on unsupported combination (422 mapped in controller).
     */
    public function publish(array $data, mixed $actorId = null): WarehouseWorkflowVersion
    {
        // Q-01: Quality Check is not an activatable stage in v1.
        if (array_key_exists('quality_check_enabled', $data)) {
            throw new \RuntimeException(__('wms.workflow.quality_check_not_supported'));
        }

        $picking = array_key_exists('picking_enabled', $data) ? (bool) $data['picking_enabled'] : true;
        $packing = array_key_exists('packing_enabled', $data) ? (bool) $data['packing_enabled'] : true;
        $shipping = array_key_exists('shipping_enabled', $data) ? (bool) $data['shipping_enabled'] : true;
        $orderReceived = array_key_exists('order_received_enabled', $data) ? (bool) $data['order_received_enabled'] : true;

        // Q-07: Order Received mandatory, not user-disableable.
        if (! $orderReceived) {
            throw new \RuntimeException(__('wms.workflow.order_received_mandatory'));
        }

        // Q-03 stage-skipping contract against Fulfillment::allowedTransitions:
        // pending → {picking, cancelled} only, so picking has no safe skip.
        if (! $picking) {
            throw new \RuntimeException(__('wms.workflow.picking_cannot_be_disabled'));
        }

        // packing → ready_to_ship is mandatory; no picked→ready_to_ship edge.
        if (! $packing) {
            throw new \RuntimeException(__('wms.workflow.packing_cannot_be_disabled'));
        }

        // shipping=false IS allowed: it blocks new shipments without
        // fabricating completion (physical orders stay incomplete until
        // shipment + delivery evidence exists via the official authorities).

        return DB::transaction(function () use ($picking, $packing, $shipping, $actorId, $data) {
            $version = WarehouseWorkflowVersion::create([
                'picking_enabled' => $picking,
                'packing_enabled' => $packing,
                'shipping_enabled' => $shipping,
                'order_received_enabled' => true,
                'created_by' => $actorId,
                'published_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            Log::info('Warehouse workflow published', [
                'version_id' => $version->id,
                'picking_enabled' => $picking,
                'packing_enabled' => $packing,
                'shipping_enabled' => $shipping,
                'actor_id' => $actorId,
            ]);

            return $version->fresh();
        });
    }

    /**
     * Q-04: version pinned at fulfillment creation. NULL = legacy (no
     * version published yet) → safe legacy behavior (all enabled).
     */
    public function resolvePinnedVersionId(): ?int
    {
        return $this->current()?->id;
    }

    public function versionForFulfillment(Fulfillment $fulfillment): ?WarehouseWorkflowVersion
    {
        if (! Schema::hasColumn('fulfillments', 'warehouse_workflow_version_id')) {
            return null;
        }

        $id = $fulfillment->warehouse_workflow_version_id ?? null;
        if ($id === null) {
            return null;
        }

        return WarehouseWorkflowVersion::query()->find($id);
    }

    public function isShippingEnabledForFulfillment(Fulfillment $fulfillment): bool
    {
        $version = $this->versionForFulfillment($fulfillment);

        // Legacy (no pin) preserves safe legacy behavior: shipping enabled.
        if (! $version) {
            // Pinned but missing = data corruption → fail-closed (block).
            if (($fulfillment->warehouse_workflow_version_id ?? null) !== null) {
                throw new \RuntimeException(
                    __('wms.workflow.invalid_version', ['id' => $fulfillment->warehouse_workflow_version_id])
                );
            }

            return true;
        }

        return (bool) $version->shipping_enabled;
    }

    /**
     * Q-05 gate: called inside ShipmentService transactions AFTER locking
     * the fulfillment, BEFORE any shipment mutation. Rejection leaves state
     * consistent (no partial writes).
     *
     * @throws \RuntimeException when shipping disabled for pinned version.
     */
    public function assertShipmentAllowedFor(Fulfillment $fulfillment): void
    {
        if (! $this->isShippingEnabledForFulfillment($fulfillment)) {
            Log::warning('Shipment creation blocked: shipping disabled by workflow', [
                'fulfillment_id' => $fulfillment->id,
                'workflow_version_id' => $fulfillment->warehouse_workflow_version_id,
            ]);

            throw new \RuntimeException(__('wms.workflow.shipping_disabled'));
        }
    }
}

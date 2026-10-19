<?php

namespace App\Services\Warehouse;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Phase 13: warehouse authorization boundary.
 *
 * - Every WMS operation requires its specific permission (guard: api).
 * - Non-global actors are confined to their home warehouse
 *   (users.warehouse_id). Only manage-warehouse crosses warehouses.
 * - Financial separation is structural: picker/packer roles are never
 *   granted financial permissions (see PermissionSeeder::seedWarehouseRoles).
 *   Controllers map denial to 404 (anti-enumeration) or 409 (claim conflict).
 */
class WarehouseAccess
{
    public function warehouseIdFor(mixed $user): ?int
    {
        $id = $user?->warehouse_id ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * True when the actor may read shipments ACROSS warehouses:
     * view-all-shipments holders (auditors) or manage-warehouse holders.
     *
     * Fail-soft PER permission so a missing permission row
     * (PermissionDoesNotExist) cannot short-circuit the sibling check and
     * silently demote a global reader to warehouse-scoped visibility —
     * the same boundary rule the substitute grant in allows() follows.
     */
    public function isGlobalShipmentReader(mixed $user): bool
    {
        if (!$user || !method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        foreach (['view-all-shipments', 'manage-warehouse'] as $permission) {
            try {
                if ($user->hasPermissionTo($permission, 'api')) {
                    return true;
                }
            } catch (\Throwable) {
                // Missing/unavailable row — fail-soft, try the next grant.
            }
        }

        return false;
    }

    public function allows(mixed $user, ?int $warehouseId, string $permission): bool
    {
        if (!$user || !method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        // view-all-shipments substitutes the base shipment-view permission
        // across ALL warehouses (read-only shipment surface; both shipment
        // read route pipes accept either). Checked BEFORE the base gate:
        // after it, holders without view-shipment (the exact intended
        // audience: auditors) could never reach it. Own fail-soft catch:
        // PermissionDoesNotExist falls through to the base gate, never
        // denies inside the boundary. Scope note: this grant is
        // warehouse-independent by design (global reader), so it may accept
        // where the scoped-actor rules below (home/assigned) would deny.
        try {
            if ($permission === 'view-shipment' && $user->hasPermissionTo('view-all-shipments', 'api')) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to the base gate (fail-closed).
        }

        $hasPerm = false;
        try {
            $hasPerm = $user->hasPermissionTo($permission, 'api');
        } catch (\Throwable) {
            $hasPerm = false;
        }

        if (!$hasPerm) {
            $legacyMap = [
                'batch.assign' => 'batch.operate',
                'batch.start' => 'batch.operate',
                'batch.refresh-progress' => 'batch.operate',
                'batch.complete' => 'batch.operate',
                'batch.confirm-location' => 'batch.operate',
                'batch.create' => 'batch.manage',
                'batch.cancel' => 'batch.manage',
                'batch.retry' => 'batch.manage',
                'batch.index' => 'view-fulfillment',
                'batch.show' => 'view-fulfillment',
                'batch.pending-fulfillments' => 'view-fulfillment',
                'batch.next-task' => 'view-fulfillment',
                'packing-tasks.bulk-create' => 'packing-execute',
                'packing-tasks.create' => 'packing-execute',
                'packing-tasks.assign' => 'packing-execute',
                'packing-tasks.start' => 'packing-execute',
                'packing-tasks.pack' => 'packing.complete',
                'packing-tasks.verify' => 'packing.complete',
                'packing-tasks.cancel' => 'manage-fulfillment',
                'packing-tasks.index' => 'view-fulfillment',
                'packing-tasks.show' => 'view-fulfillment',
                'packing-tasks.stations' => 'view-fulfillment',
                'packages.index' => 'view-fulfillment',
                'packages.show' => 'view-fulfillment',
                'packages.create' => 'packing-execute',
                'packages.add-item' => 'packing-execute',
                'packages.seal' => 'packing-execute',
                'packages.void' => 'manage-fulfillment',
                'packing-stations.index' => 'view-fulfillment',
                'packing-stations.show' => 'view-fulfillment',
                'packing-stations.create' => 'manage-warehouse',
                'packing-stations.update' => 'manage-warehouse',
                'packing-stations.delete' => 'manage-warehouse',
                'fulfillments.index' => 'view-fulfillment',
                'fulfillments.show' => 'view-fulfillment',
                'fulfillments.assign' => 'manage-fulfillment',
                'fulfillments.complete-picking' => 'manage-fulfillment',
                'fulfillments.create-tasks' => 'picking-execute',
                'fulfillments.create-packing-tasks' => 'packing-execute',
                'fulfillments.create-packing-task' => 'packing-execute',
                'fulfillment-items.assign-placement' => 'manage-fulfillment',
                'picking-tasks.index' => 'picking-execute',
                'picking-tasks.show' => 'picking-execute',
                'picking-tasks.claim' => 'picking-execute',
                'picking-tasks.release' => 'picking-execute',
                'picking-tasks.confirm' => 'picking-execute',
                'picking-tasks.record-pick' => 'picking-execute',
                'picking-tasks.skip' => 'picking-execute',
                'picking-tasks.reallocate' => 'picking-execute',
                'warehouses.index' => 'view-warehouse',
                'warehouses.show' => 'view-warehouse',
                'warehouses.create' => 'manage-warehouse',
                'warehouses.update' => 'manage-warehouse',
                'warehouses.set-default' => 'manage-warehouse',
                'warehouses.activate' => 'manage-warehouse',
                'warehouses.deactivate' => 'manage-warehouse',
                'warehouses.delete' => 'manage-warehouse',
                'warehouses.users.view' => 'view-warehouse',
                'warehouses.users.assign' => 'manage-warehouse',
                'warehouses.users.remove-bulk' => 'manage-warehouse',
                'warehouses.users.remove' => 'manage-warehouse',
                'locations.index' => 'view-location',
                'locations.show' => 'view-location',
                'locations.check-occupancy' => 'view-location',
                'locations.create' => 'manage-location',
                'locations.update' => 'manage-location',
                'locations.activate' => 'manage-location',
                'locations.deactivate' => 'manage-location',
                'locations.delete' => 'manage-location',
                'locations.empty-products' => 'manage-location',
                'inventory.receive' => 'inventory-adjust',
                'inventory.transfer' => 'inventory-adjust',
                'inventory.product-placements' => 'view-location',
                'inventory.overview' => 'view-location',
                'inventory.operations.index' => 'view-location',
                'inventory.operations.show' => 'view-location',
                'wms-shipments.index' => 'view-shipment',
                'wms-shipments.show' => 'view-shipment',
                'wms-shipments.create' => 'create-shipment',
                'wms-shipments.bulk-create' => 'create-shipment',
                'wms-shipments.bulk-dispatch' => 'update-shipment',
                'wms-shipments.bulk-deliver' => 'update-shipment',
                'wms-shipments.dispatch' => 'update-shipment',
                'wms-shipments.deliver' => 'update-shipment',
                'wms-shipments.cancel' => 'update-shipment',
                'barcode.scan' => 'view-fulfillment',
            ];

            if (isset($legacyMap[$permission])) {
                try {
                    $hasPerm = $user->hasPermissionTo($legacyMap[$permission], 'api');
                } catch (\Throwable) {
                    $hasPerm = false;
                }
            }
        }

        if (!$hasPerm) {
            return false;
        }

        // Global permission crosses warehouses. The first disjunct is the
        // self-permission case (the gate above already proved the holder);
        // both branches share the same fail-soft catch. Fail-closed: a
        // missing permission row (e.g. minimal test seeders) denies instead
        // of throwing PermissionDoesNotExist out of the authorization
        // boundary.
        try {
            if ($permission === 'manage-warehouse' || $user->hasPermissionTo('manage-warehouse', 'api')) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to the home-warehouse check (fail-closed).
        }

        // Otherwise the operation must be warehouse-scoped to home or assigned warehouses.
        if ($warehouseId === null) {
            return false;
        }

        $home = $this->warehouseIdFor($user);
        if ($home !== null && $home === (int) $warehouseId) {
            return true;
        }

        if (method_exists($user, 'warehouses')) {
            try {
                return $user->warehouses()->where('warehouses.id', (int) $warehouseId)->exists();
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * @throws AuthorizationException
     */
    public function denyUnless(mixed $user, ?int $warehouseId, string $permission): void
    {
        if (!$this->allows($user, $warehouseId, $permission)) {
            throw new AuthorizationException('Forbidden warehouse operation.');
        }
    }
}

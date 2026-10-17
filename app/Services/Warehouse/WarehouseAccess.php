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

        try {
            if (!$user->hasPermissionTo($permission, 'api')) {
                return false;
            }
        } catch (\Throwable) {
            // Fail-closed: a missing permission row (e.g. minimal test
            // seeders) denies instead of throwing PermissionDoesNotExist.
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

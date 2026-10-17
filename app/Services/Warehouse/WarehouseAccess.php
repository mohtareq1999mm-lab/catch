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

    public function allows(mixed $user, ?int $warehouseId, string $permission): bool
    {
        if (!$user || !method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        try {
            if (!$user->hasPermissionTo($permission, 'api')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        // Global permission crosses warehouses. Fail-closed: a missing
        // permission row (e.g. minimal test seeders) denies instead of
        // throwing PermissionDoesNotExist out of the authorization boundary.
        try {
            if ($permission === 'manage-warehouse' || $user->hasPermissionTo('manage-warehouse', 'api')) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to the home-warehouse check (fail-closed).
        }

        // view-all-shipments permission crosses warehouses specifically for shipment views
        try {
            if ($permission === 'view-shipment' && $user->hasPermissionTo('view-all-shipments', 'api')) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through
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

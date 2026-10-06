<?php

namespace App\Services\Warehouse;

use App\Models\Fulfillment\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single authority for warehouse selection/default/deactivation rules (§§8-10,31).
 *
 * - Explicit selection wins; otherwise the ACTIVE default is used.
 * - Only ACTIVE warehouses may back a NEW fulfillment (existing ones continue).
 * - Exactly one default: setDefault() is transactional + row-locked; a
 *   partial-unique DB index (see migration) is the final backstop.
 * - Deactivating the current default is BLOCKED (no auto-replacement).
 */
class WarehouseService
{
    /**
     * Resolve the warehouse for a NEW fulfillment.
     *
     * @throws \RuntimeException when explicit/default is missing or inactive.
     */
    public function resolveForNewFulfillment(?int $warehouseId = null): Warehouse
    {
        if ($warehouseId !== null) {
            // withTrashed: a soft-deleted id must fail with the controlled
            // rule error (not selectable for NEW fulfillment), never 404.
            $warehouse = Warehouse::withTrashed()->whereKey($warehouseId)->firstOrFail();
            if ($warehouse->trashed()) {
                throw new \RuntimeException(
                    "Warehouse #{$warehouse->id} ({$warehouse->code}) is deleted: cannot create new fulfillment"
                );
            }
            $this->assertActive($warehouse);

            return $warehouse;
        }

        // Global SoftDeletes scope excludes trashed rows: a soft-deleted
        // default is never the effective default (exactly-one-ACTIVE-default).
        $warehouse = Warehouse::where('is_default', true)->orderBy('id')->first();
        if (!$warehouse) {
            throw new \RuntimeException('No default warehouse configured');
        }
        $this->assertActive($warehouse);

        return $warehouse;
    }

    /**
     * @throws \RuntimeException when the warehouse is not active.
     */
    public function assertActive(Warehouse $warehouse): void
    {
        if ($warehouse->status !== 'active') {
            throw new \RuntimeException(
                "Warehouse #{$warehouse->id} ({$warehouse->code}) is not active: cannot create new fulfillment"
            );
        }
    }

    /**
     * Transactionally move the default flag. Requires the target to be active.
     * Never auto-selects a replacement; callers choose the new default first.
     *
     * @throws \RuntimeException when target is missing or inactive.
     */
    public function setDefault(int $warehouseId): Warehouse
    {
        return DB::transaction(function () use ($warehouseId) {
            $target = Warehouse::whereKey($warehouseId)->lockForUpdate()->firstOrFail();
            $this->assertActive($target);

            $this->moveDefaultLocked($target);

            Log::info('Warehouse default moved', [
                'warehouse_id' => $target->id,
                'code' => $target->code,
            ]);

            return $target->fresh();
        });
    }

    /**
     * Create a warehouse. Code is globally unique (DB backstop); status
     * defaults to active. is_default=true promotes via the same locked
     * move as setDefault() — never two defaults.
     *
     * @throws \RuntimeException when code is blank/duplicate or a default
     *                           is requested for an inactive warehouse.
     */
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            $code = trim((string) ($data['code'] ?? ''));
            if ($code === '') {
                throw new \RuntimeException('Warehouse code is required');
            }
            if (Warehouse::withTrashed()->where('code', $code)->exists()) {
                throw new \RuntimeException("Warehouse code {$code} already exists");
            }

            $status = $data['status'] ?? 'active';
            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new \RuntimeException("Invalid warehouse status {$status}");
            }

            $warehouse = Warehouse::create([
                'code' => $code,
                'name' => $data['name'] ?? $code,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? null,
                'status' => $status,
                'is_default' => false,
                'metadata' => $data['metadata'] ?? null,
            ]);

            if (!empty($data['is_default'])) {
                $this->assertActive($warehouse);
                $this->moveDefaultLocked($warehouse);
            }

            Log::info('Warehouse created', [
                'warehouse_id' => $warehouse->id,
                'code' => $warehouse->code,
            ]);

            return $warehouse->fresh();
        });
    }

    /**
     * Update warehouse metadata only (name/address/city/country/metadata).
     * Status, code, and default move through their explicit commands.
     */
    public function updateDetails(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data) {
            $locked = Warehouse::whereKey($warehouse->getKey())->lockForUpdate()->firstOrFail();
            $allowed = array_intersect_key(
                $data,
                array_flip(['name', 'address', 'city', 'country', 'metadata'])
            );
            if ($allowed !== []) {
                $locked->update($allowed);
            }

            return $locked->fresh();
        });
    }

    /**
     * Reactivate a warehouse (symmetric counterpart to deactivate()).
     */
    public function activate(int $warehouseId): Warehouse
    {
        return DB::transaction(function () use ($warehouseId) {
            $warehouse = Warehouse::whereKey($warehouseId)->lockForUpdate()->firstOrFail();
            $warehouse->update(['status' => 'active']);

            Log::info('Warehouse activated', [
                'warehouse_id' => $warehouse->id,
                'code' => $warehouse->code,
            ]);

            return $warehouse->fresh();
        });
    }

    /**
     * Soft-delete a warehouse. The model guard blocks deleting the current
     * default — promote another active warehouse first.
     *
     * @throws \RuntimeException when target is the default.
     */
    public function remove(Warehouse $warehouse): void
    {
        $warehouse->delete();
    }

    /**
     * Move the default flag to an already-locked target row. Callers must
     * hold an open transaction with the target locked.
     */
    private function moveDefaultLocked(Warehouse $target): void
    {
        Warehouse::where('is_default', true)
            ->whereKeyNot($target->id)
            ->lockForUpdate()
            ->update(['is_default' => false]);

        $target->update(['is_default' => true]);
    }

    /**
     * Deactivate a warehouse. BLOCKED when it is the current default —
     * admin must first promote another ACTIVE warehouse via setDefault().
     *
     * @throws \RuntimeException when target is the default.
     */
    public function deactivate(int $warehouseId): Warehouse
    {
        return DB::transaction(function () use ($warehouseId) {
            $warehouse = Warehouse::whereKey($warehouseId)->lockForUpdate()->firstOrFail();

            if ((bool) $warehouse->is_default) {
                throw new \RuntimeException(
                    "Cannot deactivate default warehouse #{$warehouse->id} ({$warehouse->code}): promote another active warehouse first"
                );
            }

            $warehouse->update(['status' => 'inactive']);

            Log::warning('Warehouse deactivated', [
                'warehouse_id' => $warehouse->id,
                'code' => $warehouse->code,
            ]);

            return $warehouse->fresh();
        });
    }
}

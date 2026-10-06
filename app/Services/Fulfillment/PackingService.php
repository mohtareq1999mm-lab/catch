<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackageItem;
use App\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PackingService
{
    /**
     * Phase 6 (P6-1/P6-3): a fulfillment may carry at most one OPEN packing
     * task. Terminal states (verified, cancelled) never block a new task.
     */
    private const OPEN_TASK_STATUSES = ['pending', 'assigned', 'packing', 'packed'];

    public function __construct(
        private FulfillmentTransition $transitions,
        private \App\Services\Shipment\ShipmentService $shipments,
    ) {}
    /**
     * Create a packing task from a completed fulfillment.
     * P6-1: at most one OPEN task per fulfillment — the fulfillment row lock
     * serializes concurrent creators; the open-task check runs under that
     * lock so two workers cannot both create.
     */
    public function createPackingTaskFromFulfillment(Fulfillment $fulfillment): PackingTask
    {
        return DB::transaction(function () use ($fulfillment) {
            $locked = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, ['picked', 'packing'], true)) {
                throw new \Exception(
                    "Cannot create packing task from fulfillment in status: {$locked->status}"
                );
            }

            $openExists = PackingTask::where('fulfillment_id', $locked->id)
                ->whereIn('status', self::OPEN_TASK_STATUSES)
                ->lockForUpdate()
                ->exists();

            if ($openExists) {
                throw new \RuntimeException(
                    "Fulfillment #{$locked->id} already has an open packing task"
                );
            }

            $task = PackingTask::create([
                'fulfillment_id' => $locked->id,
                'status' => 'pending',
            ]);

            $this->transitions->transition($locked, 'packing', ['reason' => 'packing_task_created']);

            Log::info('Packing task created', [
                'task_id' => $task->id,
                'fulfillment_id' => $locked->id,
            ]);

            return $task;
        });
    }

    /**
     * Assign task to packing station and user.
     * P6-5A: the station must belong to the fulfillment's warehouse.
     * Lock order (fulfillment → task → station) matches creation/verify.
     */
    public function assignToStation(
        PackingTask $task,
        int $stationId,
        int $userId
    ): PackingTask {
        return DB::transaction(function () use ($task, $stationId, $userId) {
            // fulfillment_id is immutable — safe to read before locking.
            $fulfillment = Fulfillment::whereKey((int) $task->fulfillment_id)->lockForUpdate()->firstOrFail();

            $locked = PackingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, ['pending', 'assigned'], true)) {
                throw new \RuntimeException(
                    "Cannot assign packing task in status: {$locked->status}"
                );
            }

            if ($locked->assigned_to !== null && (int) $locked->assigned_to !== $userId) {
                throw new \RuntimeException(
                    "Packing task #{$locked->id} is already assigned to user {$locked->assigned_to}"
                );
            }

            $station = PackingStation::whereKey($stationId)->lockForUpdate()->firstOrFail();

            if ($station->status !== 'active') {
                throw new \RuntimeException('Packing station is not active');
            }

            if ((int) $station->warehouse_id !== (int) $fulfillment->warehouse_id) {
                throw new \RuntimeException(
                    "Packing station #{$station->id} belongs to a different warehouse than fulfillment #{$fulfillment->id}"
                );
            }

            $locked->update([
                'packing_station_id' => $stationId,
                'assigned_to' => $userId,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);

            Log::info('Packing task assigned', [
                'task_id' => $locked->id,
                'station_id' => $stationId,
                'user_id' => $userId,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Start packing process. Phase 1 (T1): locked assigned → packing transition.
     * P6-5B: the station must STILL be active — it may have been deactivated
     * after assignment.
     */
    public function startPacking(PackingTask $task): PackingTask
    {
        return DB::transaction(function () use ($task) {
            $locked = PackingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'assigned') {
                throw new \RuntimeException(
                    "Cannot start packing task in status: {$locked->status}"
                );
            }

            if ($locked->packing_station_id !== null) {
                $station = PackingStation::whereKey($locked->packing_station_id)->lockForUpdate()->first();

                if ($station === null || $station->status !== 'active') {
                    throw new \RuntimeException(
                        "Cannot start packing task #{$locked->id}: packing station is no longer active"
                    );
                }
            }

            $locked->update([
                'status' => 'packing',
                'started_at' => now(),
            ]);

            Log::info('Packing started', [
                'task_id' => $locked->id,
                'assigned_to' => $locked->assigned_to,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Complete packing with package details.
     * P6-2: the state decision runs against the locked fresh row — a second
     * completion sees `packed` and is refused (no last-writer-wins on
     * weight/dimensions).
     */
    public function completePacking(
        PackingTask $task,
        float $weight,
        array $dimensions,
        ?array $materials = null,
        ?string $notes = null
    ): PackingTask {
        return DB::transaction(function () use ($task, $weight, $dimensions, $materials, $notes) {
            $locked = PackingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'packing') {
                throw new \RuntimeException(
                    "Cannot complete packing task in status: {$locked->status}"
                );
            }

            $locked->update([
                'status' => 'packed',
                'weight' => $weight,
                'dimensions' => $dimensions,
                'package_materials' => $materials,
                'packed_at' => now(),
                'notes' => $notes,
            ]);

            // Fulfillment stays `packing` until verification: `packed` is a
            // task-level state only (Phase 6 ghost-state removal).

            Log::info('Packing completed', [
                'task_id' => $locked->id,
                'fulfillment_id' => $locked->fulfillment_id,
                'weight' => $weight,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Verify packed task.
     * P6-2: locked fresh state. P6-3: no sibling open task AND every picked
     * unit fully packaged (D6-3) — otherwise the fulfillment must not become
     * ready_to_ship. Lock order: task → fulfillment → siblings → packages →
     * items, then the canonical transition (which re-locks the same row).
     */
    public function verifyPacking(PackingTask $task, ?string $notes = null): PackingTask
    {
        return DB::transaction(function () use ($task, $notes) {
            $locked = PackingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'packed') {
                throw new \RuntimeException(
                    "Cannot verify packing task in status: {$locked->status}"
                );
            }

            $fulfillment = Fulfillment::whereKey($locked->fulfillment_id)->lockForUpdate()->firstOrFail();

            $siblingOpen = PackingTask::where('fulfillment_id', $fulfillment->id)
                ->whereKeyNot($locked->id)
                ->whereIn('status', self::OPEN_TASK_STATUSES)
                ->lockForUpdate()
                ->exists();

            if ($siblingOpen) {
                throw new \RuntimeException(
                    "Cannot verify packing task #{$locked->id}: fulfillment #{$fulfillment->id} has another open packing task"
                );
            }

            $this->assertFullyPackaged($fulfillment);

            $locked->update([
                'status' => 'verified',
                'verified_at' => now(),
                'notes' => $notes ?? $locked->notes,
            ]);

            $this->transitions->transition($fulfillment, 'ready_to_ship', ['reason' => 'packing_verified']);

            Log::info('Packing verified', [
                'task_id' => $locked->id,
                'fulfillment_id' => $locked->fulfillment_id,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * P6-3B (D6-3): every fulfillment item must satisfy
     * SUM(non-voided package_items.quantity) == quantity_picked.
     * Read-only: never mutates picking or inventory quantities.
     *
     * @throws \RuntimeException on the first incomplete item.
     */
    private function assertFullyPackaged(Fulfillment $fulfillment): void
    {
        $items = FulfillmentItem::where('fulfillment_id', $fulfillment->id)
            ->lockForUpdate()
            ->get();

        $packageIds = Package::where('fulfillment_id', $fulfillment->id)
            ->where('status', '!=', Package::STATUS_VOIDED)
            ->lockForUpdate()
            ->pluck('id');

        foreach ($items as $item) {
            $picked = (float) $item->quantity_picked;
            $packed = $packageIds->isEmpty()
                ? 0.0
                : (float) PackageItem::where('fulfillment_item_id', $item->id)
                    ->whereIn('package_id', $packageIds)
                    ->sum('quantity');

            if (abs($packed - $picked) > 0.000001) {
                throw new \RuntimeException(
                    "Cannot verify packing: fulfillment item #{$item->id} picked {$picked}, packed {$packed}"
                );
            }
        }
    }

    /**
     * Create shipment from verified packing task.
     * Phase 11: delegated to ShipmentService (boundary guard + idempotency).
     * The ready_to_ship→shipped fulfillment move happens at DISPATCH, not here.
     */
    public function createShipment(PackingTask $task, array $shipmentData): Shipment
    {
        if ($task->status !== 'verified') {
            throw new \Exception('Cannot create shipment from unverified packing task');
        }

        return DB::transaction(function () use ($task, $shipmentData) {
            $fulfillment = $task->fulfillment;

            $shipment = $this->shipments->createForFulfillment(
                $fulfillment,
                [
                    'packing_task_id' => $task->id,
                    'tracking_number' => $this->generateTrackingNumber(),
                    'courier' => $shipmentData['courier'] ?? null,
                    'shipping_method' => $shipmentData['shipping_method'] ?? 'standard',
                    'total_weight' => $task->weight,
                    'dimensions' => $task->dimensions,
                    'destination_address' => $shipmentData['destination_address'] ?? $this->destinationAddress($fulfillment),
                    'notes' => $shipmentData['notes'] ?? null,
                ],
                $shipmentData['idempotency_key'] ?? null,
            );

            Log::info('Shipment created from packing task', [
                'shipment_id' => $shipment->id,
                'task_id' => $task->id,
                'tracking_number' => $shipment->tracking_number,
            ]);

            return $shipment;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function destinationAddress(Fulfillment $fulfillment): ?array
    {
        $address = $fulfillment->order?->address;

        return is_array($address) ? $address : json_decode((string) $address, true);
    }

    /**
     * Cancel packing task.
     * P6-2: locked fresh state. P6-4: a `packed` task is completed physical
     * work and cannot silently downgrade to cancelled (verify it or leave it).
     */
    public function cancelTask(PackingTask $task, ?string $reason): PackingTask
    {
        if ($reason === null || trim($reason) === '') {
            throw new \InvalidArgumentException('Cancellation reason is required');
        }

        return DB::transaction(function () use ($task, $reason) {
            $locked = PackingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['packed', 'verified', 'cancelled'], true)) {
                throw new \RuntimeException(
                    "Cannot cancel packing task in status: {$locked->status}"
                );
            }

            $locked->update([
                'status' => 'cancelled',
                'notes' => trim($reason),
            ]);

            Log::warning('Packing task cancelled', [
                'task_id' => $locked->id,
                'reason' => trim($reason),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Generate unique tracking number
     */
    private function generateTrackingNumber(): string
    {
        do {
            $number = 'SHIP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8));
            $exists = Shipment::where('tracking_number', $number)->exists();
        } while ($exists);

        return $number;
    }

    /**
     * Get pending tasks for a packing station
     */
    public function getPendingTasksForStation(int $stationId): Collection
    {
        return PackingTask::where('packing_station_id', $stationId)
            ->whereIn('status', ['assigned', 'packing'])
            ->with('fulfillment.items')
            ->orderBy('assigned_at')
            ->get();
    }

    /**
     * Get available packing stations
     */
    public function getAvailableStations(int $warehouseId): Collection
    {
        return PackingStation::where('warehouse_id', $warehouseId)
            ->active()
            ->orderBy('code')
            ->get();
    }

    /**
     * Get packing task statistics for a station
     */
    public function getStationStatistics(int $stationId, ?\DateTime $date = null): array
    {
        $date = $date ?? now();

        $query = PackingTask::where('packing_station_id', $stationId)
            ->whereDate('created_at', $date->format('Y-m-d'));

        return [
            'total_tasks' => $query->count(),
            'completed' => (clone $query)->where('status', 'verified')->count(),
            'in_progress' => (clone $query)->whereIn('status', ['assigned', 'packing', 'packed'])->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
        ];
    }

    /**
     * Phase 10: create an open package for a fulfillment.
     * D6-1: ONE active (non-voided) package per fulfillment — voided rows
     * remain as history but never block. P6-6A: an optional packing_task_id
     * must belong to the same fulfillment. P6-6B: locked fresh status gate
     * (cancelled fulfillments cannot accrue packages). Application-level
     * locked enforcement — no schema change.
     */
    public function createPackage(Fulfillment $fulfillment, ?int $packingTaskId = null, array $attributes = []): Package
    {
        return DB::transaction(function () use ($fulfillment, $packingTaskId, $attributes) {
            $locked = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, ['packing', 'picked'], true)) {
                throw new \Exception(
                    "Cannot create package for fulfillment in status: {$locked->status}"
                );
            }

            $activeExists = Package::where('fulfillment_id', $locked->id)
                ->where('status', '!=', Package::STATUS_VOIDED)
                ->lockForUpdate()
                ->exists();

            if ($activeExists) {
                throw new \RuntimeException(
                    "Fulfillment #{$locked->id} already has an active package (one package per fulfillment)"
                );
            }

            if ($packingTaskId !== null) {
                $linked = PackingTask::whereKey($packingTaskId)->firstOrFail();

                if ((int) $linked->fulfillment_id !== (int) $locked->id) {
                    throw new \RuntimeException(
                        "Packing task #{$packingTaskId} belongs to a different fulfillment than #{$locked->id}"
                    );
                }
            }

            $package = Package::create([
                'fulfillment_id' => $locked->id,
                'order_id' => $locked->order_id,
                'packing_task_id' => $packingTaskId,
                'package_number' => $this->generatePackageNumber(),
                'status' => Package::STATUS_OPEN,
                'weight' => $attributes['weight'] ?? null,
                'dimensions' => $attributes['dimensions'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            Log::info('Package created', [
                'package_id' => $package->id,
                'fulfillment_id' => $locked->id,
            ]);

            return $package->fresh();
        });
    }

    /**
     * Phase 10: add picked quantity to an open package.
     * Enforces SUM(package_items.quantity) <= fulfillment_item.quantity_picked
     * with a locked read-check-write (unique constraint alone is insufficient).
     *
     * @throws \Exception on over-pack, duplicate, unpicked, or sealed package.
     */
    public function addItemToPackage(Package $package, int $fulfillmentItemId, float $quantity): PackageItem
    {
        return DB::transaction(function () use ($package, $fulfillmentItemId, $quantity) {
            $lockedPackage = Package::whereKey($package->id)->lockForUpdate()->firstOrFail();

            if ($lockedPackage->status !== Package::STATUS_OPEN) {
                throw new \Exception("Cannot modify package in status: {$lockedPackage->status}");
            }

            // P6-6B: cancelled fulfillments cannot accrue package quantity.
            // Fresh unlocked read (a fulfillment lock here would invert the
            // package → item order used below against verify's fulfillment →
            // package order). The race is closed the other way: voidPackage
            // locks this same package row, and the open-status recheck above
            // refuses anything voided after this read.
            $fulfillmentStatus = $lockedPackage->fulfillment()->value('status');

            if ($fulfillmentStatus === 'cancelled') {
                throw new \RuntimeException(
                    "Cannot pack items for cancelled fulfillment #{$lockedPackage->fulfillment_id}"
                );
            }

            $item = FulfillmentItem::whereKey($fulfillmentItemId)->lockForUpdate()->firstOrFail();

            if ((int) $item->fulfillment_id !== (int) $lockedPackage->fulfillment_id) {
                throw new \Exception('Package item belongs to a different fulfillment');
            }
            if ($quantity <= 0) {
                throw new \Exception('Package quantity must be greater than zero');
            }

            $alreadyPacked = PackageItem::where('fulfillment_item_id', $item->id)
                ->whereHas('package', fn ($q) => $q->where('status', '!=', Package::STATUS_VOIDED))
                ->sum('quantity');
            $picked = (float) $item->quantity_picked;

            if ($picked <= 0) {
                throw new \Exception('Cannot pack unpicked quantity');
            }
            if ((float) $alreadyPacked + $quantity > $picked) {
                throw new \Exception(
                    "Over-pack rejected: picked {$picked}, already packed {$alreadyPacked}, requested {$quantity}"
                );
            }

            $existingItem = PackageItem::where('package_id', $lockedPackage->id)
                ->where('fulfillment_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            $packageItem = PackageItem::updateOrCreate(
                ['package_id' => $lockedPackage->id, 'fulfillment_item_id' => $item->id],
                [
                    'order_item_id' => $item->order_item_id,
                    'quantity' => (float) ($existingItem?->quantity ?? 0) + $quantity,
                ],
            );

            Log::info('Package item added', [
                'package_id' => $lockedPackage->id,
                'fulfillment_item_id' => $item->id,
                'quantity' => $quantity,
            ]);

            return $packageItem->fresh();
        });
    }

    /**
     * Phase 10: seal an open package (immutable contents, scan barcode).
     */
    public function sealPackage(Package $package, ?float $weight = null, ?array $dimensions = null): Package
    {
        return DB::transaction(function () use ($package, $weight, $dimensions) {
            $locked = Package::whereKey($package->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Package::STATUS_OPEN) {
                throw new \Exception("Cannot seal package in status: {$locked->status}");
            }
            if ($locked->items()->count() === 0) {
                throw new \Exception('Cannot seal an empty package');
            }

            $locked->update([
                'status' => Package::STATUS_SEALED,
                'barcode' => $locked->barcode ?? $this->generatePackageBarcode(),
                'weight' => $weight ?? $locked->weight,
                'dimensions' => $dimensions ?? $locked->dimensions,
                'sealed_at' => now(),
            ]);

            Log::info('Package sealed', ['package_id' => $locked->id]);

            return $locked->fresh();
        });
    }

    /**
     * P6-7: the single package correction primitive. Only OPEN packages may
     * be voided — a sealed package is physical custody with barcode identity
     * and must not be silently destroyed (sealed-on-cancel needs a future
     * business decision; it is left untouched and logged, never voided here).
     * The row and its items are retained for audit; voided packages are
     * excluded from quantity math and never block a replacement package.
     */
    public function voidPackage(Package $package, ?string $reason): Package
    {
        if ($reason === null || trim($reason) === '') {
            throw new \InvalidArgumentException('Void reason is required');
        }

        return DB::transaction(function () use ($package, $reason) {
            $locked = Package::whereKey($package->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Package::STATUS_VOIDED) {
                throw new \RuntimeException("Package #{$locked->id} is already voided");
            }

            if ($locked->status !== Package::STATUS_OPEN) {
                throw new \RuntimeException(
                    "Cannot void package in status: {$locked->status} (only open packages may be voided)"
                );
            }

            $locked->update([
                'status' => Package::STATUS_VOIDED,
                'notes' => trim($reason),
            ]);

            Log::warning('Package voided', [
                'package_id' => $locked->id,
                'fulfillment_id' => $locked->fulfillment_id,
                'reason' => trim($reason),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * P6-7: fulfillment-cancellation integration. Voids every OPEN package of
     * the fulfillment through the voidPackage authority (never by direct
     * status writes). Sealed packages are physical custody — left untouched
     * and reported, never silently voided.
     *
     * @return int number of packages voided.
     */
    public function voidOpenPackagesForFulfillment(Fulfillment $fulfillment, string $reason): int
    {
        $openIds = Package::where('fulfillment_id', $fulfillment->id)
            ->where('status', Package::STATUS_OPEN)
            ->pluck('id');

        $voided = 0;

        foreach ($openIds as $packageId) {
            $package = Package::whereKey($packageId)->first();

            if ($package === null) {
                continue;
            }

            $this->voidPackage($package, $reason);
            $voided++;
        }

        $sealedLeft = Package::where('fulfillment_id', $fulfillment->id)
            ->where('status', Package::STATUS_SEALED)
            ->count();

        if ($sealedLeft > 0) {
            Log::warning('Cancelled fulfillment retains sealed packages (physical custody, supervisor decision required)', [
                'fulfillment_id' => $fulfillment->id,
                'sealed_count' => $sealedLeft,
            ]);
        }

        return $voided;
    }

    /**
     * Generate unique package barcode (WHICH PACKAGE identity).
     */
    private function generatePackageBarcode(): string
    {
        do {
            $barcode = 'PKG-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8));
            $exists = Package::where('barcode', $barcode)->exists();
        } while ($exists);

        return $barcode;
    }

    /**
     * Generate unique package number.
     */
    private function generatePackageNumber(): string
    {
        do {
            $number = 'PCK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $exists = Package::where('package_number', $number)->exists();
        } while ($exists);

        return $number;
    }
}

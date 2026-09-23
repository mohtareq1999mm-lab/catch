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
    public function __construct(
        private FulfillmentTransition $transitions,
        private \App\Services\Shipment\ShipmentService $shipments,
    ) {}
    /**
     * Create a packing task from a completed fulfillment
     */
    public function createPackingTaskFromFulfillment(Fulfillment $fulfillment): PackingTask
    {
        if (!in_array($fulfillment->status, ['picked', 'packing'])) {
            throw new \Exception(
                "Cannot create packing task from fulfillment in status: {$fulfillment->status}"
            );
        }

        return DB::transaction(function () use ($fulfillment) {
            $task = PackingTask::create([
                'fulfillment_id' => $fulfillment->id,
                'status' => 'pending',
            ]);

            $this->transitions->transition($fulfillment, 'packing', ['reason' => 'packing_task_created']);

            Log::info('Packing task created', [
                'task_id' => $task->id,
                'fulfillment_id' => $fulfillment->id,
            ]);

            return $task;
        });
    }

    /**
     * Assign task to packing station and user
     */
    public function assignToStation(
        PackingTask $task,
        int $stationId,
        int $userId
    ): PackingTask {
        $station = PackingStation::findOrFail($stationId);

        if ($station->status !== 'active') {
            throw new \Exception('Packing station is not active');
        }

        $task->update([
            'packing_station_id' => $stationId,
            'assigned_to' => $userId,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        Log::info('Packing task assigned', [
            'task_id' => $task->id,
            'station_id' => $stationId,
            'user_id' => $userId,
        ]);

        return $task->fresh();
    }

    /**
     * Start packing process
     */
    public function startPacking(PackingTask $task): PackingTask
    {
        if ($task->status !== 'assigned') {
            throw new \Exception(
                "Cannot start packing task in status: {$task->status}"
            );
        }

        $task->update([
            'status' => 'packing',
            'started_at' => now(),
        ]);

        Log::info('Packing started', [
            'task_id' => $task->id,
            'assigned_to' => $task->assigned_to,
        ]);

        return $task->fresh();
    }

    /**
     * Complete packing with package details
     */
    public function completePacking(
        PackingTask $task,
        float $weight,
        array $dimensions,
        ?array $materials = null,
        ?string $notes = null
    ): PackingTask {
        if (!in_array($task->status, ['packing'])) {
            throw new \Exception(
                "Cannot complete packing task in status: {$task->status}"
            );
        }

        return DB::transaction(function () use ($task, $weight, $dimensions, $materials, $notes) {
            $task->update([
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
                'task_id' => $task->id,
                'fulfillment_id' => $task->fulfillment_id,
                'weight' => $weight,
            ]);

            return $task->fresh();
        });
    }

    /**
     * Verify packed task
     */
    public function verifyPacking(PackingTask $task, ?string $notes = null): PackingTask
    {
        if ($task->status !== 'packed') {
            throw new \Exception(
                "Cannot verify packing task in status: {$task->status}"
            );
        }

        return DB::transaction(function () use ($task, $notes) {
            $task->update([
                'status' => 'verified',
                'verified_at' => now(),
                'notes' => $notes ?? $task->notes,
            ]);

            $this->transitions->transition($task->fulfillment, 'ready_to_ship', ['reason' => 'packing_verified']);

            Log::info('Packing verified', [
                'task_id' => $task->id,
                'fulfillment_id' => $task->fulfillment_id,
            ]);

            return $task->fresh();
        });
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
     * Cancel packing task
     */
    public function cancelTask(PackingTask $task, string $reason): PackingTask
    {
        if (in_array($task->status, ['verified', 'cancelled'])) {
            throw new \Exception(
                "Cannot cancel packing task in status: {$task->status}"
            );
        }

        $task->update([
            'status' => 'cancelled',
            'notes' => $reason,
        ]);

        Log::warning('Packing task cancelled', [
            'task_id' => $task->id,
            'reason' => $reason,
        ]);

        return $task->fresh();
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
     * Phase 10: create an open package for a fulfillment (multi-package ready).
     */
    public function createPackage(Fulfillment $fulfillment, ?int $packingTaskId = null, array $attributes = []): Package
    {
        if (!in_array($fulfillment->status, ['packing', 'picked'], true)) {
            throw new \Exception(
                "Cannot create package for fulfillment in status: {$fulfillment->status}"
            );
        }

        return DB::transaction(function () use ($fulfillment, $packingTaskId, $attributes) {
            $package = Package::create([
                'fulfillment_id' => $fulfillment->id,
                'order_id' => $fulfillment->order_id,
                'packing_task_id' => $packingTaskId,
                'package_number' => $this->generatePackageNumber(),
                'status' => Package::STATUS_OPEN,
                'weight' => $attributes['weight'] ?? null,
                'dimensions' => $attributes['dimensions'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            Log::info('Package created', [
                'package_id' => $package->id,
                'fulfillment_id' => $fulfillment->id,
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

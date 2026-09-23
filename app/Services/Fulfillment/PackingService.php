<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PackingService
{
    public function __construct(
        private FulfillmentTransition $transitions,
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
     * Create shipment from verified packing task
     */
    public function createShipment(PackingTask $task, array $shipmentData): Shipment
    {
        if ($task->status !== 'verified') {
            throw new \Exception('Cannot create shipment from unverified packing task');
        }

        return DB::transaction(function () use ($task, $shipmentData) {
            $fulfillment = $task->fulfillment;
            $order = $fulfillment->order;

            $trackingNumber = $this->generateTrackingNumber();

            $shipment = Shipment::create([
                'tracking_number' => $trackingNumber,
                'order_id' => $order->id,
                'fulfillment_id' => $fulfillment->id,
                'packing_task_id' => $task->id,
                'courier' => $shipmentData['courier'] ?? null,
                'shipping_method' => $shipmentData['shipping_method'] ?? 'standard',
                'status' => 'pending',
                'total_weight' => $task->weight,
                'dimensions' => $task->dimensions,
                'destination_address' => $shipmentData['destination_address'] ?? json_decode($order->address, true),
                'notes' => $shipmentData['notes'] ?? null,
            ]);

            // P11 owns shipment creation via ShipmentService; until then this
            // legacy path stays but writes fulfillment state via the owner.
            // NOTE(P11): delegate creation to ShipmentService + ready_to_ship guard.
            $this->transitions->transition($fulfillment, 'shipped', ['reason' => 'shipment_dispatched']);

            Log::info('Shipment created from packing task', [
                'shipment_id' => $shipment->id,
                'task_id' => $task->id,
                'tracking_number' => $trackingNumber,
            ]);

            return $shipment;
        });
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
}

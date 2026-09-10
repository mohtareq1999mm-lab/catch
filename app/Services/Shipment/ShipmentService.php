<?php

namespace App\Services\Shipment;

use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

class ShipmentService
{
    public function list(array $filters = [], int $perPage = 15)
    {
        return Shipment::query()
            ->with(['order'])
            ->when($filters['order_id'] ?? null, fn($q, $v) => $q->where('order_id', (int) $v))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($filters['courier'] ?? null, fn($q, $v) => $q->where('courier', $v))
            ->when($filters['tracking_number'] ?? null, fn($q, $v) => $q->where('tracking_number', 'like', "%{$v}%"))
            ->when($filters['from'] ?? null, fn($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderBy('created_at', 'desc')
            ->paginate(min($perPage, 100));
    }

    public function find(int $id): Shipment
    {
        return Shipment::with(['order'])->findOrFail($id);
    }

    public function findByUuid(string $uuid): Shipment
    {
        return Shipment::with(['order'])->where('uuid', $uuid)->firstOrFail();
    }

    public function create(array $data): Shipment
    {
        return DB::transaction(function () use ($data) {
            $data['status'] = 'pending';
            return Shipment::create($data);
        });
    }

    public function findByTrackingNumber(string $trackingNumber): ?Shipment
    {
        return Shipment::with(['order'])->where('tracking_number', $trackingNumber)->first();
    }

    public function updateStatus(int $id, string $newStatus, ?string $notes = null): Shipment
    {
        return DB::transaction(function () use ($id, $newStatus, $notes) {
            $shipment = Shipment::lockForUpdate()->findOrFail($id);

            if (!$shipment->canTransitionTo($newStatus)) {
                throw new \RuntimeException(
                    "Shipment {$shipment->id} cannot transition from '{$shipment->status}' to '{$newStatus}'"
                );
            }

            $timestamps = [];
            if ($newStatus === 'shipped' || $newStatus === 'picked_up') {
                $timestamps['shipped_at'] = $shipment->shipped_at ?? now();
            }
            if ($newStatus === 'delivered') {
                $timestamps['delivered_at'] = now();
            }

            $shipment->update(array_merge([
                'status' => $newStatus,
                'notes' => $notes ?? $shipment->notes,
            ], $timestamps));

            return $shipment->fresh();
        });
    }

    public function update(int $id, array $data): Shipment
    {
        $shipment = Shipment::findOrFail($id);
        $shipment->update($data);
        return $shipment->fresh();
    }
}

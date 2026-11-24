<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Enums\Shipping\DeliveryAttemptStatus;
use App\Models\Shipment;
use App\Models\Shipping\DeliveryRepresentative;
use App\Models\Shipping\ShipmentAssignment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryAttemptService
{
    public function resolveRepresentative(Authenticatable $user): DeliveryRepresentative
    {
        $representative = DeliveryRepresentative::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('is_active', true)
            ->first();

        if (!$representative) {
            abort(403, 'Active delivery representative profile required.');
        }

        return $representative;
    }

    public function findOwnedAssignment(DeliveryRepresentative $representative, int $assignmentId): ShipmentAssignment
    {
        return ShipmentAssignment::query()
            ->whereKey($assignmentId)
            ->where('delivery_representative_id', $representative->id)
            ->where('status', ShipmentAssignment::STATUS_ACTIVE)
            ->with(['shipment'])
            ->firstOrFail();
    }

    public function listOwnedAssignments(DeliveryRepresentative $representative)
    {
        return ShipmentAssignment::query()
            ->where('delivery_representative_id', $representative->id)
            ->with(['shipment'])
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function recordAttempt(ShipmentAssignment $assignment, array $data): ShipmentDeliveryAttempt
    {
        $status = DeliveryAttemptStatus::from((string) $data['status']);
        $reason = isset($data['failure_reason']) ? trim((string) $data['failure_reason']) : null;
        $reason = $reason === '' ? null : $reason;

        if ($status->requiresReason() && $reason === null) {
            throw ValidationException::withMessages([
                'failure_reason' => ['failure_reason is required when status is other.'],
            ]);
        }

        $maxAttempts = max(1, (int) config('shipping.delivery.max_attempts', 3));

        return DB::transaction(function () use ($assignment, $status, $reason, $maxAttempts, $data): ShipmentDeliveryAttempt {
            $lockedAssignment = ShipmentAssignment::query()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAssignment->status !== ShipmentAssignment::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['assignment' => ['Assignment is not active.']]);
            }

            $shipment = Shipment::query()->whereKey($lockedAssignment->shipment_id)->lockForUpdate()->firstOrFail();

            $lastAttempt = ShipmentDeliveryAttempt::query()
                ->where('shipment_id', $shipment->id)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            $nextNumber = ((int) ($lastAttempt?->attempt_number ?? 0)) + 1;
            if ($nextNumber > $maxAttempts) {
                throw ValidationException::withMessages([
                    'attempt_number' => ["Maximum delivery attempts ({$maxAttempts}) exceeded."],
                ]);
            }

            try {
                return ShipmentDeliveryAttempt::create([
                    'shipment_id' => $shipment->id,
                    'shipment_assignment_id' => $lockedAssignment->id,
                    'attempt_number' => $nextNumber,
                    'status' => $status->value,
                    'attempted_at' => $data['attempted_at'] ?? now(),
                    'failure_reason' => $status === DeliveryAttemptStatus::DELIVERED ? null : $reason,
                    'metadata' => $data['metadata'] ?? null,
                ]);
            } catch (QueryException $e) {
                // Concurrent insert hit unique (shipment_id, attempt_number): retry-safe refusal.
                if ($this->isAttemptNumberConflict($e)) {
                    throw ValidationException::withMessages([
                        'attempt_number' => ['Delivery attempt number conflict; retry not allowed beyond maximum.'],
                    ]);
                }
                throw $e;
            }
        }, 3);
    }

    private function isAttemptNumberConflict(QueryException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'shipment_attempts_shipment_number_unique')
            || str_contains($msg, 'shipment_delivery_attempts_shipment_id_attempt_number_unique')
            || str_contains($msg, 'UNIQUE constraint failed');
    }
}


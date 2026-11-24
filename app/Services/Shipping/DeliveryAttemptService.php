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
use Illuminate\Database\UniqueConstraintViolationException;
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
            abort(403, __('shipping.delivery_attempt.representative_required'));
        }

        return $representative;
    }

    public function findActiveOwnedAssignment(DeliveryRepresentative $representative, int $assignmentId): ShipmentAssignment
    {
        // #164/#173: shipment_assignments is the authoritative representative
        // scope. Warehouse/staff checks are intentionally absent by design.
        return ShipmentAssignment::query()
            ->whereKey($assignmentId)
            ->where('delivery_representative_id', $representative->id)
            ->where('status', ShipmentAssignment::STATUS_ACTIVE)
            ->with(['shipment'])
            ->firstOrFail();
    }

    public function recordAttempt(int $representativeId, ShipmentAssignment $assignment, array $data): ShipmentDeliveryAttempt
    {
        $normalized = DeliveryAttemptStatus::normalizeAttempt($data);
        if ($normalized['error'] !== null) {
            throw ValidationException::withMessages([
                (string) $normalized['error_field'] => [__('shipping.delivery_attempt.' . $normalized['error'])],
            ]);
        }

        $status = (string) $normalized['status'];
        $reason = $normalized['failure_reason'];
        $maxAttempts = max(1, (int) config('shipping.delivery.max_attempts', 3));

        return DB::transaction(function () use ($representativeId, $assignment, $status, $reason, $maxAttempts, $data): ShipmentDeliveryAttempt {
            // Ownership is rechecked under the same lock as the write: an
            // authorization-time assignment can be reassigned before this point.
            $lockedAssignment = ShipmentAssignment::query()
                ->whereKey($assignment->id)
                ->where('delivery_representative_id', $representativeId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAssignment->status !== ShipmentAssignment::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'assignment' => [__('shipping.delivery_attempt.assignment_inactive')],
                ]);
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
                    'attempt_number' => [__('shipping.delivery_attempt.max_exceeded', ['max' => $maxAttempts])],
                ]);
            }

            try {
                return ShipmentDeliveryAttempt::create([
                    'shipment_id' => $shipment->id,
                    'shipment_assignment_id' => $lockedAssignment->id,
                    'attempt_number' => $nextNumber,
                    'status' => $status,
                    'attempted_at' => $data['attempted_at'] ?? now(),
                    'failure_reason' => $reason,
                    'metadata' => $data['metadata'] ?? null,
                ]);
            } catch (QueryException $e) {
                if ($this->isAttemptNumberConflict($e)) {
                    throw ValidationException::withMessages([
                        'attempt_number' => [__('shipping.delivery_attempt.number_conflict')],
                    ]);
                }
                throw $e;
            }
        }, 3);
    }

    public function isAttemptNumberConflict(QueryException $e): bool
    {
        return $e instanceof UniqueConstraintViolationException
            || ($e->errorInfo[1] ?? null) === 1062
            || (string) $e->getCode() === '23505';
    }
}

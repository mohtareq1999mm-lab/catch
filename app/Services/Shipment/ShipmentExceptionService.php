<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Models\Shipping\ShipmentException;
use App\Services\Warehouse\WarehouseAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ShipmentExceptionService
{
    public function __construct(private readonly WarehouseAccess $warehouseAccess)
    {
    }

    public function markInvestigating(ShipmentException $exception, Authenticatable $actor, ?string $notes = null): ShipmentException
    {
        return $this->transition($exception, ShipmentExceptionStatus::INVESTIGATING, $actor, $notes);
    }

    public function resolve(ShipmentException $exception, Authenticatable $actor, string $notes): ShipmentException
    {
        return $this->transition($exception, ShipmentExceptionStatus::RESOLVED, $actor, $notes);
    }

    public function dismiss(ShipmentException $exception, Authenticatable $actor, string $notes): ShipmentException
    {
        return $this->transition($exception, ShipmentExceptionStatus::DISMISSED, $actor, $notes);
    }

    private function transition(
        ShipmentException $exception,
        ShipmentExceptionStatus $to,
        Authenticatable $actor,
        ?string $notes,
    ): ShipmentException {
        $actorId = $this->authenticatedActorId($actor);
        $from = ShipmentExceptionStatus::from((string) $exception->status);

        if (!$this->canTransition($from, $to)) {
            throw new DomainException("Invalid shipment exception transition: {$from->value} -> {$to->value}");
        }

        $trimmedNotes = $notes !== null ? trim($notes) : null;
        if ($trimmedNotes === null || $trimmedNotes === '') {
            throw new DomainException('Notes are required for exception investigation actions.');
        }

        return DB::transaction(function () use ($exception, $to, $actor, $actorId, $trimmedNotes): ShipmentException {
            $locked = ShipmentException::query()
                ->with('shipment.fulfillment')
                ->lockForUpdate()
                ->findOrFail($exception->id);

            $this->assertWarehouseScope($actor, $locked);

            $current = ShipmentExceptionStatus::from((string) $locked->status);
            if (!$this->canTransition($current, $to)) {
                throw new DomainException("Invalid shipment exception transition: {$current->value} -> {$to->value}");
            }

            // Diagnostic/base notes are append-only: an action note must never
            // replace the evidence that opened the exception.
            $actionNote = '['.$to->value.'] '.$trimmedNotes;
            $existingNotes = trim((string) $locked->notes);

            $payload = [
                'status' => $to->value,
                'notes' => $existingNotes === '' ? $actionNote : $existingNotes."\n".$actionNote,
            ];

            if ($to === ShipmentExceptionStatus::INVESTIGATING) {
                $payload['owner_id'] = $actorId;
            }

            if (in_array($to, [ShipmentExceptionStatus::RESOLVED, ShipmentExceptionStatus::DISMISSED], true)) {
                $payload['resolved_at'] = now();
                $payload['resolved_by'] = $actorId;
            }

            $locked->forceFill($payload)->save();

            return $locked->fresh();
        });
    }

    private function authenticatedActorId(Authenticatable $actor): int
    {
        $id = $actor->getAuthIdentifier();

        if (!is_numeric($id) || (int) $id <= 0) {
            throw new AuthorizationException('Authenticated actor required.');
        }

        return (int) $id;
    }

    private function assertWarehouseScope(Authenticatable $actor, ShipmentException $exception): void
    {
        $warehouseId = $exception->shipment?->fulfillment?->warehouse_id;

        if ($warehouseId !== null && $this->warehouseAccess->allows($actor, (int) $warehouseId, 'update-shipment')) {
            return;
        }

        if (
            $warehouseId === null
            && $this->warehouseAccess->allows($actor, null, 'update-shipment')
            && $this->warehouseAccess->allows($actor, null, 'manage-warehouse')
        ) {
            return;
        }

        throw (new ModelNotFoundException())->setModel(ShipmentException::class);
    }

    private function canTransition(ShipmentExceptionStatus $from, ShipmentExceptionStatus $to): bool
    {
        return match ($from) {
            ShipmentExceptionStatus::OPEN => in_array($to, [ShipmentExceptionStatus::INVESTIGATING, ShipmentExceptionStatus::RESOLVED, ShipmentExceptionStatus::DISMISSED], true),
            ShipmentExceptionStatus::INVESTIGATING => in_array($to, [ShipmentExceptionStatus::RESOLVED, ShipmentExceptionStatus::DISMISSED], true),
            ShipmentExceptionStatus::RESOLVED, ShipmentExceptionStatus::DISMISSED => false,
        };
    }
}

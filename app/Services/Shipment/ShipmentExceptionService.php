<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Models\Shipping\ShipmentException;
use DomainException;
use Illuminate\Support\Facades\DB;

class ShipmentExceptionService
{
    public function markInvestigating(ShipmentException $exception, int $actorId, ?string $notes = null): ShipmentException
    {
        return $this->transition($exception, ShipmentExceptionStatus::INVESTIGATING, $actorId, $notes);
    }

    public function resolve(ShipmentException $exception, int $actorId, string $notes): ShipmentException
    {
        return $this->transition($exception, ShipmentExceptionStatus::RESOLVED, $actorId, $notes);
    }

    public function dismiss(ShipmentException $exception, int $actorId, string $notes): ShipmentException
    {
        return $this->transition($exception, ShipmentExceptionStatus::DISMISSED, $actorId, $notes);
    }

    private function transition(ShipmentException $exception, ShipmentExceptionStatus $to, int $actorId, ?string $notes): ShipmentException
    {
        if ($actorId <= 0) {
            throw new DomainException('Actor is required.');
        }

        $from = ShipmentExceptionStatus::from((string) $exception->status);

        if (!$this->canTransition($from, $to)) {
            throw new DomainException("Invalid shipment exception transition: {$from->value} -> {$to->value}");
        }

        $trimmedNotes = $notes !== null ? trim($notes) : null;
        if (in_array($to, [ShipmentExceptionStatus::RESOLVED, ShipmentExceptionStatus::DISMISSED], true) && ($trimmedNotes === null || $trimmedNotes === '')) {
            throw new DomainException('Resolution notes are required.');
        }

        return DB::transaction(function () use ($exception, $to, $actorId, $trimmedNotes): ShipmentException {
            $locked = ShipmentException::query()->lockForUpdate()->findOrFail($exception->id);
            $current = ShipmentExceptionStatus::from((string) $locked->status);
            if (!$this->canTransition($current, $to)) {
                throw new DomainException("Invalid shipment exception transition: {$current->value} -> {$to->value}");
            }

            $payload = [
                'status' => $to->value,
                'notes' => $trimmedNotes ?? $locked->notes,
            ];

            if ($to === ShipmentExceptionStatus::RESOLVED || $to === ShipmentExceptionStatus::DISMISSED) {
                $payload['resolved_at'] = now();
                $payload['resolved_by'] = $actorId;
            }

            $locked->forceFill($payload)->save();

            return $locked->fresh();
        });
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

<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Models\Shipment;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Warehouse\WarehouseAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * #161: manual resolution seam for unmatched/ambiguous inbox rows.
 *
 * This only repairs the durable linkage; it never changes shipment lifecycle
 * state. Provider-event replay is idempotent by design (#143/R4B): an
 * already-created provider event is linked but not re-applied, and a row not
 * yet processed is left for the normal worker. Re-opening the exception and
 * re-running status transitions after correction needs a dedicated lifecycle
 * follow-up with applied/terminal guards.
 */
final class ShippingWebhookMatchCorrectionService
{
    private const CORRECTABLE_STATUSES = ['unmatched', 'ambiguous'];

    public function __construct(
        private readonly WarehouseAccess $warehouseAccess,
        private readonly ShippingProviderEventProcessor $processor,
    ) {}

    public function attachShipment(
        ShippingWebhookEvent $webhook,
        Shipment $shipment,
        Authenticatable $actor,
        string $notes,
    ): ShippingWebhookEvent {
        $trimmedNotes = trim($notes);
        if ($trimmedNotes === '') {
            throw new DomainException('Manual match notes are required.');
        }

        $actorId = $actor->getAuthIdentifier();
        if (!is_numeric($actorId) || (int) $actorId <= 0) {
            throw new AuthorizationException('Authenticated actor required.');
        }

        if (!in_array((string) $webhook->match_status, self::CORRECTABLE_STATUSES, true)) {
            throw new DomainException('Only unresolved webhook matches can be corrected.');
        }

        $corrected = DB::transaction(function () use ($webhook, $shipment, $actor, $actorId, $trimmedNotes): ShippingWebhookEvent {
            $row = ShippingWebhookEvent::query()
                ->lockForUpdate()
                ->findOrFail($webhook->id);
            $target = Shipment::query()
                ->with('fulfillment')
                ->findOrFail($shipment->id);

            $warehouseId = $target->fulfillment?->warehouse_id;
            if (!$this->warehouseAccess->allows(
                $actor,
                $warehouseId !== null ? (int) $warehouseId : null,
                'update-shipment',
            )) {
                throw new AuthorizationException('Forbidden manual webhook match correction.');
            }

            // A manual link must not cross carrier boundaries: replaying a
            // provider's event against another provider's shipment would apply
            // that provider's status to the wrong lifecycle. Null provider
            // codes (legacy shipments) remain attachable.
            if (
                $row->provider_code !== null
                && $target->provider_code !== null
                && (string) $row->provider_code !== (string) $target->provider_code
            ) {
                throw new DomainException('Webhook provider does not match the target shipment provider.');
            }

            if (!in_array((string) $row->match_status, self::CORRECTABLE_STATUSES, true)) {
                throw new DomainException('Only unresolved webhook matches can be corrected.');
            }

            $line = 'manually matched by actor '.(int) $actorId.' to shipment '.$target->id.': '.$trimmedNotes;
            $existing = trim((string) $row->match_notes);
            $row->forceFill([
                'shipment_id' => $target->id,
                'match_status' => 'matched_manual',
                'match_notes' => $existing === '' ? $line : $existing."\n".$line,
            ])->save();

            $providerEvent = ShippingProviderEvent::query()
                ->where('webhook_event_id', $row->id)
                ->first();
            if ($providerEvent !== null && $providerEvent->shipment_id === null) {
                $providerEvent->forceFill(['shipment_id' => $target->id])->save();
            }

            return $row->fresh();
        });

        // Replay after correction: the processor's accept-once guard returns
        // the existing event instead of creating a second one. Never create a
        // new provider event here, because that could advance lifecycle state.
        if (ShippingProviderEvent::query()->where('webhook_event_id', $corrected->id)->exists()) {
            $this->processor->process($corrected);
        }

        return $corrected;
    }
}

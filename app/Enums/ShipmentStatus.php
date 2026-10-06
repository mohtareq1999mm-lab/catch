<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case PENDING = 'pending';
    case LABEL_CREATED = 'label_created';
    case PICKED_UP = 'picked_up';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case FAILED_DELIVERY = 'failed_delivery';
    case RETURNED = 'returned';
    case DELAYED = 'delayed';
    case CANCELLED = 'cancelled';

    /**
     * Phase 8 (P8-2 / F8-9): the enum no longer owns a competing DAG — it
     * projects the single authoritative Shipment::allowedTransitions() map.
     * Unknown values yield no transitions (fail-closed).
     */
    public function allowedTransitions(): array
    {
        $targets = \App\Models\Shipment::allowedTransitions($this->value);

        $mapped = [];
        foreach ($targets as $target) {
            $case = self::tryFrom($target);
            if ($case !== null) {
                $mapped[] = $case;
            }
        }

        return $mapped;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}

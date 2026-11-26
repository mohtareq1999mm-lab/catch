<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shipping\DeliveryRepresentative;
use App\Models\Shipping\ShipmentAssignment;
use Illuminate\Contracts\Auth\Authenticatable;

class ShipmentAssignmentPolicy
{
    // #164/#173: shipment_assignments is the authoritative representative
    // scope. Warehouse/staff checks are intentionally absent by design;
    // inactive assignments remain readable as attempt/POD history.
    public function view(Authenticatable $user, ShipmentAssignment $assignment): bool
    {
        $representative = $this->representative($user);

        return $representative !== null
            && (int) $assignment->delivery_representative_id === (int) $representative->id;
    }

    public function recordAttempt(Authenticatable $user, ShipmentAssignment $assignment): bool
    {
        return $this->view($user, $assignment) && $assignment->status === ShipmentAssignment::STATUS_ACTIVE;
    }

    private function representative(Authenticatable $user): ?DeliveryRepresentative
    {
        return DeliveryRepresentative::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('is_active', true)
            ->first();
    }
}

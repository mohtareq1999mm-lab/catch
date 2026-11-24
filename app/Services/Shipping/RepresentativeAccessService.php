<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\Shipping\DeliveryRepresentative;
use Illuminate\Contracts\Auth\Authenticatable;

class RepresentativeAccessService
{
    public function resolveActiveRepresentative(Authenticatable $user): DeliveryRepresentative
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
}

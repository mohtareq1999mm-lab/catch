<?php

namespace App\Services\General;

use Marvel\Database\Models\PickupLocation;

class PickupLocationService
{
    public function getPickupLocations($request)
    {
        $limit = min(100, max(1, (int) $request->get('limit', 10)));
        $search = $request->query('search');
        $default = $request->query('default', false);

        $query = PickupLocation::active()->ordered();

        if ($search) {
            $query->where('store_name', 'like', "%{$search}%");
        }
        if ($default) {
            $query->where('is_default', true);
        }

        return $query->paginate($limit);
    }

    public function getPickupLocationById($id)
    {
        return PickupLocation::active()->findOrFail($id);
    }

    public function getDefaultPickupLocation(): ?PickupLocation
    {
        return PickupLocation::default()->active()->first();
    }
}
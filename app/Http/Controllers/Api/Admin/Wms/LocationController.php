<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\CreateLocationRequest;
use App\Http\Requests\Admin\Wms\UpdateLocationRequest;
use App\Http\Resources\Wms\LocationResource;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P9-2: location HTTP adapter. Location has no domain service (CRUD-only,
 * no workflow invariants beyond the model-level D-LOC-MOVE immutability
 * guard), so the controller persists validated allowlists directly.
 * warehouse_id is never updatable; status moves via activate/deactivate.
 */
class LocationController extends WmsAdminController
{
    public function __construct(
        private WarehouseAccess $access,
    ) {}

    /**
     * GET /api/v1/admin/locations
     */
    public function index(Request $request): JsonResponse
    {
        $query = Location::query()->orderBy('id');
        $filter = (int) $request->get('warehouse_id', 0) ?: null;

        if (auth()->user()->can('manage-warehouse')) {
            if ($filter !== null) {
                $query->where('warehouse_id', $filter);
            }
        } else {
            $home = $this->access->warehouseIdFor(auth()->user());
            if ($home === null) {
                throw new AuthorizationException('Forbidden warehouse operation.');
            }
            if ($filter !== null) {
                $this->authorizeWarehouseScope($filter, 'view-location', true);
                $query->where('warehouse_id', $filter);
            } else {
                $query->where('warehouse_id', $home);
            }
        }

        $perPage = min((int) $request->get('limit', 15), 100);

        return $this->apiResponse(
            'Locations fetched successfully.',
            200,
            true,
            LocationResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/locations/{id}
     */
    public function show(int $id): JsonResponse
    {
        $location = Location::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($location->warehouse_id, 'view-location', true);

        return $this->apiResponse(
            'Location fetched successfully.',
            200,
            true,
            LocationResource::make($location)
        );
    }

    /**
     * POST /api/v1/admin/locations
     */
    public function store(CreateLocationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->authorizeWarehouseScope((int) $data['warehouse_id'], 'manage-location');

        Warehouse::whereKey($data['warehouse_id'])->firstOrFail();

        if (!empty($data['parent_id'])) {
            $parent = Location::whereKey($data['parent_id'])->firstOrFail();
            if ((int) $parent->warehouse_id !== (int) $data['warehouse_id']) {
                $this->unprocessable('Parent location belongs to a different warehouse.');
            }
        }

        $location = Location::create($data);

        return $this->apiResponse(
            'Location created successfully.',
            201,
            true,
            LocationResource::make($location)
        );
    }

    /**
     * PUT /api/v1/admin/locations/{id} (non-warehouse fields only).
     */
    public function update(UpdateLocationRequest $request, int $id): JsonResponse
    {
        $location = Location::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($location->warehouse_id, 'manage-location');

        $data = $request->validated();

        if (!empty($data['parent_id'])) {
            $parent = Location::whereKey($data['parent_id'])->firstOrFail();
            if ((int) $parent->warehouse_id !== (int) $location->warehouse_id) {
                $this->unprocessable('Parent location belongs to a different warehouse.');
            }
        }

        $location->update($data);

        return $this->apiResponse(
            'Location updated successfully.',
            200,
            true,
            LocationResource::make($location->fresh())
        );
    }

    /**
     * POST /api/v1/admin/locations/{id}/activate
     */
    public function activate(int $id): JsonResponse
    {
        $location = Location::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($location->warehouse_id, 'manage-location');

        $location->update(['status' => Location::STATUS_ACTIVE]);

        return $this->apiResponse(
            'Location activated successfully.',
            200,
            true,
            LocationResource::make($location->fresh())
        );
    }

    /**
     * POST /api/v1/admin/locations/{id}/deactivate
     */
    public function deactivate(int $id): JsonResponse
    {
        $location = Location::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($location->warehouse_id, 'manage-location');

        $location->update(['status' => Location::STATUS_INACTIVE]);

        return $this->apiResponse(
            'Location deactivated successfully.',
            200,
            true,
            LocationResource::make($location->fresh())
        );
    }
}

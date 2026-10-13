<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\CreatePackingStationRequest;
use App\Http\Requests\Admin\Wms\ListPackingStationsRequest;
use App\Http\Requests\Admin\Wms\UpdatePackingStationRequest;
use App\Http\Resources\Wms\PackingStationResource;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\WarehouseAccess;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class PackingStationController extends WmsAdminController
{
    public function __construct(
        private WarehouseAccess $access,
        private WarehouseService $warehouses,
    ) {}

    /**
     * GET /api/v1/admin/packing-stations
     */
    public function index(ListPackingStationsRequest $request): JsonResponse
    {
        $query = PackingStation::query()->orderBy('code');

        if (auth()->user()->can('manage-warehouse')) {
            $filter = (int) $request->validated('warehouse_id', 0) ?: null;
            if ($filter !== null) {
                $query->where('warehouse_id', $filter);
            }
        } else {
            $home = $this->access->warehouseIdFor(auth()->user());
            if ($home === null) {
                throw new AuthorizationException('Forbidden warehouse operation.');
            }
            $filter = (int) $request->validated('warehouse_id', 0) ?: null;
            if ($filter !== null && $filter !== $home) {
                $this->authorizeWarehouseScope($filter, 'view-fulfillment', true);
            }
            $query->where('warehouse_id', $home);
        }

        $status = $request->validated('status');
        if ($status !== null) {
            $query->where('status', $status);
        }

        $perPage = min((int) ($request->validated('limit') ?? 15), 100);

        return $this->apiResponse(
            __('wms.packing.stations'),
            200,
            true,
            PackingStationResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/packing-stations/{id}
     */
    public function show(int $id): JsonResponse
    {
        $station = PackingStation::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($station->warehouse_id, 'view-fulfillment', true);

        return $this->apiResponse(
            __('wms.packing.station_fetched'),
            200,
            true,
            PackingStationResource::make($station)
        );
    }

    /**
     * POST /api/v1/admin/packing-stations
     */
    public function store(CreatePackingStationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $explicitWarehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;

        try {
            $targetWarehouseId = $explicitWarehouseId
                ?? $this->warehouses->resolveForNewFulfillment()->id;
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        if ($explicitWarehouseId !== null) {
            Warehouse::whereKey($explicitWarehouseId)->firstOrFail();
        }

        $this->authorizeWarehouseScope($targetWarehouseId, 'manage-warehouse');

        $data['warehouse_id'] = $targetWarehouseId;
        $data['status'] = $data['status'] ?? 'active';

        $station = PackingStation::create($data);

        return $this->apiResponse(
            __('wms.packing.station_created'),
            201,
            true,
            PackingStationResource::make($station)
        );
    }

    /**
     * PUT /api/v1/admin/packing-stations/{id}
     */
    public function update(UpdatePackingStationRequest $request, int $id): JsonResponse
    {
        $station = PackingStation::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($station->warehouse_id, 'manage-warehouse');

        $station->update($request->validated());

        return $this->apiResponse(
            __('wms.packing.station_updated'),
            200,
            true,
            PackingStationResource::make($station->fresh())
        );
    }

    /**
     * DELETE /api/v1/admin/packing-stations/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $station = PackingStation::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($station->warehouse_id, 'manage-warehouse');

        $hasActiveTasks = $station->packingTasks()
            ->whereIn('status', ['pending', 'assigned', 'started', 'packing'])
            ->exists();

        if ($hasActiveTasks) {
            $this->unprocessable(__('wms.packing.station_has_active_tasks'));
        }

        $station->delete();

        return $this->apiResponse(
            __('wms.packing.station_deleted'),
            200,
            true
        );
    }
}

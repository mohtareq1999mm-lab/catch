<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\CreateWarehouseRequest;
use App\Http\Requests\Admin\Wms\UpdateWarehouseRequest;
use App\Http\Resources\Wms\WarehouseResource;
use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\WarehouseAccess;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P9-2: warehouse HTTP adapter. Thin: permission (route) + warehouse scope
 * + request validation, then WarehouseService. No transactions, no guards
 * here — those live in the service and the model.
 */
class WarehouseController extends WmsAdminController
{
    public function __construct(
        private WarehouseService $warehouses,
        private WarehouseAccess $access,
    ) {}

    /**
     * GET /api/v1/admin/warehouses
     */
    public function index(Request $request): JsonResponse
    {
        $query = Warehouse::query()->orderBy('id');

        if (!auth()->user()->can('manage-warehouse')) {
            $home = $this->access->warehouseIdFor(auth()->user());
            if ($home === null) {
                throw new AuthorizationException('Forbidden warehouse operation.');
            }
            $query->whereKey($home);
        }

        $perPage = min((int) $request->get('limit', 15), 100);

        return $this->apiResponse(
            'Warehouses fetched successfully.',
            200,
            true,
            WarehouseResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/warehouses/{id}
     */
    public function show(int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'view-warehouse', true);

        return $this->apiResponse(
            'Warehouse fetched successfully.',
            200,
            true,
            WarehouseResource::make($warehouse)
        );
    }

    /**
     * POST /api/v1/admin/warehouses
     */
    public function store(CreateWarehouseRequest $request): JsonResponse
    {
        $this->authorizeWarehouseScope(null, 'manage-warehouse');

        try {
            $warehouse = $this->warehouses->create($request->validated());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Warehouse created successfully.',
            201,
            true,
            WarehouseResource::make($warehouse)
        );
    }

    /**
     * PUT /api/v1/admin/warehouses/{id}
     */
    public function update(UpdateWarehouseRequest $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'manage-warehouse');

        $warehouse = $this->warehouses->updateDetails($warehouse, $request->validated());

        return $this->apiResponse(
            'Warehouse updated successfully.',
            200,
            true,
            WarehouseResource::make($warehouse)
        );
    }

    /**
     * POST /api/v1/admin/warehouses/{id}/set-default
     */
    public function setDefault(int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'manage-warehouse');

        try {
            $warehouse = $this->warehouses->setDefault($warehouse->id);
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Default warehouse updated successfully.',
            200,
            true,
            WarehouseResource::make($warehouse)
        );
    }

    /**
     * POST /api/v1/admin/warehouses/{id}/activate
     */
    public function activate(int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'manage-warehouse');

        $warehouse = $this->warehouses->activate($warehouse->id);

        return $this->apiResponse(
            'Warehouse activated successfully.',
            200,
            true,
            WarehouseResource::make($warehouse)
        );
    }

    /**
     * POST /api/v1/admin/warehouses/{id}/deactivate
     */
    public function deactivate(int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'manage-warehouse');

        try {
            $warehouse = $this->warehouses->deactivate($warehouse->id);
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Warehouse deactivated successfully.',
            200,
            true,
            WarehouseResource::make($warehouse)
        );
    }

    /**
     * DELETE /api/v1/admin/warehouses/{id} (soft-delete; default blocked).
     */
    public function destroy(int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'manage-warehouse');

        try {
            $this->warehouses->remove($warehouse);
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse('Warehouse archived successfully.', 200, true);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\CheckLocationOccupancyRequest;
use App\Http\Requests\Admin\Wms\CreateLocationRequest;
use App\Http\Requests\Admin\Wms\UpdateLocationRequest;
use App\Http\Resources\Wms\LocationResource;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\LocationEmptyService;
use App\Services\Fulfillment\LocationOccupancyService;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * P9-2: location HTTP adapter. Location has no domain service (CRUD-only,
 * no workflow invariants beyond the model-level D-LOC-MOVE immutability
 * guard), so the controller persists validated allowlists directly.
 * warehouse_id is never updatable; status moves via activate/deactivate.
 */
use Marvel\Enums\Permission;

class LocationController extends WmsAdminController
{
    public function __construct(
        private WarehouseAccess $access,
        private LocationEmptyService $emptyService,
    ) {
        $this->middleware('permission:' . Permission::LOCATIONS_INDEX, ['only' => ['index']]);
        $this->middleware('permission:' . Permission::LOCATIONS_SHOW, ['only' => ['show']]);
        $this->middleware('permission:' . Permission::LOCATIONS_CHECK_OCCUPANCY, ['only' => ['checkOccupancy']]);
        $this->middleware('permission:' . Permission::LOCATIONS_CREATE, ['only' => ['store']]);
        $this->middleware('permission:' . Permission::LOCATIONS_UPDATE, ['only' => ['update']]);
        $this->middleware('permission:' . Permission::LOCATIONS_ACTIVATE, ['only' => ['activate']]);
        $this->middleware('permission:' . Permission::LOCATIONS_DEACTIVATE, ['only' => ['deactivate']]);
        $this->middleware('permission:' . Permission::LOCATIONS_DELETE, ['only' => ['destroy']]);
        $this->middleware('permission:' . Permission::LOCATIONS_EMPTY_PRODUCTS, ['only' => ['emptyProducts']]);
    }

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

        if ($request->filled('barcode')) {
            $query->where('barcode', $request->get('barcode'));
        }

        if ($request->filled('code')) {
            $query->where('code', $request->get('code'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->get('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('search')) {
            $search = addcslashes((string) $request->get('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('limit', 15), 100);

        return $this->apiResponse(
            __('wms.location.fetched_many'),
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
            __('wms.location.fetched_one'),
            200,
            true,
            // Detail view carries live placements (2 queries, no N+1) so
            // the Admin sees what sits in the bin without extra calls.
            LocationResource::make($location->loadMissing('productLocations.product'))
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
                $this->unprocessable(__('wms.location.parent_warehouse'));
            }
        }

        $location = Location::create($data);

        return $this->apiResponse(
            __('wms.location.created'),
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
                $this->unprocessable(__('wms.location.parent_warehouse'));
            }
        }

        $location->update($data);

        return $this->apiResponse(
            __('wms.location.updated'),
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
            __('wms.location.activated'),
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
            __('wms.location.deactivated'),
            200,
            true,
            LocationResource::make($location->fresh())
        );
    }

    /**
     * DELETE /api/v1/admin/locations/{id}
     *
     * Safe deletion guards:
     * 1. Blocks if location has child locations.
     * 2. Blocks if location holds active physical inventory (quantity > 0 or allocated_hint > 0).
     * 3. Blocks if OPEN picking tasks (pending/assigned/picking — the real
     *    open set owned by OrderPickingService/BatchPickingService/
     *    PickingExecutionService) target this location's placements.
     * 4. Blocks if ANY picking task (including terminal picked/skipped/
     *    cancelled history) references this location's placements: the
     *    product_location_id FK on picking_tasks is RESTRICT, and history
     *    is audit trail — deactivate instead of deleting.
     * 5. Atomic transaction deletes zero-stock placement records and removes the location.
     */
    public function destroy(int $id): JsonResponse
    {
        $location = Location::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($location->warehouse_id, 'manage-location');

        // 1. Guard against active child locations
        if ($location->children()->exists()) {
            return $this->apiResponse(
                __('wms.location.delete_children'),
                422,
                false
            );
        }

        // 2. Guard against physical inventory stock
        $hasStock = $location->productLocations()
            ->where(function ($q) {
                $q->where('quantity', '>', 0)
                  ->orWhere('allocated_hint', '>', 0);
            })
            ->exists();

        if ($hasStock) {
            return $this->apiResponse(
                __('wms.location.delete_stock'),
                422,
                false
            );
        }

        $placementIds = $location->productLocations()->pluck('id');

        // 3. Guard against open picking tasks linked to this location.
        // Real open set: pending/assigned/picking (never 'claimed' — no such status).
        $hasActiveTasks = $placementIds->isNotEmpty() && PickingTask::whereIn('product_location_id', $placementIds)
            ->whereIn('status', ['pending', 'assigned', 'picking'])
            ->exists();

        if ($hasActiveTasks) {
            return $this->apiResponse(
                __('wms.location.delete_tasks'),
                422,
                false
            );
        }

        // 4. Guard against picking history (any status): FK RESTRICT on
        // picking_tasks.product_location_id would 500 — refuse cleanly and
        // point the operator at deactivation (history-preserving path).
        $hasHistory = $placementIds->isNotEmpty() && PickingTask::whereIn('product_location_id', $placementIds)
            ->exists();

        if ($hasHistory) {
            return $this->apiResponse(
                __('wms.location.delete_history'),
                422,
                false
            );
        }

        // 5. Safely delete within a transaction, rechecking task guards
        // under lock: a task may have pinned a placement between the
        // pre-checks above and this write (TOCTOU) — the locked recheck is
        // authoritative and keeps the FK RESTRICT 500 unreachable.
        try {
            DB::transaction(function () use ($location, $placementIds) {
                $lockedIds = $location->productLocations()->lockForUpdate()->pluck('id');
                $raced = $lockedIds->isNotEmpty() && PickingTask::whereIn('product_location_id', $lockedIds)
                    ->lockForUpdate()
                    ->first() !== null;
                if ($raced) {
                    throw new \RuntimeException(__('wms.location.delete_history'));
                }
                $location->productLocations()->delete();
                $location->delete();
            });
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        } catch (\Illuminate\Database\QueryException $e) {
            // Final backstop: FK RESTRICT (MySQL 1451) on
            // picking_tasks.product_location_id — refuse cleanly, never 500.
            if (($e->errorInfo[1] ?? null) === 1451) {
                return $this->apiResponse(__('wms.location.delete_history'), 422, false);
            }
            throw $e;
        }

        return $this->apiResponse(__('message.MESSAGE.WMS_LOCATION_DELETED'), 200, true);
    }

    /**
     * DELETE /api/v1/admin/locations/{location}/products
     * Alias: DELETE /api/v1/admin/wms/locations/{location}/products
     *
     * Empty a Location of its eligible placement hints while keeping the
     * Location itself intact (status/type/warehouse untouched).
     *
     * - Auth via sanctum + permission:manage-location (route middleware).
     * - Object-level warehouse scope enforced here (403 on cross-warehouse).
     * - Missing location → 404 via firstOrFail.
     * - Unsafe (physical stock / open tasks / open commitments / picking
     *   history) → 422 with a translated wms.location.empty_* message.
     * - Success (including already-empty idempotent replay) → 200 with a
     *   truthful {location_id, warehouse_id, removed, removed_ids,
     *   remaining} payload. Central stock is never touched.
     */
    public function emptyProducts(int $location): JsonResponse
    {
        $loc = Location::whereKey($location)->firstOrFail();
        $this->authorizeWarehouseScope($loc->warehouse_id, 'manage-location');

        try {
            $result = $this->emptyService->empty($loc);
        } catch (\InvalidArgumentException $e) {
            // Business refusal: same envelope as destroy() guards
            // (422 + success:false), never a raw exception.
            return $this->apiResponse($e->getMessage(), 422, false);
        } catch (\Illuminate\Database\QueryException $e) {
            // Final backstop: FK RESTRICT (MySQL 1451) on
            // picking_tasks.product_location_id — refuse cleanly, never 500.
            if (($e->errorInfo[1] ?? null) === 1451) {
                return $this->apiResponse(__('wms.location.empty_history'), 422, false);
            }
            throw $e;
        }

        $message = ((int) ($result['removed'] ?? 0)) > 0
            ? __('wms.location.emptied')
            : __('wms.location.already_empty');

        return $this->apiResponse(
            $message,
            200,
            true,
            [
                'location_id' => (int) $result['location_id'],
                'warehouse_id' => (int) $result['warehouse_id'],
                'removed' => (int) $result['removed'],
                'removed_ids' => array_values($result['removed_ids'] ?? []),
                'remaining' => (int) ($result['remaining'] ?? 0),
            ]
        );
    }

    /**
     * POST /api/v1/admin/locations/check-occupancy
     *
     * Check occupancy status of an array of location IDs.
     * Categorizes bins into empty, occupied (with products and variants details), and not_found.
     */
    public function checkOccupancy(
        CheckLocationOccupancyRequest $request,
        LocationOccupancyService $occupancyService
    ): JsonResponse {
        $warehouseId = $request->input('warehouse_id');
        if ($warehouseId !== null) {
            $this->authorizeWarehouseScope((int) $warehouseId, 'view-location', true);
        }

        $result = $occupancyService->checkOccupancy(
            $request->locationIds(),
            $warehouseId !== null ? (int) $warehouseId : null,
            auth()->user()
        );

        return $this->apiResponse(
            __('wms.location.occupancy_checked'),
            200,
            true,
            $result
        );
    }
}

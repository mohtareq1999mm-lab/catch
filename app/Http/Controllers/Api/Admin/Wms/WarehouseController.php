<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\AssignWarehouseUsersRequest;
use App\Http\Requests\Admin\Wms\CreateWarehouseRequest;
use App\Http\Requests\Admin\Wms\RemoveWarehouseUsersRequest;
use App\Http\Requests\Admin\Wms\UpdateWarehouseRequest;
use App\Http\Resources\Wms\WarehouseResource;
use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\WarehouseAccess;
use App\Services\Warehouse\WarehouseDashboardService;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;

use Marvel\Enums\Permission;

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
        private WarehouseDashboardService $dashboardService,
    ) {
        $this->middleware('permission:' . Permission::WAREHOUSES_INDEX, ['only' => ['index']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_SHOW, ['only' => ['show', 'dashboard']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_CREATE, ['only' => ['store']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_UPDATE, ['only' => ['update']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_SET_DEFAULT, ['only' => ['setDefault']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_ACTIVATE, ['only' => ['activate']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_DEACTIVATE, ['only' => ['deactivate']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_DELETE, ['only' => ['destroy']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_USERS_VIEW, ['only' => ['users']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_USERS_ASSIGN, ['only' => ['assignUsers']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_USERS_REMOVE_BULK, ['only' => ['removeUsers']]);
        $this->middleware('permission:' . Permission::WAREHOUSES_USERS_REMOVE, ['only' => ['removeUser']]);
    }

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
            __('wms.warehouse.fetched_many'),
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
            __('wms.warehouse.fetched_one'),
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
            __('wms.warehouse.created'),
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
            __('wms.warehouse.updated'),
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
            __('wms.warehouse.default_updated'),
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
            __('wms.warehouse.activated'),
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
            __('wms.warehouse.deactivated'),
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

        return $this->apiResponse(__('message.MESSAGE.WAREHOUSE_ARCHIVED'), 200, true);
    }

    /**
     * GET /api/v1/admin/warehouses/{id}/users
     */
    public function users(int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'view-warehouse', true);

        $users = $warehouse->users()
            ->select('users.id', 'users.name', 'users.email', 'users.type')
            ->get();

        return $this->apiResponse(
            __('wms.warehouse.users_fetched'),
            200,
            true,
            $users
        );
    }

    /**
     * POST /api/v1/admin/warehouses/{id}/users
     */
    public function assignUsers(AssignWarehouseUsersRequest $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'manage-warehouse');

        $result = $this->warehouses->assignUsers(
            $warehouse,
            $request->validated('user_ids'),
            $request->validated('mode', 'attach')
        );

        return $this->apiResponse(
            __('wms.warehouse.users_assigned'),
            200,
            true,
            $result
        );
    }

    /**
     * DELETE /api/v1/admin/warehouses/{id}/users
     */
    public function removeUsers(RemoveWarehouseUsersRequest $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'manage-warehouse');

        $result = $this->warehouses->assignUsers(
            $warehouse,
            $request->validated('user_ids'),
            'detach'
        );

        return $this->apiResponse(
            __('wms.warehouse.users_removed'),
            200,
            true,
            $result
        );
    }

    /**
     * DELETE /api/v1/admin/warehouses/{id}/users/{userId}
     */
    public function removeUser(int $id, int $userId): JsonResponse
    {
        $warehouse = Warehouse::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'manage-warehouse');

        User::whereKey($userId)->firstOrFail();

        $result = $this->warehouses->assignUsers(
            $warehouse,
            [$userId],
            'detach'
        );

        return $this->apiResponse(
            __('wms.warehouse.users_removed'),
            200,
            true,
            $result
        );
    }

    /**
     * GET /api/v1/admin/warehouses/{id}/dashboard
     * or GET /api/v1/admin/warehouses/dashboard
     */
    public function dashboard(Request $request, ?int $id = null): JsonResponse
    {
        $warehouseId = $id;

        if ($warehouseId === null) {
            $warehouseId = $this->access->warehouseIdFor(auth()->user())
                ?? Warehouse::where('is_default', true)->value('id');
        }

        if (!$warehouseId) {
            abort(404, __('wms.warehouse.no_default'));
        }

        $warehouse = Warehouse::whereKey($warehouseId)->firstOrFail();
        $this->authorizeWarehouseScope($warehouse->id, 'view-warehouse', true);

        $data = $this->dashboardService->getDashboard($warehouse, auth()->user());

        return $this->apiResponse(
            __('wms.warehouse.dashboard_fetched'),
            200,
            true,
            $data
        );
    }

    /**
     * GET /api/v1/admin/warehouses/permissions
     */
    public function permissions(Request $request): JsonResponse
    {
        $data = $this->dashboardService->getPermissions(auth()->user());

        return $this->apiResponse(
            __('wms.warehouse.permissions_fetched'),
            200,
            true,
            $data
        );
    }
}


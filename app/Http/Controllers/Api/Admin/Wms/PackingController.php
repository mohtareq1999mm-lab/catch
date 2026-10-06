<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\AssignPackingTaskRequest;
use App\Http\Requests\Admin\Wms\BatchCommandRequest;
use App\Http\Requests\Admin\Wms\CompletePackingRequest;
use App\Http\Requests\Admin\Wms\ListPackingStationsRequest;
use App\Http\Requests\Admin\Wms\ListPackingTasksRequest;
use App\Http\Requests\Admin\Wms\VerifyPackingRequest;
use App\Http\Resources\Wms\PackingStationResource;
use App\Http\Resources\Wms\PackingTaskDetailResource;
use App\Http\Resources\Wms\PackingTaskResource;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Services\Fulfillment\PackingService;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

/**
 * P9-6: packing-task + station HTTP adapter. Thin: permission (route) +
 * warehouse scope (task → fulfillment.warehouse_id, station → warehouse_id)
 * + request validation, then PackingService. No transactions, no guards,
 * no lifecycle writes here. Packing → fulfillment moves run inside the
 * service via the single transition owner.
 */
class PackingController extends WmsAdminController
{
    public function __construct(
        private PackingService $packing,
        private WarehouseAccess $access,
    ) {}

    private function taskWarehouseId(PackingTask $task): int
    {
        return (int) $task->fulfillment()->value('warehouse_id');
    }

    /**
     * GET /api/v1/admin/packing-tasks
     */
    public function index(ListPackingTasksRequest $request): JsonResponse
    {
        $query = PackingTask::query()->orderByDesc('id')
            ->with(['packingStation:id,code,name,warehouse_id']);

        if (auth()->user()->can('manage-warehouse')) {
            $filter = (int) $request->validated('warehouse_id', 0) ?: null;
            if ($filter !== null) {
                $query->whereHas('fulfillment', fn ($q) => $q->where('warehouse_id', $filter));
            }
        } else {
            $home = $this->access->warehouseIdFor(auth()->user());
            if ($home === null) {
                throw new AuthorizationException('Forbidden warehouse operation.');
            }
            $query->whereHas('fulfillment', fn ($q) => $q->where('warehouse_id', $home));
        }

        if ($request->validated('station_id') !== null) {
            $station = PackingStation::whereKey((int) $request->validated('station_id'))->firstOrFail();
            $this->authorizeWarehouseScope($station->warehouse_id, 'view-fulfillment', true);
            $query->where('packing_station_id', $station->id);
        }

        if ($request->validated('fulfillment_id') !== null) {
            $fulfillment = Fulfillment::whereKey((int) $request->validated('fulfillment_id'))->firstOrFail();
            $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'view-fulfillment', true);
            $query->where('fulfillment_id', $fulfillment->id);
        }

        foreach (['status', 'assigned_to'] as $field) {
            $value = $request->validated($field);
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        $perPage = min((int) ($request->validated('limit') ?? 15), 100);

        return $this->apiResponse(
            'Packing tasks fetched successfully.',
            200,
            true,
            PackingTaskResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/packing-tasks/{id}
     */
    public function show(int $id): JsonResponse
    {
        $task = PackingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'view-fulfillment', true);

        $task->loadMissing(['packingStation:id,code,name,warehouse_id']);

        return $this->apiResponse(
            'Packing task fetched successfully.',
            200,
            true,
            PackingTaskDetailResource::make($task)
        );
    }

    /**
     * POST /api/v1/admin/fulfillments/{id}/create-packing-task
     */
    public function createTask(int $id): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'packing-execute');

        try {
            $task = $this->packing->createPackingTaskFromFulfillment($fulfillment);
        } catch (\RuntimeException $e) {
            // At-most-one-open-task guard → 409; wrong-status stays 422.
            if (str_contains($e->getMessage(), 'already has an open packing task')) {
                $this->conflict($e->getMessage());
            }
            $this->unprocessable($e->getMessage());
        } catch (\Exception $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Packing task created successfully.',
            201,
            true,
            PackingTaskDetailResource::make($task->fresh()->loadMissing('packingStation:id,code,name,warehouse_id'))
        );
    }

    /**
     * POST /api/v1/admin/packing-tasks/{id}/assign
     *
     * The assignee is always the authenticated caller. A foreign station is
     * refused by the service (422): station/warehouse mismatch never assigns.
     */
    public function assign(AssignPackingTaskRequest $request, int $id): JsonResponse
    {
        $task = PackingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'packing-execute');

        try {
            $assigned = $this->packing->assignToStation(
                $task,
                (int) $request->validated('station_id'),
                $this->actorId()
            );
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already assigned to user')) {
                $this->conflict($e->getMessage());
            }
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Packing task assigned successfully.',
            200,
            true,
            PackingTaskDetailResource::make($assigned->fresh()->loadMissing('packingStation:id,code,name,warehouse_id'))
        );
    }

    /**
     * POST /api/v1/admin/packing-tasks/{id}/start
     */
    public function start(int $id): JsonResponse
    {
        $task = PackingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'packing-execute');

        try {
            $started = $this->packing->startPacking($task);
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Packing started successfully.',
            200,
            true,
            PackingTaskDetailResource::make($started->fresh()->loadMissing('packingStation:id,code,name,warehouse_id'))
        );
    }

    /**
     * POST /api/v1/admin/packing-tasks/{id}/pack
     *
     * Completion is supervisor-gated (packing.complete): the packer executes,
     * a supervisor records completion. Double completion is refused (422):
     * no last-writer-wins on weight/dimensions.
     */
    public function pack(CompletePackingRequest $request, int $id): JsonResponse
    {
        $task = PackingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'packing.complete');

        try {
            $packed = $this->packing->completePacking(
                $task,
                (float) $request->validated('weight'),
                (array) $request->validated('dimensions'),
                $request->validated('materials'),
                $request->validated('notes')
            );
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Packing completed successfully.',
            200,
            true,
            PackingTaskDetailResource::make($packed->fresh()->loadMissing('packingStation:id,code,name,warehouse_id'))
        );
    }

    /**
     * POST /api/v1/admin/packing-tasks/{id}/verify
     *
     * Verification advances the fulfillment to ready_to_ship inside the
     * service — only when no sibling task is open and every picked unit is
     * fully packaged (D6-3). Both refusals surface as 422.
     */
    public function verify(VerifyPackingRequest $request, int $id): JsonResponse
    {
        $task = PackingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'packing.complete');

        try {
            $verified = $this->packing->verifyPacking($task, $request->validated('notes'));
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Packing verified successfully.',
            200,
            true,
            PackingTaskDetailResource::make($verified->fresh()->loadMissing('packingStation:id,code,name,warehouse_id'))
        );
    }

    /**
     * POST /api/v1/admin/packing-tasks/{id}/cancel
     *
     * Packed/verified tasks cannot silently downgrade (422): verify them or
     * leave them. Cancellation records the reason on the retained row.
     */
    public function cancel(BatchCommandRequest $request, int $id): JsonResponse
    {
        $task = PackingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'manage-fulfillment');

        try {
            $cancelled = $this->packing->cancelTask($task, (string) $request->validated('reason'));
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Packing task cancelled successfully.',
            200,
            true,
            PackingTaskDetailResource::make($cancelled->fresh()->loadMissing('packingStation:id,code,name,warehouse_id'))
        );
    }

    /**
     * GET /api/v1/admin/packing-stations
     */
    public function stations(ListPackingStationsRequest $request): JsonResponse
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
            'Packing stations fetched successfully.',
            200,
            true,
            PackingStationResource::collection($query->paginate($perPage))
        );
    }
}

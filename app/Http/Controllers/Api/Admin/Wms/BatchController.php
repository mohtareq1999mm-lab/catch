<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\ListBatchesRequest;
use App\Http\Requests\Admin\Wms\AssignBatchRequest;
use App\Http\Requests\Admin\Wms\BatchCommandRequest;
use App\Http\Requests\Admin\Wms\CreateBatchRequest;
use App\Http\Resources\Wms\BatchDetailResource;
use App\Http\Resources\Wms\BatchResource;
use App\Http\Resources\Wms\FulfillmentResource;
use App\Http\Resources\Wms\PickingTaskResource;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Warehouse\WarehouseAccess;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P9-4: batch HTTP adapter. Thin: permission (route) + warehouse scope on
 * batch.warehouse_id + request validation, then BatchPickingService. No
 * transactions, no guards, no lifecycle writes here.
 *
 * P9-4.1: reads only. Commands land in P9-4.3.
 */
class BatchController extends WmsAdminController
{
    public function __construct(
        private BatchPickingService $batches,
        private WarehouseService $warehouses,
        private WarehouseAccess $access,
    ) {}

    /**
     * GET /api/v1/admin/batches
     */
    public function index(ListBatchesRequest $request): JsonResponse
    {
        $query = FulfillmentBatch::query()->orderByDesc('id');

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

        foreach (['status', 'assigned_to'] as $field) {
            $value = $request->validated($field);
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        $perPage = min((int) ($request->validated('limit') ?? 15), 100);

        return $this->apiResponse(
            'Batches fetched successfully.',
            200,
            true,
            BatchResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/batches/{id}
     */
    public function show(int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'view-fulfillment', true);

        $batch->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]);

        return $this->apiResponse(
            'Batch fetched successfully.',
            200,
            true,
            BatchDetailResource::make($batch)
        );
    }

    /**
     * GET /api/v1/admin/batches/{id}/next-task
     */
    public function nextTask(int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'view-fulfillment', true);

        $task = $this->batches->getNextTask($batch->fresh());
        if ($task === null) {
            return $this->apiResponse('No pending tasks in this batch.', 200, true);
        }

        return $this->apiResponse(
            'Next picking task fetched successfully.',
            200,
            true,
            PickingTaskResource::make($task)
        );
    }

    /**
     * GET /api/v1/admin/batches/pending-fulfillments
     */
    public function pendingFulfillments(Request $request): JsonResponse
    {
        $warehouseId = (int) $request->get('warehouse_id', 0) ?: null;
        $limit = min(max((int) $request->get('limit', 10), 1), 100);

        try {
            $resolvedId = $warehouseId ?? $this->warehouses->resolveForNewFulfillment()->id;
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }
        if ($warehouseId !== null) {
            \App\Models\Fulfillment\Warehouse::whereKey($warehouseId)->firstOrFail();
        }
        // Filtered read: anti-enumeration (P9-2 location-index precedent).
        $this->authorizeWarehouseScope($resolvedId, 'view-fulfillment', true);

        $fulfillments = $this->batches->getPendingFulfillments($resolvedId, $limit);

        return $this->apiResponse(
            'Pending fulfillments fetched successfully.',
            200,
            true,
            FulfillmentResource::collection($fulfillments)
        );
    }

    /**
     * POST /api/v1/admin/batches
     *
     * No idempotency key by design (§25): double POST creates two batches.
     * The service serializes concurrent creators via locked rechecks.
     */
    public function store(CreateBatchRequest $request): JsonResponse
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
            \App\Models\Fulfillment\Warehouse::whereKey($explicitWarehouseId)->firstOrFail();
        }
        $this->authorizeWarehouseScope($targetWarehouseId, 'batch.manage');

        $fulfillments = Fulfillment::whereIn('id', $data['fulfillment_ids'])->get();

        try {
            $batch = $this->batches->createBatchFromFulfillments(
                $fulfillments,
                $explicitWarehouseId,
                $data['type'] ?? 'wave'
            );
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Batch created successfully.',
            201,
            true,
            BatchDetailResource::make($batch->fresh()->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]))
        );
    }

    /**
     * POST /api/v1/admin/batches/{id}/assign
     */
    public function assign(AssignBatchRequest $request, int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'batch.operate');

        // Terminal/wrong-state batches refuse with 422; live contention
        // surfaces as 409 from the service below.
        if (!in_array($batch->status, ['pending', 'assigned'], true)) {
            $this->unprocessable("Cannot assign batch in status: {$batch->status}");
        }

        try {
            $assigned = $this->batches->assignBatch($batch, (int) $request->validated('user_id'));
        } catch (\RuntimeException $e) {
            $this->conflict($e->getMessage());
        }

        return $this->apiResponse(
            'Batch assigned successfully.',
            200,
            true,
            BatchDetailResource::make($assigned->fresh()->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]))
        );
    }

    /**
     * POST /api/v1/admin/batches/{id}/start
     */
    public function start(int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'batch.operate');

        try {
            $started = $this->batches->startPicking($batch);
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Batch picking started successfully.',
            200,
            true,
            BatchDetailResource::make($started->fresh()->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]))
        );
    }

    /**
     * POST /api/v1/admin/batches/{id}/refresh-progress
     *
     * P9-5: exposes the batch completion authority for the scan flow
     * (PickingExecutionService::confirm × N, then this refresh).
     * Idempotent: not-ready and already-completed batches are no-ops
     * returning the current detail. Ready batches complete with a stable
     * timestamp and advance fully-picked fulfillments via the single
     * transition owner.
     */
    public function refreshProgress(int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'batch.operate');

        try {
            $refreshed = $this->batches->refreshBatchProgress($batch);
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            $refreshed->status === 'completed'
                ? 'Batch completed successfully.'
                : 'Batch progress refreshed successfully.',
            200,
            true,
            BatchDetailResource::make($refreshed->fresh()->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]))
        );
    }

    /**
     * POST /api/v1/admin/batches/{id}/cancel
     */
    public function cancel(BatchCommandRequest $request, int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'batch.manage');

        try {
            $cancelled = $this->batches->cancelBatch($batch, (string) $request->validated('reason'));
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\Exception $e) {
            // The service signals terminal batches with base \Exception.
            // Narrow by design: this endpoint performs no other fallible work.
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Batch cancelled successfully.',
            200,
            true,
            BatchDetailResource::make($cancelled->fresh()->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]))
        );
    }

    /**
     * POST /api/v1/admin/batches/{id}/retry
     */
    public function retry(BatchCommandRequest $request, int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'batch.manage');

        try {
            $created = $this->batches->retrySkippedTasks($batch, (string) $request->validated('reason'));
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\RuntimeException $e) {
            // Exactly-one-open-task conflicts surface as 409; terminal
            // batches and other refusals stay 422. The service message for
            // the open-task guard is stable and covered by domain tests.
            if (str_contains($e->getMessage(), 'already has open picking task')) {
                $this->conflict($e->getMessage());
            }
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            count($created) > 0 ? 'Skipped tasks retried successfully.' : 'Nothing retryable in this batch.',
            200,
            true,
            PickingTaskResource::collection($created)
        );
    }
}

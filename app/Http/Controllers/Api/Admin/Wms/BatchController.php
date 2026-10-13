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
            __('wms.batch.fetched_many'),
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
            __('wms.batch.fetched_one'),
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
            return $this->apiResponse(__('message.MESSAGE.BATCH_NO_PENDING_TASKS'), 200, true);
        }

        return $this->apiResponse(
            __('wms.batch.next_task'),
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
            __('wms.batch.pending_fulfillments'),
            200,
            true,
            FulfillmentResource::collection($fulfillments)
        );
    }

    /**
     * POST /api/v1/admin/batches
     *
     * Idempotent when idempotency_key is provided: a replay returns the
     * original batch with HTTP 200; a fresh creation returns HTTP 201.
     * Keyless calls keep the legacy behavior (one call = one batch).
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

        if (isset($data['fulfillment_ids']) && is_array($data['fulfillment_ids'])) {
            $fulfillments = Fulfillment::whereIn('id', $data['fulfillment_ids'])->get();
        } else {
            $fulfillments = Fulfillment::whereIn('order_id', (array) ($data['order_ids'] ?? []))->get();
        }
        $idempotencyKey = isset($data['idempotency_key']) && trim((string) $data['idempotency_key']) !== ''
            ? trim((string) $data['idempotency_key'])
            : null;

        // Read-only replay probe for the 200/201 distinction (the service
        // remains the idempotency authority under lock). Warehouse-scoped so
        // a foreign-warehouse key never leaks existence or detail here.
        $replayed = $idempotencyKey !== null
            && FulfillmentBatch::where('idempotency_key', $idempotencyKey)
                ->where('warehouse_id', $targetWarehouseId)
                ->exists();

        try {
            $batch = $this->batches->createBatchFromFulfillments(
                $fulfillments,
                $explicitWarehouseId,
                $data['type'] ?? 'wave',
                $idempotencyKey
            );
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            // Narrow duplicate-key fallback for the idempotency race ONLY:
            // the UNIQUE(idempotency_key) backstop won for a concurrent
            // writer — reload their row as a replay (warehouse-scoped, so a
            // foreign-warehouse key never leaks). Anything else rethrows.
            $existing = ($idempotencyKey !== null && $this->isDuplicateKey($e))
                ? FulfillmentBatch::where('idempotency_key', $idempotencyKey)
                    ->where('warehouse_id', $targetWarehouseId)
                    ->first()
                : null;
            if ($existing === null) {
                throw $e;
            }
            $batch = $existing;
            $replayed = true;
        }

        return $this->apiResponse(
            $replayed ? __('wms.batch.replay') : __('wms.batch.created'),
            $replayed ? 200 : 201,
            true,
            BatchDetailResource::make($batch->fresh()->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]))
        );
    }

    /**
     * Duplicate-key detector for the idempotency race fallback. Matches
     * ONLY engine-specific unique-violation codes (MySQL 1062, SQLite 19,
     * PostgreSQL 23505) — bare SQLSTATE 23000 is excluded because it also
     * covers FK / NOT-NULL violations. Anything else is rethrown, never
     * replayed.
     */
    private function isDuplicateKey(\Illuminate\Database\QueryException $e): bool
    {
        $info = $e->errorInfo ?? null;
        if (!is_array($info)) {
            return false;
        }

        return ($info[1] ?? null) === 1062
            || ($info[1] ?? null) === 19
            || ($info[0] ?? null) === '23505';
    }

    /**
     * POST /api/v1/admin/batches/{id}/assign
     *
     * First-winner-wins by default (409 on contention). Explicit
     * reassign=true performs a privileged reassignment and requires
     * batch.manage authority on the batch warehouse.
     */
    public function assign(AssignBatchRequest $request, int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'batch.operate');

        // Terminal/wrong-state batches refuse with 422; live contention
        // surfaces as 409 from the service below.
        if (!in_array($batch->status, ['pending', 'assigned'], true)) {
            $this->unprocessable(__('wms.batch.assign_status', ['status' => $batch->status]));
        }

        $reassign = (bool) $request->validated('reassign', false);
        $targetUser = (int) $request->validated('user_id');
        // Reassignment intent always needs batch.manage (TOCTOU-safe: the
        // decision cannot rely on the pre-lock assigned_to snapshot).
        if ($reassign) {
            $this->authorizeWarehouseScope($batch->warehouse_id, 'batch.manage');
        }

        try {
            $assigned = $this->batches->assignBatch($batch, $targetUser, $reassign);
        } catch (\App\Exceptions\WmsConflictException $e) {
            // Live contention surfaces as 409 from the service.
            $this->conflict($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        $wasTakeover = $assigned->assigned_to !== null && (int) $assigned->assigned_to === $targetUser
            && $batch->assigned_to !== null && (int) $batch->assigned_to !== $targetUser;

        return $this->apiResponse(
            ($reassign && $wasTakeover) ? __('wms.batch.reassigned') : __('wms.batch.assigned'),
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
            __('wms.batch.started'),
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
                ? __('wms.batch.completed')
                : __('wms.batch.progress'),
            200,
            true,
            BatchDetailResource::make($refreshed->fresh()->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]))
        );
    }

    /**
     * POST /api/v1/admin/batches/{id}/complete
     *
     * Strict completion: rejects with 422 while required tasks remain
     * incomplete. Already-completed batches are idempotent no-ops.
     * refresh-progress stays as the lenient progress probe for scan flows.
     */
    public function complete(int $id): JsonResponse
    {
        $batch = FulfillmentBatch::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($batch->warehouse_id, 'batch.operate');

        try {
            $completed = $this->batches->completeBatch($batch);
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.batch.completed'),
            200,
            true,
            BatchDetailResource::make($completed->fresh()->loadMissing(['pickingTasks' => fn ($q) => $q->orderBy('sequence')]))
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
            __('wms.batch.cancelled'),
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
        } catch (\App\Exceptions\WmsConflictException $e) {
            // Exactly-one-open-task contention surfaces as 409; terminal
            // batches and other refusals stay 422. Typed by the service so
            // locale can never change the status code.
            $this->conflict($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            count($created) > 0 ? __('wms.batch.retried') : __('wms.batch.nothing_retry'),
            200,
            true,
            PickingTaskResource::collection($created)
        );
    }
}

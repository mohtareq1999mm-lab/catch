<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\AssignPlacementRequest;
use App\Http\Requests\Admin\Wms\ConfirmPickRequest;
use App\Http\Requests\Admin\Wms\ListPickingTasksRequest;
use App\Http\Requests\Admin\Wms\ReallocateTaskRequest;
use App\Http\Requests\Admin\Wms\RecordPickRequest;
use App\Http\Requests\Admin\Wms\SkipTaskRequest;
use App\Http\Resources\Wms\FulfillmentDetailResource;
use App\Http\Resources\Wms\FulfillmentItemResource;
use App\Http\Resources\Wms\PickingTaskDetailResource;
use App\Http\Resources\Wms\PickingTaskResource;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PickingTask;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\OrderPickingService;
use App\Services\Fulfillment\PickingExecutionService;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P9-4: picking-task HTTP adapter. Thin: permission (route) + warehouse
 * scope (resolved task → item → fulfillment, never request data) + request
 * validation, then the picking authorities. No transactions, no guards,
 * no lifecycle writes here.
 *
 * P9-4.1: reads only. Commands land in P9-4.2.
 */
use Marvel\Enums\Permission;

class PickingController extends WmsAdminController
{
    public function __construct(
        private WarehouseAccess $access,
        private OrderPickingService $orderPicking,
        private PickingExecutionService $execution,
        private BatchPickingService $batches,
        private FulfillmentService $fulfillments,
    ) {
        $this->middleware('permission:' . Permission::PICKING_TASKS_INDEX, ['only' => ['index']]);
        $this->middleware('permission:' . Permission::PICKING_TASKS_SHOW, ['only' => ['show']]);
        $this->middleware('permission:' . Permission::FULFILLMENTS_CREATE_TASKS, ['only' => ['createTasks']]);
        $this->middleware('permission:' . Permission::PICKING_TASKS_CLAIM, ['only' => ['claim']]);
        $this->middleware('permission:' . Permission::PICKING_TASKS_RELEASE, ['only' => ['releaseClaim']]);
        $this->middleware('permission:' . Permission::PICKING_TASKS_CONFIRM, ['only' => ['confirm']]);
        $this->middleware('permission:' . Permission::PICKING_TASKS_RECORD_PICK, ['only' => ['recordPick']]);
        $this->middleware('permission:' . Permission::PICKING_TASKS_SKIP, ['only' => ['skip']]);
        $this->middleware('permission:' . Permission::PICKING_TASKS_REALLOCATE, ['only' => ['reallocate']]);
        $this->middleware('permission:' . Permission::FULFILLMENTS_COMPLETE_PICKING, ['only' => ['completePicking']]);
        $this->middleware('permission:' . Permission::FULFILLMENT_ITEMS_ASSIGN_PLACEMENT, ['only' => ['assignPlacement']]);
    }

    /**
     * GET /api/v1/admin/picking-tasks
     */
    public function index(ListPickingTasksRequest $request): JsonResponse
    {
        $query = PickingTask::query()
            ->with(['fulfillmentItem.fulfillment.assignedUser', 'batch.assignedUser'])
            ->orderByDesc('id');
        $this->applyWarehouseScope($query, $request);

        $batchId = $request->validated('batch_id');
        if ($batchId !== null) {
            $batch = FulfillmentBatch::whereKey($batchId)->firstOrFail();
            $this->authorizeWarehouseScope($batch->warehouse_id, 'picking-execute', true);
            $query->where('batch_id', $batch->id);
        }

        $fulfillmentId = $request->validated('fulfillment_id');
        if ($fulfillmentId !== null) {
            $fulfillment = Fulfillment::whereKey($fulfillmentId)->firstOrFail();
            $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'picking-execute', true);
            $query->whereHas('fulfillmentItem', fn ($q) => $q->where('fulfillment_id', $fulfillment->id));
        }

        foreach (['status'] as $field) {
            $value = $request->validated($field);
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        $assignedTo = $request->validated('assigned_to');
        if ($assignedTo !== null) {
            $assignedId = (int) $assignedTo;
            $query->where(function ($q) use ($assignedId) {
                $q->whereHas('fulfillmentItem.fulfillment', fn ($f) => $f->where('assigned_to', $assignedId))
                  ->orWhereHas('batch', fn ($b) => $b->where('assigned_to', $assignedId));
            });
        }

        if ($request->validated('mine')) {
            $userId = $this->actorId();
            $query->where(function ($q) use ($userId) {
                $q->where('claimed_by', $userId)
                  ->orWhereHas('fulfillmentItem.fulfillment', fn ($f) => $f->where('assigned_to', $userId))
                  ->orWhereHas('batch', fn ($b) => $b->where('assigned_to', $userId));
            });
        }

        $perPage = min((int) ($request->validated('limit') ?? 15), 100);

        return $this->apiResponse(
            __('wms.picking.fetched_many'),
            200,
            true,
            PickingTaskResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/picking-tasks/{id}
     */
    public function show(int $id): JsonResponse
    {
        $task = PickingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'picking-execute', true);

        $task->loadMissing([
            'fulfillmentItem.fulfillment.assignedUser',
            'fulfillmentItem.product',
            'fulfillmentItem.productVariant.attributeProducts.attributeValue.attribute',
            'batch.assignedUser',
            'productLocation.location',
        ]);

        return $this->apiResponse(
            __('wms.picking.fetched_one'),
            200,
            true,
            PickingTaskDetailResource::make($task)
        );
    }

    /**
     * POST /api/v1/admin/fulfillments/{id}/create-tasks
     *
     * WMS-PICK-001: empty results never succeed. The service throws on
     * invalid state / empty fulfillment / awaiting placement / no remaining
     * work (422 below); non-empty results are created or idempotently reused
     * tasks. No silent success with data:[].
     */
    public function createTasks(int $id): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'picking-execute');

        try {
            $tasks = $this->orderPicking->createTasksForFulfillment($fulfillment);
        } catch (\App\Exceptions\WmsConflictException $e) {
            $this->conflict($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.picking.ready'),
            200,
            true,
            PickingTaskResource::collection($tasks)
        );
    }

    /**
     * POST /api/v1/admin/picking-tasks/{id}/claim
     */
    public function claim(Request $request, int $id): JsonResponse
    {
        $task = PickingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'picking-execute');

        // Terminal tasks refuse with 422 (illegal state); live contention
        // surfaces as 409 from the service below.
        if (!in_array($task->status, ['pending', 'assigned', 'picking'], true)) {
            $this->unprocessable(__('wms.picking.claim_terminal', ['id' => $task->id, 'status' => $task->status]));
        }

        try {
            $claimed = $this->execution->claim(
                $task,
                $this->actorId(),
                null,
                $this->resolveOverride($request)
            );
        } catch (\RuntimeException $e) {
            // Race backstop: the row turned terminal under lock after the
            // read above — still a 422 business refusal, never a 409 and
            // never a silent claim on completed work. Checked on fresh state.
            $freshStatus = PickingTask::whereKey($task->id)->value('status');
            if (in_array($freshStatus, ['picked', 'skipped', 'cancelled'], true)) {
                $this->unprocessable($e->getMessage());
            }
            $this->conflict($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.picking.claimed'),
            200,
            true,
            PickingTaskDetailResource::make($claimed->fresh()->loadMissing(['fulfillmentItem.fulfillment', 'productLocation.location']))
        );
    }

    /**
     * POST /api/v1/admin/picking-tasks/{id}/release
     */
    public function releaseClaim(Request $request, int $id): JsonResponse
    {
        $task = PickingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'picking-execute');

        // Defensive read gate: terminal tasks refuse here (422); the service
        // owns the claimant check AND the authoritative terminal guard
        // (WMS-RELEASE-001) for races between this read and the row lock.
        if (!in_array($task->status, ['pending', 'assigned', 'picking'], true)) {
            $this->unprocessable(__('wms.picking.release_terminal', ['id' => $task->id, 'status' => $task->status]));
        }

        try {
            $released = $this->execution->releaseClaim(
                $task,
                $this->actorId(),
                $this->resolveOverride($request)
            );
        } catch (\RuntimeException $e) {
            // Race backstop: the row turned terminal under lock after the
            // read above — still a 422 business refusal, never a 403 and
            // never a silent resurrection. Checked on fresh state (locale-proof).
            $freshStatus = PickingTask::whereKey($task->id)->value('status');
            if (in_array($freshStatus, ['picked', 'skipped', 'cancelled'], true)) {
                $this->unprocessable($e->getMessage());
            }
            throw new AuthorizationException($e->getMessage(), $e);
        }

        return $this->apiResponse(
            __('wms.picking.released'),
            200,
            true,
            PickingTaskDetailResource::make($released->fresh()->loadMissing(['fulfillmentItem.fulfillment', 'productLocation.location']))
        );
    }

    /**
     * POST /api/v1/admin/picking-tasks/{id}/confirm
     */
    public function confirm(ConfirmPickRequest $request, int $id): JsonResponse
    {
        $task = PickingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'picking-execute');

        try {
            $confirmed = $this->execution->confirm(
                $task,
                [
                    'location' => (string) $request->validated('location'),
                    // Raw string: the resolver accepts SKU/barcode strings
                    // AND numeric IDs (ctype_digit fallback). An (int) cast
                    // here destroys string SKUs ('SKU-1' → 0 → 422).
                    'product' => (string) $request->validated('product'),
                    'quantity' => (float) $request->validated('quantity'),
                    'op_seq' => (int) ($request->validated('op_seq') ?? 0),
                ],
                $this->actorId(),
                $this->resolveOverride($request)
            );
        } catch (\RuntimeException $e) {
            // Includes PickingValidationException + UnknownBarcodeException
            // (both extend RuntimeException): scan/business refusals → 422.
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.picking.confirmed'),
            200,
            true,
            PickingTaskDetailResource::make($confirmed->fresh()->loadMissing(['fulfillmentItem.fulfillment', 'productLocation.location']))
        );
    }

    /**
     * POST /api/v1/admin/picking-tasks/{id}/record-pick
     */
    public function recordPick(RecordPickRequest $request, int $id): JsonResponse
    {
        $task = PickingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'picking-execute');

        try {
            $recorded = $this->batches->recordPick(
                $task,
                (float) $request->validated('quantity'),
                $request->validated('notes'),
                $this->actorId(),
                $this->resolveOverride($request),
                $request->validated('op_seq')
            );
        } catch (\Exception $e) {
            // The service signals business refusals with base \Exception;
            // unexpected failures surface here as 422 with the message.
            // Narrow by design: this endpoint performs no other fallible work.
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.picking.recorded'),
            200,
            true,
            PickingTaskDetailResource::make($recorded->fresh()->loadMissing(['fulfillmentItem.fulfillment', 'productLocation.location']))
        );
    }

    /**
     * POST /api/v1/admin/picking-tasks/{id}/skip
     */
    public function skip(SkipTaskRequest $request, int $id): JsonResponse
    {
        $task = PickingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'picking-execute');

        try {
            $skipped = $this->batches->skipTask($task, (string) $request->validated('reason'));
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.picking.skipped'),
            200,
            true,
            PickingTaskDetailResource::make($skipped->fresh()->loadMissing(['fulfillmentItem.fulfillment', 'productLocation.location']))
        );
    }

    /**
     * POST /api/v1/admin/picking-tasks/{id}/reallocate
     */
    public function reallocate(ReallocateTaskRequest $request, int $id): JsonResponse
    {
        $task = PickingTask::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->taskWarehouseId($task), 'picking-execute');

        try {
            $moved = $this->execution->reallocateTask(
                $task,
                (int) $request->validated('product_location_id')
            );
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.picking.reallocated'),
            200,
            true,
            PickingTaskDetailResource::make($moved->fresh()->loadMissing(['fulfillmentItem.fulfillment', 'productLocation.location']))
        );
    }

    /**
     * POST /api/v1/admin/fulfillments/{id}/complete-picking
     *
     * Explicit order-flow advance (picking → picked) via OrderPickingService.
     * No target status accepted — the service owns the transition.
     */
    public function completePicking(int $id): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'manage-fulfillment');

        try {
            $completed = $this->orderPicking->completePicking($fulfillment, [
                'actor_id' => $this->actorId(),
            ]);
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.picking.picking_completed'),
            200,
            true,
            FulfillmentDetailResource::make($this->loadFulfillmentDetail($completed->fresh()))
        );
    }

    /**
     * POST /api/v1/admin/fulfillment-items/{id}/assign-placement
     */
    public function assignPlacement(AssignPlacementRequest $request, int $id): JsonResponse
    {
        $item = FulfillmentItem::whereKey($id)->firstOrFail();
        $fulfillment = $item->fulfillment()->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'manage-fulfillment');

        try {
            $assigned = $this->fulfillments->assignPlacement(
                $item,
                (int) $request->validated('product_location_id')
            );
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            __('wms.picking.placement_assigned'),
            200,
            true,
            FulfillmentItemResource::make($assigned->fresh()->loadMissing('productLocation.location'))
        );
    }

    /**
     * Effective override = request intent AND server-side
     * fulfillment-override permission. Unauthorized intent → 403, never
     * silent elevation.
     */
    private function resolveOverride(Request $request): bool
    {
        if (!$request->boolean('override')) {
            return false;
        }
        if (!auth()->user()->can('fulfillment-override')) {
            throw new AuthorizationException('Override requires fulfillment-override permission.');
        }

        return true;
    }

    /**
     * Bounded fulfillment detail for complete-picking responses. Mirrors the
     * P9-3 loader shape (relations, not logic).
     */
    private function loadFulfillmentDetail(Fulfillment $fulfillment): Fulfillment
    {
        $fulfillment->loadMissing([
            'order', 'warehouse', 'assignedUser', 'items', 'packingTasks', 'shipments',
        ]);

        $itemIds = $fulfillment->items->pluck('id');
        $fulfillment->setRelation(
            'pickingTasksManual',
            $itemIds->isNotEmpty()
                ? PickingTask::whereIn('fulfillment_item_id', $itemIds->all())->orderBy('id')->get()
                : collect()
        );
        $fulfillment->setRelation(
            'packagesManual',
            Package::where('fulfillment_id', $fulfillment->id)->orderBy('id')->get()
        );

        return $fulfillment;
    }

    /**
     * Constrain a task query to the actor's warehouse scope. Global
     * manage-warehouse holders see everything (optional filter still
     * honored); everyone else is confined to home (null home → 403).
     */
    private function applyWarehouseScope($query, ListPickingTasksRequest $request): void
    {
        if (auth()->user()->can('manage-warehouse')) {
            $filter = (int) $request->validated('warehouse_id', 0) ?: null;
            if ($filter !== null) {
                $query->whereHas(
                    'fulfillmentItem.fulfillment',
                    fn ($q) => $q->where('warehouse_id', $filter)
                );
            }

            return;
        }

        $home = $this->access->warehouseIdFor(auth()->user());
        if ($home === null) {
            throw new AuthorizationException('Forbidden warehouse operation.');
        }

        $filter = (int) $request->validated('warehouse_id', 0) ?: null;
        if ($filter !== null && $filter !== $home) {
            $this->authorizeWarehouseScope($filter, 'picking-execute', true);
        }
        $query->whereHas(
            'fulfillmentItem.fulfillment',
            fn ($q) => $q->where('warehouse_id', $home)
        );
    }

    /**
     * Authoritative task warehouse: task → item → fulfillment. Null at any
     * hop fails closed (denyUnless rejects null targets).
     */
    private function taskWarehouseId(PickingTask $task): ?int
    {
        $item = $task->fulfillmentItem()->first();

        return $item?->fulfillment()->value('warehouse_id');
    }
}

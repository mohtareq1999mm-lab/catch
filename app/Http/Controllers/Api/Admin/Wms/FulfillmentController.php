<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\AssignFulfillmentRequest;
use App\Http\Requests\Admin\Wms\CancelFulfillmentRequest;
use App\Http\Requests\Admin\Wms\ListFulfillmentsRequest;
use App\Http\Requests\Admin\Wms\ReleaseFulfillmentRequest;
use App\Http\Resources\Wms\FulfillmentDetailResource;
use App\Http\Resources\Wms\FulfillmentResource;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Warehouse\WarehouseAccess;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Order;

/**
 * P9-3: fulfillment HTTP adapter. Thin: permission (route) + warehouse scope
 * + request validation, then FulfillmentService. No transactions, no guards,
 * no lifecycle writes here — those live in the service and the transition
 * owner. No Order/inventory/payment/shipment writes outside the service.
 */
class FulfillmentController extends WmsAdminController
{
    public function __construct(
        private FulfillmentService $fulfillments,
        private WarehouseService $warehouses,
        private WarehouseAccess $access,
    ) {}

    /**
     * GET /api/v1/admin/fulfillments
     */
    public function index(ListFulfillmentsRequest $request): JsonResponse
    {
        $query = Fulfillment::query()->orderByDesc('id');

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

        foreach (['order_id', 'status', 'assigned_to'] as $field) {
            $value = $request->validated($field);
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        $perPage = min((int) ($request->validated('limit') ?? 15), 100);
        $page = FulfillmentResource::collection(
            $query->withCount(['items', 'shipments'])->paginate($perPage)
        );

        return $this->apiResponse('Fulfillments fetched successfully.', 200, true, $page);
    }

    /**
     * GET /api/v1/admin/fulfillments/{id}
     */
    public function show(int $id): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'view-fulfillment', true);

        return $this->apiResponse(
            'Fulfillment fetched successfully.',
            200,
            true,
            FulfillmentDetailResource::make($this->loadDetail($fulfillment))
        );
    }

    /**
     * POST /api/v1/admin/fulfillments/release
     */
    public function release(ReleaseFulfillmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve-then-scope: the request's warehouse_id only identifies the
        // intended target; scope is enforced against the resolved warehouse.
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
        $this->authorizeWarehouseScope($targetWarehouseId, 'fulfillment.create');

        $order = Order::whereKey($data['order_id'])->firstOrFail();
        $idempotencyKey = $data['idempotency_key'] ?? null;

        // Replay hint for the 200/201 distinction (read-only; the service
        // remains the idempotency authority — a lost race only mistakes the
        // status code, never the domain outcome).
        $replayed = $this->findReplay($order->id, $targetWarehouseId, $idempotencyKey) !== null;

        try {
            $fulfillment = $this->fulfillments->releaseForOrder(
                $order,
                $explicitWarehouseId,
                $idempotencyKey
            );
        } catch (QueryException $e) {
            // Narrow duplicate-key fallback for the idempotency race ONLY:
            // the UNIQUE(idempotency_key) backstop won for a concurrent
            // writer — reload their row as a replay. Any other database
            // failure (or a keyless call, which cannot collide) is rethrown
            // and never converted into a false 200 success.
            $existing = ($idempotencyKey !== null && $this->isDuplicateKey($e))
                ? Fulfillment::where('idempotency_key', $idempotencyKey)->first()
                : null;
            if ($existing === null) {
                throw $e;
            }
            $fulfillment = $existing;
            $replayed = true;
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            $replayed ? 'Fulfillment already released.' : 'Fulfillment released successfully.',
            $replayed ? 200 : 201,
            true,
            FulfillmentDetailResource::make($this->loadDetail($fulfillment->fresh()))
        );
    }

    /**
     * POST /api/v1/admin/fulfillments/{id}/cancel
     */
    public function cancel(CancelFulfillmentRequest $request, int $id): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'fulfillment.cancel');

        try {
            $cancelled = $this->fulfillments->cancelFulfillment(
                $fulfillment,
                (string) $request->validated('reason'),
                [
                    'cancelled_by' => $this->actorId(),
                    'cancel_source' => 'admin_api',
                ]
            );
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Fulfillment cancelled successfully.',
            200,
            true,
            FulfillmentDetailResource::make($this->loadDetail($cancelled->fresh()))
        );
    }

    /**
     * POST /api/v1/admin/fulfillments/{id}/assign
     */
    public function assign(AssignFulfillmentRequest $request, int $id): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'manage-fulfillment');

        try {
            $assigned = $this->fulfillments->assignToUser(
                $fulfillment,
                (int) $request->validated('user_id')
            );
        } catch (\RuntimeException $e) {
            // First-winner conflict: another user already holds the task.
            $this->conflict($e->getMessage());
        }

        return $this->apiResponse(
            'Fulfillment assigned successfully.',
            200,
            true,
            FulfillmentDetailResource::make($this->loadDetail($assigned->fresh()))
        );
    }

    /**
     * Bounded detail assembly. Read-only; all relations scoped to this
     * fulfillment id. Manual relations (no Eloquent relation defined on the
     * model) are attached via setRelation for the detail resource.
     */
    private function loadDetail(Fulfillment $fulfillment): Fulfillment
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
     * Duplicate-key detector for the idempotency race fallback. MySQL
     * error 1062 only — anything else is rethrown, never replayed.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $info = $e->errorInfo ?? null;

        return is_array($info) && ($info[1] ?? null) === 1062;
    }

    /**
     * Read-only replay probe. Mirrors the service's reuse rules without
     * locking: keyed lookup, else pending (order, warehouse) reuse.
     */
    private function findReplay(int $orderId, int $warehouseId, ?string $idempotencyKey): ?Fulfillment
    {
        if ($idempotencyKey !== null) {
            return Fulfillment::where('idempotency_key', $idempotencyKey)->first();
        }

        return Fulfillment::where('order_id', $orderId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'pending')
            ->first();
    }
}

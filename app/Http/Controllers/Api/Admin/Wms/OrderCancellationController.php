<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\BatchCommandRequest;
use App\Models\Fulfillment\Fulfillment;
use App\Services\General\OrderService;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Order;

/**
 * P9-7: order-cancellation HTTP adapter for the during-fulfillment case.
 *
 * The ONLY order writer used here is OrderService::changeOrderStatus() —
 * Order Flow stays the sole lifecycle authority. Cancellation cascades to
 * open fulfillments inside that service (P7-1, same transaction); shipped /
 * terminal fulfillments are never force-cancelled and live shipments are
 * never silently orphaned (F8-5: surfaced via log for manual follow-up).
 *
 * Warehouse scope is fail-closed: a non-global actor may cancel an order
 * only when every non-cancelled fulfillment of that order sits in the
 * actor's home warehouse (a cross-warehouse cascade from a single-warehouse
 * actor would be a scope leak). Orders with no fulfillment context are
 * outside the WMS surface entirely (403 for non-global actors).
 */
class OrderCancellationController extends WmsAdminController
{
    public function __construct(
        private OrderService $orders,
        private WarehouseAccess $access,
    ) {}

    /**
     * POST /api/v1/admin/orders/{orderId}/cancel
     */
    public function cancel(BatchCommandRequest $request, int $orderId): JsonResponse
    {
        $order = Order::whereKey($orderId)->firstOrFail();

        $this->authorizeOrderScope($order);

        $result = false;
        try {
            $result = $this->orders->changeOrderStatus(
                null,
                'cancelled',
                $order->getKey(),
                true,
                (string) $request->validated('reason')
            );
        } catch (\RuntimeException $e) {
            // Flow-gate refusals and guard refusals from the cascade
            // (sealed custody, packed work, live shipment) → 422 with the
            // order untouched by partial state (atomic service transaction).
            $this->unprocessable($e->getMessage());
        }

        if ($result === false) {
            $this->unprocessable('Order cannot be cancelled in its current state.');
        }

        $order = $order->fresh();
        $fulfillments = Fulfillment::where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn ($f) => ['id' => $f->id, 'status' => $f->status])
            ->all();

        return $this->apiResponse(
            'Order cancelled successfully.',
            200,
            true,
            [
                'order_id' => $order->getKey(),
                'status' => $order->status,
                'fulfillments' => $fulfillments,
            ]
        );
    }

    private function authorizeOrderScope(Order $order): void
    {
        if (auth()->user()->can('manage-warehouse')) {
            return;
        }

        $home = $this->access->warehouseIdFor(auth()->user());
        if ($home === null) {
            throw new AuthorizationException('Forbidden warehouse operation.');
        }

        $warehouses = Fulfillment::where('order_id', $order->getKey())
            ->where('status', '!=', 'cancelled')
            ->distinct()
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($warehouses === [] || $warehouses !== [$home]) {
            throw new AuthorizationException('Forbidden warehouse operation.');
        }
    }
}

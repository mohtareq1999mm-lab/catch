<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderStatusBatchRequest;
use App\Services\General\OrderStatusBatchService;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;

/**
 * Unified Order Status Mutation: PATCH /api/v1/orders/status.
 *
 * ONE endpoint for ONE order ([id]) or MANY orders ([id, ...]) — the same
 * orchestrator and the same business pipeline either way. Each order is
 * mutated independently (own transaction, own result); one failure never
 * rolls back the batch. Processed batches always return HTTP 200 with a
 * summary + per-order results; only malformed requests are 422 and only
 * unauthenticated/unauthorized callers are 401/403.
 */
class OrderStatusBatchController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderStatusBatchService $batchService) {}

    public function update(OrderStatusBatchRequest $request): JsonResponse
    {
        $outcome = $this->batchService->updateStatuses(
            $request->user(),
            $request->validated()['order_ids'],
            (string) $request->validated()['status'],
            (array) ($request->validated()['flow_values'] ?? []),
            (array) ($request->validated()['flow_values_by_order'] ?? [])
        );

        $failed = $outcome['summary']['failed'];

        return $this->apiResponse(
            $failed === 0
                ? __('checkout.status_batch_updated')
                : __('checkout.status_batch_partial', [
                    'succeeded' => $outcome['summary']['succeeded'],
                    'total' => $outcome['summary']['total'],
                ]),
            200,
            $outcome['summary']['succeeded'] > 0,
            $outcome
        );
    }
}

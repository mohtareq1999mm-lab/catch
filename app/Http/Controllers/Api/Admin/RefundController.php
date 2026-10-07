<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Refund\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Http\Resources\GetSingleRefundResource;
use Marvel\Http\Resources\RefundResource;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 10 unification — canonical admin refund endpoints.
 *
 * Thin HTTP layer only: every decision delegates to RefundService.
 * Approval records the business refund locally (no provider call, no
 * wallet movement, no payment-status change).
 *
 *   GET  /api/v1/admin/refunds
 *   GET  /api/v1/admin/refunds/{refund}
 *   POST /api/v1/admin/refunds/{refund}/approve
 *   POST /api/v1/admin/refunds/{refund}/reject
 */
class RefundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RefundService $refunds,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->refunds->listForAdmin(
            $request->only(['status', 'order_id']),
            (int) ($request->get('limit', 15)),
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'data' => RefundResource::collection(collect($result['data']))->resolve($request),
            'meta' => $result['meta'],
            'summary' => $result['summary'],
        ]);
    }

    public function show(int $refund): JsonResponse
    {
        $row = \Marvel\Database\Models\Refund::query()
            ->with(['shop', 'order', 'customer', 'refund_policy', 'refund_reason'])
            ->find($refund);

        if (!$row) {
            return $this->apiResponse(__('message.ERROR.NOT_FOUND'), 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new GetSingleRefundResource($row));
    }

    public function approve(Request $request, int $refund): JsonResponse
    {
        $request->validate(['decision_note' => ['nullable', 'string', 'max:10000']]);

        try {
            $row = $this->refunds->approve($refund, (int) $request->user()->getAuthIdentifier(), $request->get('decision_note'));
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), $this->refundErrorStatus($e->getMessage()), false);
        }

        return $this->apiResponse(__('message.MESSAGE.REFUND_DECIDED'), 200, true, new GetSingleRefundResource(
            $row->load(['shop', 'order', 'customer', 'refund_policy', 'refund_reason'])
        ));
    }

    public function reject(Request $request, int $refund): JsonResponse
    {
        $request->validate(['decision_note' => ['nullable', 'string', 'max:10000']]);

        try {
            $row = $this->refunds->reject($refund, (int) $request->user()->getAuthIdentifier(), $request->get('decision_note'));
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), $this->refundErrorStatus($e->getMessage()), false);
        }

        return $this->apiResponse(__('message.MESSAGE.REFUND_DECIDED'), 200, true, new GetSingleRefundResource(
            $row->load(['shop', 'order', 'customer', 'refund_policy', 'refund_reason'])
        ));
    }

    private function refundErrorStatus(string $message): int
    {
        if ($message === __('message.ERROR.NOT_FOUND')) {
            return 404;
        }

        if ($message === __('message.ERROR.ALREADY_REFUNDED')) {
            return 400;
        }

        return 422;
    }
}

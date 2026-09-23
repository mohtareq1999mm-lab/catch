<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\RefundOrderRequest;
use App\Services\Payment\PaymentRefundService;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Order;
use Marvel\Traits\ApiResponse;

class PaymentRefundController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PaymentRefundService $refunds,
    ) {}

    /**
     * POST /api/v1/admin/payments/{order}/refund
     *
     * Thin admin endpoint: the FormRequest owns validation, the
     * PaymentRefundService owns every business rule and the provider call.
     * Authorization is the route's permission:payments.refund middleware.
     * Every service refusal is fail-closed as a 422; nothing here touches
     * the gateway directly.
     */
    public function refund(RefundOrderRequest $request, int $order): JsonResponse
    {
        $orderModel = Order::query()->findOrFail($order);

        try {
            $summary = $this->refunds->refund(
                $orderModel,
                (float) $request->validated('amount'),
                $request->validated('reason'),
                trim((string) $request->validated('idempotency_key')),
                (int) $request->user()->getAuthIdentifier(),
            );
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, $summary);
    }
}

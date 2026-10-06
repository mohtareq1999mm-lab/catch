<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Http\Resources\AvailableOrderFlowResource;
use App\Models\OrderFlow\OrderFlow;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;

/**
 * Flow Definition endpoints (definitions only, NEVER order values).
 *
 * - GET .../order-flows/available: canonical guest-safe discovery. Returns
 *   every ACTIVE flow (shipping_type selection contract). No auth, no
 *   internal identifiers, no admin flags.
 * - GET .../order-flows/by-shipping-type/{type}: guest-safe convenience
 *   for one flow (D8b). Same sanitized contract as available (one serializer).
 *
 * The frontend selects shipping_type (local|international); the backend
 * resolves the Flow. flow_id is never a customer contract.
 */
class FlowDefinitionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderFlowService $flows,
    ) {
        // Both discovery endpoints are guest-safe by design (D8b).
    }

    public function available(): JsonResponse
    {
        $flows = OrderFlow::query()
            ->active()
            ->with(['statuses', 'inputs' => fn ($q) => $q->active()->ordered()])
            ->orderBy('id')
            ->get();

        return $this->apiResponse('Order flow definitions retrieved successfully.', 200, true, [
            'flows' => AvailableOrderFlowResource::collection($flows)->toArray(request()),
        ]);
    }

    public function byShippingType(string $shippingType): JsonResponse
    {
        try {
            $flow = $this->flows->resolveFlowForShippingType($shippingType);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        $flow->load(['statuses', 'inputs' => fn ($q) => $q->active()->ordered()]);

        return $this->apiResponse('Order flow definition retrieved successfully.', 200, true,
            (new AvailableOrderFlowResource($flow))->toArray(request()));
    }
}

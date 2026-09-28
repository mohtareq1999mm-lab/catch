<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Http\Resources\FlowInputResource;
use App\Http\Resources\OrderFlowResource;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;

/**
 * Public Flow Definition endpoint (definitions only, NEVER order values).
 *
 * Any authenticated customer needs this BEFORE checkout to render the
 * flow's required inputs dynamically. Cached; fail-closed 422 when the
 * shipping type is unsupported or has no active flow.
 */
class FlowDefinitionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderFlowService $flows,
    ) {
        $this->middleware(['auth:sanctum']);
    }

    public function byShippingType(string $shippingType): JsonResponse
    {
        try {
            $flow = $this->flows->resolveFlowForShippingType($shippingType);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        $flow->load(['statuses', 'inputs' => fn ($q) => $q->active()->ordered()]);

        $payload = (new OrderFlowResource($flow))->toArray(request());
        $payload['inputs'] = FlowInputResource::collection($flow->inputs)->toArray(request());

        return $this->apiResponse('Order flow definition retrieved successfully.', 200, true, $payload);
    }
}

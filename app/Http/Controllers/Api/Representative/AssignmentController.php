<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Representative;

use App\Http\Controllers\Controller;
use App\Http\Requests\Representative\StoreDeliveryAttemptRequest;
use App\Http\Resources\Shipping\ShipmentAssignmentResource;
use App\Http\Resources\Shipping\ShipmentDeliveryAttemptResource;
use App\Services\Shipping\DeliveryAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    public function __construct(private readonly DeliveryAttemptService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $representative = $this->service->resolveRepresentative($request->user());
        $assignments = $this->service->listOwnedAssignments($representative);

        return response()->json([
            'data' => ShipmentAssignmentResource::collection($assignments->items()),
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
            ],
        ]);
    }

    public function show(Request $request, int $assignment): JsonResponse
    {
        $representative = $this->service->resolveRepresentative($request->user());
        $owned = $this->service->findOwnedAssignment($representative, $assignment);
        $this->authorize('view', $owned);

        return response()->json(['data' => new ShipmentAssignmentResource($owned)]);
    }

    public function storeAttempt(StoreDeliveryAttemptRequest $request, int $assignment): JsonResponse
    {
        $representative = $this->service->resolveRepresentative($request->user());
        $owned = $this->service->findOwnedAssignment($representative, $assignment);
        $this->authorize('recordAttempt', $owned);
        $attempt = $this->service->recordAttempt($owned, $request->validated());

        return response()->json([
            'data' => new ShipmentDeliveryAttemptResource($attempt),
        ], 201);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Representative;

use App\Http\Controllers\Controller;
use App\Http\Requests\Representative\StoreDeliveryAttemptRequest;
use App\Http\Resources\Shipping\ShipmentDeliveryAttemptResource;
use App\Services\Shipping\DeliveryAttemptService;
use Illuminate\Http\JsonResponse;

class AssignmentController extends Controller
{
    public function __construct(private readonly DeliveryAttemptService $service)
    {
    }

    public function storeAttempt(StoreDeliveryAttemptRequest $request, int $assignment): JsonResponse
    {
        $representative = $this->service->resolveRepresentative($request->user());
        $owned = $this->service->findActiveOwnedAssignment($representative, $assignment);
        $this->authorize('recordAttempt', $owned);
        $attempt = $this->service->recordAttempt($representative->id, $owned, $request->validated());

        return response()->json([
            'data' => new ShipmentDeliveryAttemptResource($attempt),
        ], 201);
    }
}

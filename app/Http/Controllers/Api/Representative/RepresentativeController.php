<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Representative;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shipping\DeliveryRepresentativeResource;
use App\Http\Resources\Shipping\ShipmentAssignmentResource;
use App\Http\Resources\Shipping\ShipmentDeliveryAttemptResource;
use App\Http\Resources\Shipping\ShipmentPodResource;
use App\Models\Shipping\DeliveryRepresentative;
use App\Models\Shipping\ShipmentAssignment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Models\Shipping\ShipmentPod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RepresentativeController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new DeliveryRepresentativeResource($this->representative($request))]);
    }

    public function assignments(Request $request): JsonResponse
    {
        $rep = $this->representative($request);
        $assignments = ShipmentAssignment::query()
            ->where('delivery_representative_id', $rep->id)
            ->with(['shipment'])
            ->orderByDesc('id')
            ->paginate(15);

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

    public function showAssignment(Request $request, int $assignment): JsonResponse
    {
        $rep = $this->representative($request);
        $owned = ShipmentAssignment::query()
            ->whereKey($assignment)
            ->where('delivery_representative_id', $rep->id)
            ->with(['shipment'])
            ->firstOrFail();

        return response()->json(['data' => new ShipmentAssignmentResource($owned)]);
    }

    public function showAttempt(Request $request, int $attempt): JsonResponse
    {
        $rep = $this->representative($request);
        $attemptModel = ShipmentDeliveryAttempt::query()
            ->whereKey($attempt)
            ->whereHas('assignment', fn ($q) => $q->where('delivery_representative_id', $rep->id))
            ->firstOrFail();

        return response()->json(['data' => new ShipmentDeliveryAttemptResource($attemptModel)]);
    }

    public function showPod(Request $request, int $pod): JsonResponse
    {
        $rep = $this->representative($request);
        $podModel = ShipmentPod::query()
            ->whereKey($pod)
            ->whereHas('assignment', fn ($q) => $q->where('delivery_representative_id', $rep->id))
            ->firstOrFail();

        return response()->json(['data' => new ShipmentPodResource($podModel)]);
    }

    private function representative(Request $request): DeliveryRepresentative
    {
        $representative = $request->attributes->get('deliveryRepresentative');
        if (!$representative instanceof DeliveryRepresentative) {
            abort(403, 'Active delivery representative profile required.');
        }

        return $representative;
    }
}

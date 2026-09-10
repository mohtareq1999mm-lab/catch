<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipment\CreateShipmentRequest;
use App\Http\Requests\Shipment\UpdateShipmentRequest;
use App\Http\Requests\Shipment\UpdateShipmentStatusRequest;
use App\Http\Resources\Shipment\ShipmentResource;
use App\Models\Shipment;
use App\Services\Shipment\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Traits\ApiResponse;

class       ShipmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ShipmentService $shipmentService,
    ) {
        $this->middleware('permission:' . Permission::VIEW_SHIPMENTS, ['only' => ['index']]);
        $this->middleware('permission:' . Permission::VIEW_SHIPMENT, ['only' => ['show', 'showByUuid']]);
        $this->middleware('permission:' . Permission::CREATE_SHIPMENT, ['only' => ['store']]);
        $this->middleware('permission:' . Permission::UPDATE_SHIPMENT, ['only' => ['update', 'updateStatus']]);
    }

    public function index(Request $request): JsonResponse
    {
        $shipments = $this->shipmentService->list(
            $request->only(['order_id', 'status', 'courier', 'tracking_number', 'from', 'to']),
            (int) $request->get('limit', 15),
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ShipmentResource::collection($shipments));
    }

    public function show(int $id): JsonResponse
    {
        $shipment = $this->shipmentService->find($id);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ShipmentResource::make($shipment));
    }

    public function showByUuid(string $uuid): JsonResponse
    {
        $shipment = $this->shipmentService->findByUuid($uuid);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ShipmentResource::make($shipment));
    }

    public function trackShipment(string $trackingNumber): JsonResponse
    {
        $shipment = $this->shipmentService->findByTrackingNumber($trackingNumber);

        if (!$shipment) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ShipmentResource::make($shipment));
    }

    public function store(CreateShipmentRequest $request): JsonResponse
    {
        $shipment = $this->shipmentService->create($request->validated());

        return $this->apiResponse(SHIPMENT_CREATED_SUCCESSFULLY, 201, true, ShipmentResource::make($shipment));
    }

    public function updateStatus(UpdateShipmentStatusRequest $request, int $id): JsonResponse
    {
        try {
            $shipment = $this->shipmentService->updateStatus(
                $id,
                $request->validated('status'),
                $request->validated('notes'),
            );

            return $this->apiResponse(SHIPMENT_STATUS_UPDATED, 200, true, ShipmentResource::make($shipment));
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }
    }

    public function update(UpdateShipmentRequest $request, int $id): JsonResponse
    {
        $shipment = $this->shipmentService->update($id, $request->validated());

        return $this->apiResponse(SHIPMENT_UPDATED_SUCCESSFULLY, 200, true, ShipmentResource::make($shipment));
    }
}

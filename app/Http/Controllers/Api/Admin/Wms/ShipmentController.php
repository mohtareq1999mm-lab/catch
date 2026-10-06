<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Requests\Admin\Wms\BatchCommandRequest;
use App\Http\Requests\Admin\Wms\CreateShipmentRequest;
use App\Http\Requests\Admin\Wms\ListShipmentsRequest;
use App\Http\Requests\Admin\Wms\ShipmentNotesRequest;
use App\Http\Resources\Wms\ShipmentDetailResource;
use App\Http\Resources\Wms\ShipmentResource;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Shipment;
use App\Services\Shipment\ShipmentService;
use Illuminate\Http\JsonResponse;

/**
 * P9-8: fulfillment-scoped shipment HTTP adapter.
 *
 * Scope resolves via shipment.fulfillment_id → fulfillment.warehouse_id;
 * order-only labels (null fulfillment_id) are NOT part of the WMS surface
 * and 404 here (they stay on the order shipment surface). State ownership:
 * ShipmentService owns shipment rows, FulfillmentTransition owns the
 * ready_to_ship→shipped→delivered fulfillment moves (inside the service),
 * Order Flow owns orders (maybeCompleteOrder is service-internal and
 * guarded; this adapter never writes orders.status).
 */
class ShipmentController extends WmsAdminController
{
    public function __construct(
        private ShipmentService $shipments,
    ) {}

    private function shipmentWarehouseId(Shipment $shipment): int
    {
        // Fail-closed: a WMS-surfaced shipment always has a fulfillment.
        $fulfillment = Fulfillment::whereKey((int) $shipment->fulfillment_id)->firstOrFail();

        return (int) $fulfillment->warehouse_id;
    }

    /**
     * GET /api/v1/admin/shipments?fulfillment_id=
     */
    public function index(ListShipmentsRequest $request): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey((int) $request->validated('fulfillment_id'))->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'view-shipment', true);

        $query = Shipment::query()
            ->where('fulfillment_id', $fulfillment->id)
            ->orderByDesc('id');

        $status = $request->validated('status');
        if ($status !== null) {
            $query->where('status', $status);
        }

        $perPage = min((int) ($request->validated('limit') ?? 15), 100);

        return $this->apiResponse(
            'Shipments fetched successfully.',
            200,
            true,
            ShipmentResource::collection($query->paginate($perPage))
        );
    }

    /**
     * GET /api/v1/admin/shipments/{id}
     */
    public function show(int $id): JsonResponse
    {
        $shipment = Shipment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->shipmentWarehouseId($shipment), 'view-shipment', true);

        return $this->apiResponse(
            'Shipment fetched successfully.',
            200,
            true,
            ShipmentDetailResource::make($shipment)
        );
    }

    /**
     * POST /api/v1/admin/fulfillments/{id}/shipments
     *
     * One active shipment per fulfillment (D8-1): an unkeyed duplicate
     * refuses with 409; a keyed replay for the same fulfillment returns
     * the same row (200 + existing id); the same key for a different
     * fulfillment refuses loudly (409). Cancelled / non-ready fulfillments
     * refuse with 422.
     */
    public function store(CreateShipmentRequest $request, int $id): JsonResponse
    {
        $fulfillment = Fulfillment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($fulfillment->warehouse_id, 'create-shipment');

        $data = array_filter([
            'courier' => $request->validated('courier'),
            'shipping_method' => $request->validated('shipping_method'),
            'destination_address' => $request->validated('destination_address'),
            'notes' => $request->validated('notes'),
        ], fn ($v) => $v !== null);

        $key = $request->validated('idempotency_key');
        // Response-code hint only: the service remains the replay authority
        // (fresh() clears wasRecentlyCreated, so the model cannot tell us).
        $replay = $key !== null
            && Shipment::where('idempotency_key', $key)->where('fulfillment_id', $fulfillment->id)->exists();

        try {
            $shipment = $this->shipments->createForFulfillment(
                $fulfillment,
                $data,
                $key
            );
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already has an active shipment')
                || str_contains($e->getMessage(), 'already bound to shipment')) {
                $this->conflict($e->getMessage());
            }
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            $replay ? 'Shipment already exists for this idempotency key.' : 'Shipment created successfully.',
            $replay ? 200 : 201,
            true,
            ShipmentDetailResource::make($shipment->fresh())
        );
    }

    /**
     * POST /api/v1/admin/shipments/{id}/dispatch
     *
     * Carrier handoff: label_created → picked_up, fulfillment
     * ready_to_ship → shipped inside the service. Duplicate dispatch
     * refuses loudly (422) — never a silent no-op.
     */
    public function dispatchShipment(ShipmentNotesRequest $request, int $id): JsonResponse
    {
        $shipment = Shipment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->shipmentWarehouseId($shipment), 'update-shipment');

        try {
            $dispatched = $this->shipments->dispatch($shipment->id, $request->validated('notes'));
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Shipment dispatched successfully.',
            200,
            true,
            ShipmentDetailResource::make($dispatched->fresh())
        );
    }

    /**
     * POST /api/v1/admin/shipments/{id}/deliver
     *
     * Staff-confirmed delivery walks picked_up → in_transit →
     * out_for_delivery → delivered and completes the order when the locked
     * rule holds (inside the service). Re-delivery is a safe 200 replay.
     */
    public function markDelivered(ShipmentNotesRequest $request, int $id): JsonResponse
    {
        $shipment = Shipment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->shipmentWarehouseId($shipment), 'update-shipment');

        try {
            $delivered = $this->shipments->markDelivered($shipment->id, $request->validated('notes'));
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Shipment delivered successfully.',
            200,
            true,
            ShipmentDetailResource::make($delivered->fresh())
        );
    }

    /**
     * POST /api/v1/admin/shipments/{id}/cancel
     *
     * Canonical shipment cancellation. Terminal states refuse (422); a
     * shipped/delivered fulfillment refuses — cancellation never moves
     * fulfillment backward. The row is retained with reason/source/actor.
     */
    public function cancelShipment(BatchCommandRequest $request, int $id): JsonResponse
    {
        $shipment = Shipment::whereKey($id)->firstOrFail();
        $this->authorizeWarehouseScope($this->shipmentWarehouseId($shipment), 'update-shipment');

        try {
            $cancelled = $this->shipments->cancelShipment(
                $shipment->id,
                (string) $request->validated('reason'),
                ['cancel_source' => 'admin_api', 'cancelled_by' => $this->actorId()]
            );
        } catch (\InvalidArgumentException $e) {
            $this->unprocessable($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->unprocessable($e->getMessage());
        }

        return $this->apiResponse(
            'Shipment cancelled successfully.',
            200,
            true,
            ShipmentDetailResource::make($cancelled->fresh())
        );
    }
}

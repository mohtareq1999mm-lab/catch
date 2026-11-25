<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Shipment\ShipmentService;
use Marvel\Database\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

use Marvel\Enums\Permission;

/**
 * #166: Legacy admin order shipment compatibility shim.
 *
 * Preserves the existing response shape for current consumers. Status writes
 * delegate to ShipmentService::recordOrderStatusChange; this controller must
 * not mutate shipment/order status directly. Removal criteria:
 * docs/shipping/legacy-shipment-compatibility.md.
 */
class ShipmentController extends Controller
{
    public function __construct(private ShipmentService $shipments)
    {
        $this->middleware('permission:' . Permission::ORDERS_SHIPMENT_SHOW, ['only' => ['show']]);
        $this->middleware('permission:' . Permission::ORDERS_SHIPMENT_UPDATE_STATUS, ['only' => ['updateStatus']]);
    }

    /**
     * Update shipment status.
     *
     * #137: the canonical authority owns the timeline row, order mirror and
     * deferred status-change event. This controller only validates and adapts
     * the legacy response shape.
     */
    public function updateStatus(Request $request, string $orderId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,label_created,picked_up,in_transit,out_for_delivery,delivered,failed_delivery,returned,cancelled',
            'tracking_number' => 'nullable|string|max:255',
            'courier_name' => 'nullable|string|max:255',
            'location_data' => 'nullable|array',
            'estimated_delivery_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $validator->validate();

        try {
            $order = Order::findOrFail($orderId);

            $this->shipments->recordOrderStatusChange((int) $order->id, (string) $request->input('status'), [
                'tracking_number' => $request->input('tracking_number'),
                'courier_name' => $request->input('courier_name'),
                'location_data' => $request->input('location_data'),
                'estimated_delivery_at' => $request->input('estimated_delivery_at')
                    ? \Illuminate\Support\Carbon::parse($request->input('estimated_delivery_at'))
                    : null,
                'notes' => $request->input('notes'),
            ]);

            $freshOrder = $order->fresh();

            return response()->json([
                'success' => true,
                'message' => __('message.MESSAGE.SHIPMENT_STATUS_UPDATED_SUCCESSFULLY'),
                'data' => [
                    'order_id' => $order->id,
                    'shipment_status' => $freshOrder?->shipment_status,
                    'tracking_number' => $freshOrder?->tracking_number,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Shipment status update failed', ['order_id' => $orderId, 'exception' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => __('message.ERROR.SHIPMENT_STATUS_UPDATE_FAILED'),
            ], 500);
        }
    }

    /**
     * Get shipment details
     */
    public function show(string $orderId): JsonResponse
    {
        try {
            $order = Order::findOrFail($orderId);

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => $order->id,
                    'shipment_status' => $order->shipment_status,
                    'tracking_number' => $order->tracking_number,
                    'courier_name' => $order->courier_name,
                    'estimated_delivery_at' => $order->estimated_delivery_at?->toIso8601String(),
                    'actual_delivery_at' => $order->actual_delivery_at?->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => __('message.ERROR.ORDER_NOT_FOUND'),
            ], 404);
        }
    }
}

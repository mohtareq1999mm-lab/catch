<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\Shipment\EstimatedDeliveryChanged;
use App\Events\Shipment\ShipmentStatusChanged;
use App\Http\Controllers\Controller;
use Marvel\Database\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ShipmentController extends Controller
{
    /**
     * Update shipment status
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

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $order = Order::findOrFail($orderId);

            DB::transaction(function () use ($order, $request) {
                $oldStatus = $order->shipment_status ?? 'pending';
                $newStatus = $request->input('status');

                event(new ShipmentStatusChanged(
                    order: $order,
                    oldStatus: $oldStatus,
                    newStatus: $newStatus,
                    trackingNumber: $request->input('tracking_number'),
                    courierName: $request->input('courier_name'),
                    locationData: $request->input('location_data'),
                    estimatedDelivery: $request->input('estimated_delivery_at')
                        ? new \DateTime($request->input('estimated_delivery_at'))
                        : null,
                ));

                $newEta = $request->input('estimated_delivery_at');
                if ($newEta && $order->estimated_delivery_at?->toDateString() !== $newEta) {
                    event(new EstimatedDeliveryChanged(
                        order: $order,
                        oldEta: $order->estimated_delivery_at,
                        newEta: new \DateTime($newEta),
                        reason: $request->input('notes') ?? 'admin_update',
                    ));
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Shipment status updated successfully',
                'data' => [
                    'order_id' => $order->id,
                    'shipment_status' => $order->fresh()->shipment_status,
                    'tracking_number' => $order->tracking_number,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update shipment status',
                'error' => $e->getMessage(),
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
                'message' => 'Order not found',
            ], 404);
        }
    }
}

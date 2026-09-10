<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\Order;
use Marvel\Traits\ApiResponse;

class OrderTrackingController extends Controller
{
    use ApiResponse;

    /**
     * Track order by order number (public - requires email or phone verification).
     */
    public function trackByOrderNumber(Request $request)
    {
        $validated = $request->validate([
            'order_number' => 'required|string',
            'user_email' => 'required_without:user_phone|email|max:255',
            'user_phone' => 'required_without:user_email|string|max:255',
        ]);

        $query = Order::query()->where('order_number', $validated['order_number']);

        if (isset($validated['user_email'])) {
            $query->where('user_email', $validated['user_email']);
        } else {
            $query->where('user_phone', $validated['user_phone']);
        }

        $order = $query->with(['orderItems', 'statusHistory.changedBy'])->first();

        if (!$order) {
            return $this->apiResponse('Order not found. Please check your order number and contact details.', 404, false);
        }

        try {
            \App\Services\Logging\OrderTrackingLogger::logTrackingAccess($order, 'customer_public', auth()->id());
            \App\Services\Metrics\OrderTrackingMetrics::incrementTrackingAccess('customer_public');
        } catch (\Throwable $e) {}

        return $this->apiResponse('Order tracking retrieved successfully.', 200, true, [
            'order' => $this->formatOrderForTracking($order),
            'timeline' => $this->buildTimeline($order),
            'current_status' => $this->getCurrentStatusInfo($order),
            'estimated_delivery' => $this->getEstimatedDelivery($order),
        ]);
    }

    /**
     * Track order for authenticated user.
     */
    public function trackAuthenticatedOrder(Request $request, $orderId)
    {
        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', Auth::id())
            ->with(['orderItems', 'statusHistory.changedBy'])
            ->first();

        if (!$order) {
            return $this->apiResponse('Order not found.', 404, false);
        }

        try {
            \App\Services\Logging\OrderTrackingLogger::logTrackingAccess($order, 'customer_auth', Auth::id());
            \App\Services\Metrics\OrderTrackingMetrics::incrementTrackingAccess('customer_auth');
        } catch (\Throwable $e) {}

        return $this->apiResponse('Order tracking retrieved successfully.', 200, true, [
            'order' => $this->formatOrderForTracking($order),
            'timeline' => $this->buildTimeline($order),
            'current_status' => $this->getCurrentStatusInfo($order),
            'estimated_delivery' => $this->getEstimatedDelivery($order),
            'can_cancel' => $this->canCancel($order),
        ]);
    }

    /**
     * Get all orders for authenticated user with tracking info.
     */
    public function listUserOrders(Request $request)
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);

        $orders = Order::query()
            ->where('user_id', Auth::id())
            ->with(['statusHistory' => function ($query) {
                $query->latest('changed_at')->limit(1);
            }])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = $orders->getCollection()->map(function (Order $order) {
            $lastHistory = $order->statusHistory->first();

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'fulfillment_status' => $order->fulfillment_status,
                'total' => round((float) $order->total_price, 2),
                'currency' => $order->currency_code ?? config('payment.default_currency', 'EGP'),
                'created_at' => $order->created_at?->toIso8601String(),
                'last_update' => $lastHistory?->changed_at?->toIso8601String(),
                'status_info' => $this->getCurrentStatusInfo($order),
                'tracking_url' => route('api.tracking.order', $order->id),
            ];
        });

        $orders->setCollection($data);

        return $this->apiResponse('Orders retrieved successfully.', 200, true, $orders);
    }

    // Helper methods

    private function formatOrderForTracking(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'payment_method' => $order->payment_method,
            'total_price' => round((float) $order->total_price, 2),
            'currency_code' => $order->currency_code ?? config('payment.default_currency', 'EGP'),
            'created_at' => $order->created_at?->toIso8601String(),
            'items_count' => $order->orderItems ? $order->orderItems->count() : 0,
            'items' => $order->relationLoaded('orderItems') ? $order->orderItems->map(fn($item) => [
                'name' => $item->product_name,
                'quantity' => $item->product_quantity,
                'price' => round((float) $item->product_price, 2),
            ])->values()->all() : [],
        ];
    }

    private function buildTimeline(Order $order): array
    {
        // Prefer history if available; fallback to at least the current status
        $history = $order->statusHistory()->orderBy('changed_at', 'asc')->get();

        if ($history->isEmpty()) {
            return [[
                'timestamp' => $order->created_at?->toIso8601String(),
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'fulfillment_status' => $order->fulfillment_status,
                'title' => $this->getStatusTitle($order->status, true),
                'description' => 'Your order has been created successfully.',
                'changed_by' => null,
                'icon' => $this->getStatusIcon($order->status),
                'color' => $this->getStatusColor($order->status),
            ]];
        }

        return $history->map(function (OrderStatusHistory $record) {
            return [
                'timestamp' => $record->changed_at->toIso8601String(),
                'status' => $record->new_status,
                'payment_status' => $record->new_payment_status,
                'fulfillment_status' => $record->new_fulfillment_status,
                'title' => $this->getTimelineTitle($record),
                'description' => $record->notes ?? $this->getTimelineDescription($record),
                'changed_by' => $record->changedBy ? [
                    'name' => $record->changedBy->name,
                    'type' => $record->changed_by_type,
                ] : ['name' => null, 'type' => $record->changed_by_type],
                'icon' => $this->getStatusIcon($record->new_status),
                'color' => $this->getStatusColor($record->new_status),
            ];
        })->values()->all();
    }

    private function getCurrentStatusInfo(Order $order): array
    {
        $statusInfo = [
            'status' => $order->status,
            'payment' => $order->payment_status,
            'fulfillment' => $order->fulfillment_status,
            'label' => $this->getStatusLabel($order->status),
            'description' => $this->getStatusDescription($order),
            'icon' => $this->getStatusIcon($order->status),
            'color' => $this->getStatusColor($order->status),
            'progress_percentage' => $this->getProgressPercentage($order),
        ];

        // P2-3: Customer-facing pending payment verification messaging
        if ($order->payment_status === Order::PAYMENT_STATUS_PENDING && $order->payment_method === 'online') {
            $minutesSinceCreation = now()->diffInMinutes($order->created_at);

            if ($minutesSinceCreation < 30) {
                $statusInfo['verification_message'] = 'Payment verification in progress. This usually completes within a few minutes.';
                $statusInfo['verification_status'] = 'in_progress';
            } elseif ($minutesSinceCreation < 120) {
                $statusInfo['verification_message'] = 'Payment verification is taking longer than usual. Your order is being processed.';
                $statusInfo['verification_status'] = 'delayed';
            } else {
                $statusInfo['verification_message'] = 'Payment verification is pending. Please contact support if you completed payment.';
                $statusInfo['verification_status'] = 'requires_attention';
                $statusInfo['support_action'] = 'contact_support';
            }
        }

        return $statusInfo;
    }

    private function getEstimatedDelivery(Order $order): ?array
    {
        if ($order->status === Order::ORDER_STATUS_DELIVERED) {
            return [
                'delivered_at' => $order->completed_at?->toIso8601String(),
                'message' => 'Order has been delivered',
            ];
        }

        if ($order->status === Order::ORDER_STATUS_CANCELLED) {
            return null;
        }

        $estimatedDays = match ($order->fulfillment_type) {
            'pickup' => 2,
            'delivery' => 5,
            default => 5,
        };

        $estimatedDate = $order->created_at ? $order->created_at->copy()->addDays($estimatedDays) : now()->addDays($estimatedDays);

        $daysRemaining = (int) max(0, now()->diffInDays($estimatedDate, false));

        return [
            'estimated_date' => $estimatedDate->toIso8601String(),
            'estimated_date_formatted' => $estimatedDate->format('l, F j, Y'),
            'days_remaining' => $daysRemaining,
            'message' => $estimatedDate->isPast()
                ? 'Delivery is overdue. Please contact support.'
                : "Estimated delivery in {$estimatedDate->diffForHumans()}",
        ];
    }

    private function canCancel(Order $order): bool
    {
        return in_array($order->status, [Order::ORDER_STATUS_PENDING, Order::ORDER_STATUS_PROCESSING], true)
            && $order->payment_status !== Order::PAYMENT_STATUS_SUCCESS;
    }

    private function getTimelineTitle(OrderStatusHistory $record): string
    {
        if ($record->old_status === null) {
            return 'Order Created';
        }

        return $this->getStatusTitle($record->new_status, false);
    }

    private function getStatusTitle(string $status, bool $isCreation): string
    {
        if ($isCreation) {
            return 'Order Created';
        }

        return match ($status) {
            Order::ORDER_STATUS_PENDING => 'Order Placed',
            Order::ORDER_STATUS_PROCESSING => 'Order Being Processed',
            Order::ORDER_STATUS_COMPLETED => 'Order Completed',
            Order::ORDER_STATUS_DELIVERED => 'Order Delivered',
            Order::ORDER_STATUS_CANCELLED => 'Order Cancelled',
            default => ucfirst($status),
        };
    }

    private function getTimelineDescription(OrderStatusHistory $record): string
    {
        if ($record->old_status === null) {
            return 'Your order has been created successfully.';
        }

        return match ($record->new_status) {
            Order::ORDER_STATUS_PENDING => 'Waiting for payment confirmation.',
            Order::ORDER_STATUS_PROCESSING => 'Your order is being prepared.',
            Order::ORDER_STATUS_COMPLETED => 'Payment received and order is ready.',
            Order::ORDER_STATUS_DELIVERED => 'Your order has been delivered successfully.',
            Order::ORDER_STATUS_CANCELLED => 'Order has been cancelled.',
            default => "Status changed to {$record->new_status}",
        };
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            Order::ORDER_STATUS_PENDING => 'Pending Payment',
            Order::ORDER_STATUS_PROCESSING => 'Processing',
            Order::ORDER_STATUS_COMPLETED => 'Completed',
            Order::ORDER_STATUS_DELIVERED => 'Delivered',
            Order::ORDER_STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($status),
        };
    }

    private function getStatusDescription(Order $order): string
    {
        if ($order->status === Order::ORDER_STATUS_PENDING) {
            return $order->payment_status === Order::PAYMENT_STATUS_PENDING
                ? 'Waiting for payment confirmation'
                : 'Order is pending';
        }

        return match ($order->status) {
            Order::ORDER_STATUS_PROCESSING => 'Your order is being prepared for shipment',
            Order::ORDER_STATUS_COMPLETED => 'Your order is ready and will be shipped soon',
            Order::ORDER_STATUS_DELIVERED => 'Your order has been delivered successfully',
            Order::ORDER_STATUS_CANCELLED => 'This order has been cancelled',
            default => 'Order status: ' . $order->status,
        };
    }

    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            Order::ORDER_STATUS_PENDING => '⏳',
            Order::ORDER_STATUS_PROCESSING => '📦',
            Order::ORDER_STATUS_COMPLETED => '✅',
            Order::ORDER_STATUS_DELIVERED => '🎉',
            Order::ORDER_STATUS_CANCELLED => '❌',
            default => '📋',
        };
    }

    private function getStatusColor(string $status): string
    {
        return match ($status) {
            Order::ORDER_STATUS_PENDING => 'yellow',
            Order::ORDER_STATUS_PROCESSING => 'blue',
            Order::ORDER_STATUS_COMPLETED => 'green',
            Order::ORDER_STATUS_DELIVERED => 'green',
            Order::ORDER_STATUS_CANCELLED => 'red',
            default => 'gray',
        };
    }

    private function getProgressPercentage(Order $order): int
    {
        if ($order->status === Order::ORDER_STATUS_CANCELLED) {
            return 0;
        }

        return match ($order->status) {
            Order::ORDER_STATUS_PENDING => 20,
            Order::ORDER_STATUS_PROCESSING => 50,
            Order::ORDER_STATUS_COMPLETED => 80,
            Order::ORDER_STATUS_DELIVERED => 100,
            default => 0,
        };
    }
}

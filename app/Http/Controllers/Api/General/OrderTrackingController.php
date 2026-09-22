<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Models\OrderStatusHistory;
use App\Models\OrderTrackingEvent;
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

        $order = $query->with(['orderItems'])->first();

        if (!$order) {
            return $this->apiResponse('Order not found. Please check your order number and contact details.', 404, false);
        }

        try {
            \App\Services\Logging\OrderTrackingLogger::logTrackingAccess($order, 'customer_public', auth()->id());
            \App\Services\Metrics\OrderTrackingMetrics::incrementTrackingAccess('customer_public');
        } catch (\Throwable $e) {}

        return $this->apiResponse('Order tracking retrieved successfully.', 200, true, [
            'order' => $this->formatOrderForTracking($order),
            'timeline' => $this->publicTimeline($order),
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
            ->with(['orderItems'])
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
            'timeline' => $this->timeline($order),
            'current_status' => $this->getCurrentStatusInfo($order),
            'estimated_delivery' => $this->getEstimatedDelivery($order),
            'can_cancel' => $this->canCancel($order),
            'progress' => $this->progress($order),
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

    /**
     * Public timeline - only customer-visible events from OrderTrackingEvent
     */
    private function publicTimeline(Order $order): array
    {
        return $this->buildTimelineResponse($order, true);
    }

    /**
     * Full timeline - all events from OrderTrackingEvent (authenticated users)
     */
    private function timeline(Order $order): array
    {
        return $this->buildTimelineResponse($order, false);
    }

    /**
     * Build timeline response from OrderTrackingEvent table
     */
    private function buildTimelineResponse(Order $order, bool $customerVisibleOnly): array
    {
        $query = OrderTrackingEvent::query()
            ->forOrder($order->id)
            ->orderBy('event_timestamp', 'asc');

        if ($customerVisibleOnly) {
            $query->customerVisible();
        }

        $events = $query->get();

        if ($events->isEmpty()) {
            return [[
                'timestamp' => $order->created_at?->toIso8601String(),
                'event_type' => 'order.created',
                'title' => __('tracking.order.created'),
                'description' => __('tracking.order.created_description'),
                'actor' => ['type' => 'system', 'name' => __('tracking.actor.system')],
                'icon' => 'shopping-cart',
                'metadata' => null,
            ]];
        }

        return $events->map(function (OrderTrackingEvent $event) {
            return [
                'timestamp' => $event->event_timestamp->toIso8601String(),
                'event_type' => $event->event_type,
                'title' => $event->customer_label_key 
                    ? __($event->customer_label_key)
                    : ucfirst(str_replace(['.', '_'], ' ', $event->event_type)),
                'description' => $this->translateDescription($event),
                'actor' => [
                    'type' => $event->actor_type,
                    'name' => $event->actor_name ?? __('tracking.actor.' . $event->actor_type),
                ],
                'icon' => $event->metadata['icon'] ?? 'circle',
                'metadata' => $event->metadata,
            ];
        })->values()->all();
    }

    /**
     * Translate description with metadata placeholders
     */
    private function translateDescription(OrderTrackingEvent $event): string
    {
        if (!$event->customer_description_key) {
            return '';
        }

        $metadata = $event->metadata ?? [];
        
        return __($event->customer_description_key, $metadata);
    }

    /**
     * Order progress widget data
     */
    private function progress(Order $order): array
    {
        $stages = [
            'order_placed' => false,
            'payment_confirmed' => false,
            'preparing_shipment' => false,
            'shipped' => false,
            'out_for_delivery' => false,
            'delivered' => false,
        ];

        $events = OrderTrackingEvent::query()
            ->forOrder($order->id)
            ->customerVisible()
            ->get();

        foreach ($events as $event) {
            $this->markStageComplete($stages, $event->event_type);
        }

        $stageList = [];
        $currentStageIndex = 0;
        $index = 0;

        foreach ($stages as $stage => $completed) {
            $stageList[] = [
                'stage' => $stage,
                'label' => __('tracking.milestone.' . $stage),
                'completed' => $completed,
                'is_current' => !$completed && $currentStageIndex === $index,
            ];

            if ($completed) {
                $currentStageIndex = $index + 1;
            }

            $index++;
        }

        $completedCount = count(array_filter($stages));
        $totalCount = count($stages);
        $progressPercentage = $totalCount > 0 
            ? (int) round(($completedCount / $totalCount) * 100) 
            : 0;

        return [
            'stages' => $stageList,
            'progress_percentage' => $progressPercentage,
            'completed_stages' => $completedCount,
            'total_stages' => $totalCount,
        ];
    }

    /**
     * Mark stage as complete based on event type
     */
    private function markStageComplete(array &$stages, string $eventType): void
    {
        match ($eventType) {
            'order.created' => $stages['order_placed'] = true,
            'payment.succeeded' => $stages['payment_confirmed'] = true,
            'fulfillment.processing' => $stages['preparing_shipment'] = true,
            'order.shipped', 'shipment.picked_up', 'shipment.in_transit' => $stages['shipped'] = true,
            'shipment.out_for_delivery', 'fulfillment.out_for_delivery' => $stages['out_for_delivery'] = true,
            'order.delivered', 'shipment.delivered', 'fulfillment.delivered' => $stages['delivered'] = true,
            default => null,
        };
    }

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
        return $this->buildTimelineResponse($order, false);
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
}

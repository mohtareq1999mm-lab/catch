<?php

namespace App\Services\Logging;

use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;

class OrderTrackingLogger
{
    /**
     * Log order status change
     */
    public static function logStatusChange(Order $order, string $oldStatus, string $newStatus, ?int $changedBy, string $changedByType): void
    {
        Log::info('Order status changed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'changed_by' => $changedBy,
            'changed_by_type' => $changedByType,
            'total_price' => $order->total_price,
            'currency' => $order->currency_code,
            'context' => 'order_tracking',
        ]);
    }

    /**
     * Log payment verification attempt
     */
    public static function logPaymentVerification(Order $order, string $result, ?array $gatewayResponse = null): void
    {
        Log::info('Payment verification attempted', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_method' => $order->payment_method,
            'payment_gateway' => $order->payment_gateway,
            'result' => $result,  // 'success', 'failed', 'pending'
            'gateway_response_status' => $gatewayResponse['InvoiceStatus'] ?? $gatewayResponse['invoice_status'] ?? null,
            'amount' => $order->total_price,
            'currency' => $order->currency_code,
            'context' => 'payment_verification',
        ]);
    }

    /**
     * Log tracking access (customer or admin)
     */
    public static function logTrackingAccess(Order $order, string $accessType, ?int $userId = null): void
    {
        Log::debug('Order tracking accessed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'access_type' => $accessType,  // 'customer_public', 'customer_auth', 'admin'
            'accessed_by_user_id' => $userId,
            'order_user_id' => $order->user_id,
            'order_status' => $order->status,
            'context' => 'order_tracking_access',
        ]);
    }

    /**
     * Log stuck payment detection
     */
    public static function logStuckPayment(Order $order, int $minutesPending): void
    {
        Log::warning('Stuck payment detected', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'payment_method' => $order->payment_method,
            'payment_gateway' => $order->payment_gateway,
            'minutes_pending' => $minutesPending,
            'created_at' => $order->created_at?->toIso8601String(),
            'context' => 'stuck_payment_alert',
            'severity' => 'warning',
        ]);
    }
}

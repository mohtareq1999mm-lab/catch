<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordPaymentSuccessInTimeline
{
    /**
     * Handle the event.
     */
    public function handle(PaymentSucceeded $event): void
    {
        try {
            $order = $event->order;
            $transaction = $order->transactions()->latest()->first();
            $currentMethod = $transaction?->payment_gateway ?? 'unknown';

            $previousFailedEvent = OrderTrackingEvent::where('order_id', $order->id)
                ->where('event_type', 'payment.failed')
                ->latest('event_timestamp')
                ->first();

            $metadata = [
                'amount' => $order->paid_total,
                'currency' => $order->currency ?? 'SAR',
                'payment_method' => $currentMethod,
                'transaction_id' => $transaction?->id,
                'payment_intent_id' => $transaction?->payment_intent_id,
            ];

            if ($previousFailedEvent) {
                $previousMethod = $previousFailedEvent->metadata['payment_method'] ?? null;
                if ($previousMethod && $previousMethod !== $currentMethod) {
                    $metadata['payment_method_changed'] = true;
                    $metadata['previous_payment_method'] = $previousMethod;
                }
            }

            OrderTrackingEvent::create([
                'order_id' => $order->id,
                'event_type' => 'payment.succeeded',
                'event_timestamp' => now(),
                'actor_type' => 'customer',
                'actor_id' => $order->customer_id,
                'actor_name' => $order->customer?->name ?? 'Customer',
                'old_status' => 'payment-pending',
                'new_status' => $order->payment_status,
                'metadata' => $metadata,
                'customer_visible' => true,
                'customer_label_key' => 'tracking.payment.succeeded',
                'customer_description_key' => 'tracking.payment.succeeded_description',
                'admin_notes' => null,
                'source' => 'system',
                'ip_address' => request()->ip(),
            ]);

            Log::info('Payment success tracking event recorded', [
                'order_id' => $order->id,
                'amount' => $order->paid_total,
                'method_changed' => $metadata['payment_method_changed'] ?? false,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record payment success tracking event', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

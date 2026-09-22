<?php

namespace App\Listeners\Refund;

use App\Events\Refund\RefundProcessed;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordRefundInTimeline
{
    public function handle(RefundProcessed $event): void
    {
        try {
            $refund = $event->refund;
            $order = $event->order;

            $totalPaid = $order->paid_total ?? 0;
            $totalRefunded = $order->refunds()->sum('amount');
            $remainingBalance = $totalPaid - $totalRefunded;

            OrderTrackingEvent::create([
                'order_id' => $order->id,
                'event_type' => 'refund.processed',
                'event_timestamp' => now(),
                'actor_type' => 'admin',
                'actor_id' => $refund->approved_by ?? null,
                'actor_name' => $this->resolveActorName($refund),
                'old_status' => null,
                'new_status' => null,
                'metadata' => [
                    'refund_id' => $refund->id,
                    'refund_amount' => $refund->amount,
                    'refund_type' => $event->refundType,
                    'order_number' => $order->order_number,
                    'total_paid' => $totalPaid,
                    'total_refunded' => $totalRefunded,
                    'remaining_balance' => $remainingBalance,
                    'processed_at' => now()->toIso8601String(),
                ],
                'customer_visible' => true,
                'customer_label_key' => 'tracking.refund.processed',
                'customer_description_key' => 'tracking.refund.processed_description',
                'admin_notes' => null,
                'source' => 'system',
                'ip_address' => request()->ip(),
            ]);

            Log::info('Refund processed tracking event recorded', [
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'amount' => $refund->amount,
                'type' => $event->refundType,
                'remaining_balance' => $remainingBalance,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record refund processed tracking event', [
                'order_id' => $event->order->id ?? null,
                'refund_id' => $event->refund->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function resolveActorName($refund): string
    {
        if ($refund->approved_by) {
            return \App\Models\User::find($refund->approved_by)?->name ?? 'Admin User';
        }

        return 'System';
    }
}

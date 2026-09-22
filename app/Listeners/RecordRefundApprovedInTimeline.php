<?php

namespace App\Listeners;

use App\Events\RefundApproved;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordRefundApprovedInTimeline
{
    public function handle(RefundApproved $event): void
    {
        try {
            $refund = $event->refund;
            $order = $refund->order;

            OrderTrackingEvent::create([
                'order_id' => $order->id,
                'event_type' => 'refund.approved',
                'event_timestamp' => now(),
                'actor_type' => 'admin',
                'actor_id' => $refund->approved_by ?? null,
                'actor_name' => $this->resolveActorName($refund),
                'old_status' => null,
                'new_status' => null,
                'metadata' => [
                    'refund_id' => $refund->id,
                    'refund_amount' => $refund->amount,
                    'order_number' => $order->order_number,
                    'approved_at' => now()->toIso8601String(),
                ],
                'customer_visible' => true,
                'customer_label_key' => 'tracking.refund.approved',
                'customer_description_key' => 'tracking.refund.approved_description',
                'admin_notes' => null,
                'source' => 'system',
                'ip_address' => request()->ip(),
            ]);

            Log::info('Refund approved tracking event recorded', [
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'amount' => $refund->amount,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record refund approved tracking event', [
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

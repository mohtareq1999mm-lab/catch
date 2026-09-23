<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6: SINGLE authoritative owner of fulfillment status transitions.
 *
 * All fulfillment status writes MUST go through this service. Direct
 * `$fulfillment->update(['status' => ...])` from picking/packing/shipment code
 * is a defect (B3 wedge). Same-state transitions are idempotent no-ops.
 */
class FulfillmentTransition
{
    /**
     * Transition a fulfillment to a new status under row lock.
     *
     * @param array{actor_id?: int|string|null, actor_type?: string, reason?: string} $context
     *
     * @throws \RuntimeException on illegal transition.
     */
    public function transition(Fulfillment $fulfillment, string $to, array $context = []): Fulfillment
    {
        return DB::transaction(function () use ($fulfillment, $to, $context) {
            $locked = Fulfillment::whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from === $to) {
                return $locked; // idempotent no-op
            }

            if (!$locked->canTransitionTo($to)) {
                throw new \RuntimeException(
                    "Invalid fulfillment transition from {$from} to {$to} (fulfillment #{$locked->id})"
                );
            }

            $updates = ['status' => $to] + $this->timestampsFor($to);
            $locked->update($updates);

            Log::info('Fulfillment transition', [
                'fulfillment_id' => $locked->id,
                'order_id' => $locked->order_id,
                'from' => $from,
                'to' => $to,
                'actor_id' => $context['actor_id'] ?? null,
                'actor_type' => $context['actor_type'] ?? 'system',
                'reason' => $context['reason'] ?? null,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function timestampsFor(string $to): array
    {
        return match ($to) {
            'picking' => ['picking_started_at' => now()],
            'picked' => ['picking_completed_at' => now()],
            'packing' => ['packing_started_at' => now()],
            'ready_to_ship' => ['packing_completed_at' => now(), 'ready_to_ship_at' => now()],
            'shipped' => ['shipped_at' => now()],
            'delivered' => ['delivered_at' => now()],
            'cancelled' => ['cancelled_at' => now()],
            default => [],
        };
    }
}

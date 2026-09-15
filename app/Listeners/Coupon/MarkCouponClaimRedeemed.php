<?php

namespace App\Listeners\Coupon;

use App\Events\PaymentSucceeded;
use App\Services\Coupon\CouponClaimService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\CouponClaim;

/**
 * Phase 2 Fix #2: Order Completion Integration
 *
 * Marks active coupon claims as REDEEMED when payment succeeds.
 *
 * Flow:
 * 1. Order completed → PaymentSucceeded event fires
 * 2. This listener locates ACTIVE claim for (coupon_id, user_id) on the order
 * 3. Calls CouponClaimService::markRedeemed() to transition ACTIVE → REDEEMED
 *
 * Transaction Safety:
 * - PaymentSucceeded implements ShouldDispatchAfterCommit
 * - This listener queued via ShouldQueue
 * - Event only fires after payment transaction commits
 * - Redemption happens in separate transaction after payment success confirmed
 */
class MarkCouponClaimRedeemed implements ShouldQueue
{
    /**
     * P2: forward-compatible declaration. Laravel 10.30 ignores this on
     * queued listeners — commit-safety is guaranteed by PaymentSucceeded
     * implementing ShouldDispatchAfterCommit. Kept so a future framework
     * upgrade honors per-listener deferral too.
     */
    public $afterCommit = true;

    public function __construct(
        private readonly CouponClaimService $claimService,
    ) {}

    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order;

        // Only process if order used a coupon
        if (!$order->coupon_id) {
            return;
        }

        // Find active claim for this order's coupon and user
        $activeClaim = CouponClaim::query()
            ->where('coupon_id', $order->coupon_id)
            ->where('user_id', $order->user_id)
            ->where('status', \App\Enums\CouponClaimStatus::ACTIVE)
            ->first();

        if (!$activeClaim) {
            // No active claim found - this is expected if:
            // 1. Coupon was assignment-mode (no claim required)
            // 2. Claim already redeemed by concurrent listener
            // 3. Claim expired between order creation and payment
            Log::info('No active coupon claim found for completed order', [
                'order_id' => $order->id,
                'coupon_id' => $order->coupon_id,
                'user_id' => $order->user_id,
            ]);
            return;
        }

        // Mark the claim as redeemed
        try {
            $this->claimService->markRedeemed($activeClaim);

            Log::info('Coupon claim marked as redeemed', [
                'claim_id' => $activeClaim->id,
                'order_id' => $order->id,
                'coupon_id' => $order->coupon_id,
                'user_id' => $order->user_id,
            ]);
        } catch (\Exception $e) {
            // Log but don't fail the listener - payment already succeeded
            Log::error('Failed to mark coupon claim as redeemed', [
                'claim_id' => $activeClaim->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

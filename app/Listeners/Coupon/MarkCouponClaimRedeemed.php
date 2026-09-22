<?php

namespace App\Listeners\Coupon;

use App\Events\PaymentSucceeded;
use App\Services\Coupon\CouponClaimService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;

/**
 * CP-01: Atomic claim redemption (INV-01).
 *
 * Resolution chain (no blind first-match):
 *   authoritative order → exact coupon (by snapshot code) → exact customer
 *   → exact ACTIVE unexpired claim → state-checked transition.
 *
 * Idempotency: the claim row is locked and re-checked inside a transaction;
 * a repeated/duplicate listener run finds no ACTIVE claim and becomes a
 * safe no-op. PaymentSucceeded implements ShouldDispatchAfterCommit and this
 * listener is queued + afterCommit, so redemption only runs after the payment
 * transaction commits.
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

        // Only process orders that actually carry a coupon snapshot.
        if (!$order || !$order->coupon || !$order->user_id) {
            return;
        }

        // Exact coupon resolved from the authoritative order snapshot
        // (CP-09 canonical match; snapshots are never rewritten).
        $coupon = Coupon::byCode($order->coupon)->first();

        if (!$coupon) {
            Log::warning('Coupon claim redemption skipped: snapshot code has no coupon', [
                'order_id' => $order->id,
                'code' => $order->coupon,
                'user_id' => $order->user_id,
            ]);

            return;
        }

        DB::transaction(function () use ($order, $coupon) {
            // Exact ACTIVE unexpired claim for THIS coupon + THIS user,
            // locked so concurrent listener runs serialize here.
            $activeClaim = CouponClaim::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('user_id', $order->user_id)
                ->where('status', \App\Enums\CouponClaimStatus::ACTIVE)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->lockForUpdate()
                ->first();

            if (!$activeClaim) {
                // Expected when: assignment-mode coupon (no claim), claim
                // already redeemed by an earlier run, or claim expired
                // between checkout and payment.
                Log::info('No active coupon claim found for completed order', [
                    'order_id' => $order->id,
                    'coupon_id' => $coupon->getKey(),
                    'user_id' => $order->user_id,
                ]);

                return;
            }

            try {
                $this->claimService->markRedeemed($activeClaim);

                Log::info('Coupon claim marked as redeemed', [
                    'claim_id' => $activeClaim->id,
                    'order_id' => $order->id,
                    'coupon_id' => $coupon->getKey(),
                    'user_id' => $order->user_id,
                ]);
            } catch (\App\Exceptions\CouponClaimException $e) {
                if ($e->reason === \App\Exceptions\CouponClaimException::REASON_CANNOT_REDEEM) {
                    // S3: lost race — the claim transitioned under our lock
                    // (already REDEEMED/EXPIRED). Safe no-op, do not retry.
                    Log::info('Coupon claim already transitioned; skipping redemption', [
                        'claim_id' => $activeClaim->id,
                        'order_id' => $order->id,
                    ]);

                    return;
                }

                throw $e;
            } catch (\Exception $e) {
                // S3: transient infrastructure errors rethrow so the queue
                // retries. Payment already succeeded; the claim stays ACTIVE
                // and the reconciler flags it if retries exhaust.
                Log::error('Failed to mark coupon claim as redeemed', [
                    'claim_id' => $activeClaim->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }
}

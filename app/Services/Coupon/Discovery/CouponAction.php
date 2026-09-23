<?php

namespace App\Services\Coupon\Discovery;

use App\Enums\CouponClaimStatus;
use Marvel\Database\Models\CouponClaim;

/**
 * Canonical claim/action derivation for customer coupon surfaces.
 *
 * Consumed by every endpoint presenting coupons (general catalog and the
 * personalized list alike) so `claim_status` and `action` can never drift
 * apart between surfaces.
 *
 * - eligible=false → no usable action (visible, not redeemable).
 * - redeemed claim → terminal, no action.
 * - active unexpired claim, or no claim requirement → apply.
 * - otherwise (claim required, nothing held) → claim.
 */
class CouponAction
{
    /**
     * @return array{claim_status: 'redeemed'|'claimed'|'not_required'|'claimable', action: 'apply'|'claim'|null}
     */
    public static function resolve(bool $eligible, bool $requiresClaim, ?CouponClaim $claim): array
    {
        $status = $claim?->status;
        $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;

        $activeClaim = $claim !== null
            && $statusValue === CouponClaimStatus::ACTIVE->value
            && ($claim->expires_at === null || $claim->expires_at->isFuture());

        if ($claim !== null && $statusValue === CouponClaimStatus::REDEEMED->value) {
            $claimStatus = 'redeemed';
            $action = null;
        } elseif ($activeClaim) {
            $claimStatus = 'claimed';
            $action = 'apply';
        } elseif (! $requiresClaim) {
            $claimStatus = 'not_required';
            $action = 'apply';
        } else {
            $claimStatus = 'claimable';
            $action = 'claim';
        }

        if (! $eligible) {
            $action = null;
        }

        return ['claim_status' => $claimStatus, 'action' => $action];
    }
}

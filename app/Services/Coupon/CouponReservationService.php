<?php

namespace App\Services\Coupon;

use App\Models\CouponReservation;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Order;

/**
 * Handles coupon reservation lifecycle during payment window.
 *
 * Prevents double-booking of single-use coupons by creating temporary
 * reservations with 30min TTL. The reservation is consumed on payment
 * success or released on payment failure/expiration.
 */
class CouponReservationService
{
    private const RESERVATION_TTL_MINUTES = 30;

    /**
     * Reserve a coupon for the order's payment window.
     * Prevents other users from using the same coupon until this payment completes.
     *
     * @throws \RuntimeException if coupon cannot be reserved (already at limit)
     */
    public function reserve(Order $order, Coupon $coupon): CouponReservation
    {
        return DB::transaction(function () use ($order, $coupon) {
            // Lock the coupon to check availability
            $lockedCoupon = Coupon::whereKey($coupon->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedCoupon) {
                throw new \RuntimeException(__('checkout.coupon_not_found'));
            }

            // CRITICAL FIX: Lock existing reservation check to prevent race condition
            // Check if order already has a reservation (idempotent)
            $existing = CouponReservation::where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Refresh expiration
                $existing->update([
                    'expires_at' => now()->addMinutes(self::RESERVATION_TTL_MINUTES),
                ]);
                return $existing;
            }

            // CRITICAL FIX: Count with FOR UPDATE to get consistent snapshot
            // This ensures the count reflects reservations visible in this transaction
            $activeReservations = CouponReservation::where('coupon_id', $lockedCoupon->id)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->count();

            // Calculate total usage (consumed + reserved)
            $totalUsage = (int) $lockedCoupon->used + $activeReservations;

            // Check if coupon has capacity
            if ($lockedCoupon->limiter !== null && $totalUsage >= $lockedCoupon->limiter) {
                throw new \RuntimeException(__('checkout.coupon_usage_limit_reached'));
            }

            // Create reservation
            return CouponReservation::create([
                'coupon_id' => $lockedCoupon->id,
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'reserved_at' => now(),
                'expires_at' => now()->addMinutes(self::RESERVATION_TTL_MINUTES),
            ]);
        });
    }

    /**
     * Consume the reservation (payment success).
     * Deletes the reservation as the coupon is now consumed in the order.
     */
    public function consume(Order $order): void
    {
        CouponReservation::where('order_id', $order->id)->delete();
    }

    /**
     * Release the reservation (payment failure, order cancellation, or manual release).
     * Makes the coupon available for others again.
     */
    public function release(Order $order): void
    {
        CouponReservation::where('order_id', $order->id)->delete();
    }

    /**
     * Check if a coupon can be reserved (has capacity).
     * Used for validation before creating the order.
     */
    public function canReserve(Coupon $coupon): bool
    {
        return DB::transaction(function () use ($coupon) {
            $lockedCoupon = Coupon::whereKey($coupon->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedCoupon) {
                return false;
            }

            // No limiter = unlimited
            if ($lockedCoupon->limiter === null) {
                return true;
            }

            // CRITICAL FIX: Lock reservations for consistent read
            $activeReservations = CouponReservation::where('coupon_id', $lockedCoupon->id)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->count();

            $totalUsage = (int) $lockedCoupon->used + $activeReservations;

            return $totalUsage < $lockedCoupon->limiter;
        });
    }
}

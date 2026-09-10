<?php

namespace App\Services\Customer;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

class CustomerMetricsService
{
    /**
     * Rebuild metrics for a specific user from source of truth (orders table).
     * This is a deterministic, idempotent operation.
     *
     * Qualification: status='completed' AND payment_status='payment-success'
     */
    public function rebuildForUser(User $user): CustomerMetrics
    {
        return DB::transaction(function () use ($user) {
            // Query qualifying orders
            $qualifyingOrders = Order::query()
                ->where('user_id', $user->getKey())
                ->where('status', Order::ORDER_STATUS_COMPLETED)
                ->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                ->select([
                    'id',
                    'converted_total_price',
                    'created_at',
                ])
                ->orderBy('created_at')
                ->get();

            $completedOrders = $qualifyingOrders->count();
            $totalQualifyingOrderValue = $qualifyingOrders->sum('converted_total_price');
            $firstOrderAt = $qualifyingOrders->first()?->created_at;
            $lastOrderAt = $qualifyingOrders->last()?->created_at;

            // Count coupon usages (redemptions)
            // A coupon is "used" when it appears in a qualifying order
            $couponsUsed = Order::query()
                ->where('user_id', $user->getKey())
                ->where('status', Order::ORDER_STATUS_COMPLETED)
                ->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                ->whereNotNull('coupon')
                ->distinct('coupon')
                ->count('coupon');

            // Upsert metrics
            $metrics = CustomerMetrics::query()->updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'completed_orders' => $completedOrders,
                    'total_qualifying_order_value' => $totalQualifyingOrderValue,
                    'first_order_at' => $firstOrderAt,
                    'last_order_at' => $lastOrderAt,
                    'coupons_used' => $couponsUsed,
                    'computed_at' => now(),
                ]
            );

            return $metrics;
        });
    }

    /**
     * Get or compute metrics for a user.
     * Returns cached metrics if fresh, otherwise rebuilds.
     */
    public function getMetrics(User $user): CustomerMetrics
    {
        $metrics = CustomerMetrics::query()
            ->where('user_id', $user->getKey())
            ->first();

        if (!$metrics) {
            return $this->rebuildForUser($user);
        }

        return $metrics;
    }

    /**
     * Ensure metrics exist for a user (lazy initialization).
     * Does not force rebuild if metrics already exist.
     */
    public function ensureMetrics(User $user): CustomerMetrics
    {
        return CustomerMetrics::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'completed_orders' => 0,
                'total_qualifying_order_value' => 0.00,
                'first_order_at' => null,
                'last_order_at' => null,
                'coupons_used' => 0,
                'computed_at' => now(),
            ]
        );
    }

    /**
     * Rebuild all metrics (admin operation).
     * Use with caution on large datasets.
     */
    public function rebuildAll(): void
    {
        $userIds = Order::query()
            ->distinct('user_id')
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user) {
                $this->rebuildForUser($user);
            }
        }
    }
}

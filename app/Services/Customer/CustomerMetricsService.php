<?php

namespace App\Services\Customer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

class CustomerMetricsService
{
    /**
     * FINAL CLOSURE Sec 5 Case B marker. Orders stamped with this status
     * carry unreconstructable currency data; their spend is excluded from
     * coupon eligibility comparisons (counts/dates still include them).
     */
    public const LEGACY_CURRENCY_UNRESOLVED = 'unresolved';
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
            $columns = ['id', 'converted_total_price', 'created_at'];
            if (Schema::hasColumn('orders', 'legacy_currency_status')) {
                $columns[] = 'legacy_currency_status';
            }
            $qualifyingOrders = Order::query()
                ->where('user_id', $user->getKey())
                ->where('status', Order::ORDER_STATUS_COMPLETED)
                ->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                ->select($columns)
                ->orderBy('created_at')
                ->get();

            $completedOrders = $qualifyingOrders->count();
            // FINAL CLOSURE Sec 5 Case B: LEGACY_CURRENCY_UNRESOLVED rows carry
            // unnormalized mixed-currency values and MUST NOT pollute spend
            // comparisons. Counts/dates (currency-agnostic) still include them;
            // only the monetary SUM excludes them. Guarded for rolling deploys.
            $spendOrders = $qualifyingOrders;
            if (Schema::hasColumn('orders', 'legacy_currency_status')) {
                $spendOrders = $qualifyingOrders->filter(
                    fn ($o) => ($o->legacy_currency_status ?? null) !== self::LEGACY_CURRENCY_UNRESOLVED
                );
            }
            $totalQualifyingOrderValue = $spendOrders->sum('converted_total_price');
            $firstOrderAt = $qualifyingOrders->first()?->created_at;
            $lastOrderAt = $qualifyingOrders->last()?->created_at;

            // Count coupon usages (redemptions).
            // FINAL BUSINESS CONTRACT Sec 2: min_coupons_used means the NUMBER
            // of qualifying orders in which a coupon was used — NOT the number
            // of distinct codes. SAVE10+SAVE10+SAVE20 across 3 orders = 3.
            $couponsUsed = Order::query()
                ->where('user_id', $user->getKey())
                ->where('status', Order::ORDER_STATUS_COMPLETED)
                ->where('payment_status', Order::PAYMENT_STATUS_SUCCESS)
                ->whereNotNull('coupon')
                ->where('coupon', '!=', '')
                ->count();

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

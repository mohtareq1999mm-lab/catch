<?php

namespace App\Services\Dashboard;

use App\Enums\UserType;
use App\Models\DigitalDownloadLog;
use App\Models\DigitalEntitlement;
use App\Models\DigitalLicenseKey;
use App\Models\PaymentReconciliationResult;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;

class DashboardService
{
    /**
     * Base-currency-safe revenue expression. Orders created after the
     * currency feature carry `converted_total_price` (order total expressed
     * in the store base currency at purchase-time rate); legacy orders have
     * NULL there and their `total_price` is already base-denominated.
     * NEVER raw-SUM `total_price` across currencies.
     */
    private const BASE_REVENUE_RAW = 'COALESCE(converted_total_price, total_price)';

    // =========================================================================
    // Existing Endpoints
    // =========================================================================

    public function getOverview(Request $request): array
    {
        return Cache::remember('dashboard_overview', 300, function () {
            $totalRevenue = (float) Order::where('status', 'completed')
                ->whereDate('created_at', '<=', Carbon::now())
                ->selectRaw('SUM(' . self::BASE_REVENUE_RAW . ') as agg')
                ->value('agg');

            $todaysRevenue = (float) Order::where('status', 'completed')
                ->whereDate('created_at', '>', Carbon::now()->subDays(1))
                ->selectRaw('SUM(' . self::BASE_REVENUE_RAW . ') as agg')
                ->value('agg');

            $totalRefunds = (float) DB::table('refunds')
                ->whereDate('refunds.created_at', '<', Carbon::now())
                ->sum('amount');

            $totalOrders = Order::whereDate('created_at', '<=', Carbon::now())->count();

            $totalProducts = Product::count();

            $totalCustomers = User::where('type', UserType::USER->value)->count();
            $newCustomers = User::where('type', UserType::USER->value)
                ->whereDate('created_at', '>', Carbon::now()->subDays(30))
                ->count();

            return [
                'total_revenue'     => round($totalRevenue, 2),
                'todays_revenue'    => round($todaysRevenue, 2),
                'total_refunds'     => round($totalRefunds, 2),
                'total_orders'      => $totalOrders,
                'total_products'    => $totalProducts,
                'total_customers'   => $totalCustomers,
                'new_customers'     => $newCustomers,
            ] + $this->revenueCurrencyContext(
                Order::where('status', 'completed')
            ) + $this->refundCurrencyContext(
                DB::table('refunds')->whereDate('refunds.created_at', '<', Carbon::now())
            );
        });
    }

    public function getRevenueOverview(Request $request): array
    {
        return Cache::remember('dashboard_revenue', 300, function () {
            $totalRevenue = (float) Order::where('status', 'completed')
                ->whereDate('created_at', '<=', Carbon::now())
                ->selectRaw('SUM(' . self::BASE_REVENUE_RAW . ') as agg')
                ->value('agg');

            $todaysRevenue = (float) Order::where('status', 'completed')
                ->whereDate('created_at', '>', Carbon::now()->subDays(1))
                ->selectRaw('SUM(' . self::BASE_REVENUE_RAW . ') as agg')
                ->value('agg');

            $months = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December',
            ];

            $salesByMonth = Order::select(
                    DB::raw('SUM(' . self::BASE_REVENUE_RAW . ') as total'),
                    DB::raw($this->dateFormat('%c') . " as month_num")
                )
                ->where('status', 'completed')
                ->whereYear('created_at', Carbon::now()->year)
                ->groupBy('month_num')
                ->pluck('total', 'month_num')
                ->toArray();

            $monthlyBreakdown = array_map(fn ($index, $month) => [
                'month' => $month,
                'total' => round((float) ($salesByMonth[$index + 1] ?? 0), 2),
            ], array_keys($months), $months);

            return [
                'total_revenue'      => round($totalRevenue, 2),
                'todays_revenue'     => round($todaysRevenue, 2),
                'monthly_breakdown'  => $monthlyBreakdown,
                'revenue_by_currency' => $this->revenueByCurrency(
                    Order::where('status', 'completed')->whereYear('created_at', Carbon::now()->year)
                ),
            ] + $this->revenueCurrencyContext(
                Order::where('status', 'completed')->whereYear('created_at', Carbon::now()->year)
            );
        });
    }

    public function getOrderStatusOverview(Request $request): array
    {
        return Cache::remember('dashboard_order_stats', 300, function () {
            $countByDays = function (int $days): array {
                $results = Order::select('status', DB::raw('count(*) as order_count'))
                    ->whereDate('created_at', '>', Carbon::now()->subDays($days))
                    ->groupBy('status')
                    ->pluck('order_count', 'status');

                // Canonical statuses come from the Order model itself (the
                // single source of truth). Counts are derived from the
                // actual data; unknown/future statuses flow through
                // dynamically instead of being silently dropped.
                $counts = [
                    Order::ORDER_STATUS_PENDING => 0,
                    Order::ORDER_STATUS_PROCESSING => 0,
                    Order::ORDER_STATUS_COMPLETED => 0,
                    Order::ORDER_STATUS_CANCELLED => 0,
                    Order::ORDER_STATUS_DELIVERED => 0,
                ];

                foreach ($results as $status => $count) {
                    $counts[$status] = (int) $count;
                }

                // Legacy response keys kept for backward client
                // compatibility. These are payment/fulfillment concepts and
                // are not stored as order.status values; they remain zero
                // unless the data layer ever contains them.
                return $counts + [
                    'refunded'         => 0,
                    'failed'           => 0,
                    'local_facility'   => 0,
                    'out_for_delivery' => 0,
                ];
            };

            return [
                'today'   => $countByDays(1),
                'weekly'  => $countByDays(7),
                'monthly' => $countByDays(30),
                'yearly'  => $countByDays(365),
            ];
        });
    }

    public function getRecentOrders(Request $request, int $limit = 10)
    {
        return Cache::remember("dashboard_recent_orders_{$limit}", 300, function () use ($limit) {
            return Order::with(['user', 'pickupLocation'])
                ->take($limit)
                ->get();
        });
    }

    public function getTopSellingProducts(Request $request, int $limit = 10)
    {
        return Cache::remember("dashboard_top_products_{$limit}", 300, function () use ($limit) {
            return Product::where('sold_quantity', '>', 0)
                ->orderBy('sold_quantity', 'desc')
                ->take($limit)
                ->get(['id', 'name', 'slug', 'price', 'sold_quantity']);
        });
    }

    public function getCategoryStats(Request $request): array
    {
        return Cache::remember('dashboard_category_stats', 300, function () {
            $productCounts = DB::table('category_product')
                ->select(
                    'categories.id as category_id',
                    'categories.name as category_name',
                    DB::raw('COUNT(category_product.product_id) as product_count')
                )
                ->join('products', 'category_product.product_id', '=', 'products.id')
                ->join('categories', 'category_product.category_id', '=', 'categories.id')
                ->groupBy('categories.id', 'categories.name')
                ->orderBy('product_count', 'desc')
                ->limit(15)
                ->get();

            $salesData = DB::table('categories')
                ->select(
                    'categories.id as category_id',
                    'categories.name as category_name',
                    DB::raw('COALESCE(SUM(order_products.product_quantity), 0) as total_sales')
                )
                ->leftJoin('category_product', 'category_product.category_id', '=', 'categories.id')
                ->leftJoin('products', 'category_product.product_id', '=', 'products.id')
                ->leftJoin('order_products', 'order_products.product_id', '=', 'products.id')
                ->leftJoin('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->groupBy('categories.id', 'categories.name')
                ->orderBy('total_sales', 'desc')
                ->limit(15)
                ->get();

            return [
                'product_distribution' => $productCounts,
                'sales_distribution'   => $salesData,
            ];
        });
    }

    public function getLowStockProducts(Request $request, int $limit = 10)
    {
        return Cache::remember("dashboard_low_stock_{$limit}", 300, function () use ($limit) {
            // Low stock is a PHYSICAL-inventory concept; digital products
            // have no stock semantics and must not appear here.
            return Product::with('type')
                ->physical()
                ->where('stock_quantity', '<', 10)
                ->take($limit)
                ->get();
        });
    }

    // =========================================================================
    // 1. Sales Analytics
    // =========================================================================

    public function getSalesAnalytics(Request $request): array
    {
        return Cache::remember('dashboard_sales_analytics', 300, function () {
            $now = Carbon::now();

            $today = (float) Order::where('status', 'completed')
                ->whereDate('created_at', $now->toDateString())
                ->sum(DB::raw(self::BASE_REVENUE_RAW));

            $yesterday = (float) Order::where('status', 'completed')
                ->whereDate('created_at', $now->copy()->subDay()->toDateString())
                ->sum(DB::raw(self::BASE_REVENUE_RAW));

            $last7Days = (float) Order::where('status', 'completed')
                ->whereDate('created_at', '>', $now->copy()->subDays(7))
                ->sum(DB::raw(self::BASE_REVENUE_RAW));

            $last30Days = (float) Order::where('status', 'completed')
                ->whereDate('created_at', '>', $now->copy()->subDays(30))
                ->sum(DB::raw(self::BASE_REVENUE_RAW));

            $todayVsYesterday = $this->percentageChange($yesterday, $today);

            $thisMonth = (float) Order::where('status', 'completed')
                ->whereYear('created_at', $now->year)
                ->whereMonth('created_at', $now->month)
                ->sum(DB::raw(self::BASE_REVENUE_RAW));

            $lastMonth = (float) Order::where('status', 'completed')
                ->whereYear('created_at', $now->copy()->subMonth()->year)
                ->whereMonth('created_at', $now->copy()->subMonth()->month)
                ->sum(DB::raw(self::BASE_REVENUE_RAW));

            $thisYear = (float) Order::where('status', 'completed')
                ->whereYear('created_at', $now->year)
                ->sum(DB::raw(self::BASE_REVENUE_RAW));

            $lastYear = (float) Order::where('status', 'completed')
                ->whereYear('created_at', $now->copy()->subYear()->year)
                ->sum(DB::raw(self::BASE_REVENUE_RAW));

            $completedOrders = Order::where('status', 'completed')->count();
            $completedRevenue = (float) Order::where('status', 'completed')->sum(DB::raw(self::BASE_REVENUE_RAW));
            $aov = $completedOrders > 0 ? round($completedRevenue / $completedOrders, 2) : 0;

            $revenueByPayment = DB::table('transactions')
                ->join('orders', 'transactions.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->select('transactions.payment_method', DB::raw('SUM(COALESCE(orders.converted_total_price, orders.total_price)) as total'))
                ->groupBy('transactions.payment_method')
                ->pluck('total', 'payment_method');

            $revenueByPaymentMethod = $revenueByPayment->map(function ($total, $method) {
                return ['method' => $method, 'total' => round((float) $total, 2)];
            })->values();

            $revenueByFulfillment = Order::where('status', 'completed')
                ->select('fulfillment_type', DB::raw('SUM(COALESCE(converted_total_price, total_price)) as total'))
                ->groupBy('fulfillment_type')
                ->pluck('total', 'fulfillment_type');

            $revenueByFulfillmentType = $revenueByFulfillment->map(function ($total, $type) {
                return ['fulfillment_type' => $type ?: 'delivery', 'total' => round((float) $total, 2)];
            })->values();

            return [
                'daily_revenue' => [
                    'today'      => round($today, 2),
                    'yesterday'  => round($yesterday, 2),
                    'last_7_days' => round($last7Days, 2),
                    'last_30_days' => round($last30Days, 2),
                ],
                'revenue_comparison' => [
                    'today_vs_yesterday' => [
                        'today'    => round($today, 2),
                        'yesterday' => round($yesterday, 2),
                        'change'   => $todayVsYesterday,
                    ],
                    'this_month_vs_last_month' => [
                        'this_month' => round($thisMonth, 2),
                        'last_month' => round($lastMonth, 2),
                        'change'     => $this->percentageChange($lastMonth, $thisMonth),
                    ],
                    'this_year_vs_last_year' => [
                        'this_year' => round($thisYear, 2),
                        'last_year' => round($lastYear, 2),
                        'change'    => $this->percentageChange($lastYear, $thisYear),
                    ],
                ],
                'average_order_value' => $aov,
                'revenue_by_payment_method' => $revenueByPaymentMethod,
                'revenue_by_fulfillment_type' => $revenueByFulfillmentType,
            ] + $this->revenueCurrencyContext(
                Order::where('status', 'completed')
            );
        });
    }

    // =========================================================================
    // 2. Customer Analytics
    // =========================================================================

    public function getCustomerAnalytics(Request $request): array
    {
        return Cache::remember('dashboard_customer_analytics', 300, function () {
            $now = Carbon::now();

            $totalCustomers = User::where('type', UserType::USER->value)->count();

            $customersBefore = User::where('type', UserType::USER->value)
                ->whereDate('created_at', '<=', $now->copy()->subDays(30))
                ->count();

            $customersInLast30 = User::where('type', UserType::USER->value)
                ->whereDate('created_at', '>', $now->copy()->subDays(30))
                ->count();

            $returningCustomers = 0;
            if ($totalCustomers > 0) {
                $customerIds = User::where('type', UserType::USER->value)->pluck('id');
                $returningCustomers = Order::whereIn('user_id', $customerIds)
                    ->whereDate('created_at', '>', $now->copy()->subDays(30))
                    ->distinct('user_id')
                    ->count('user_id');
            }

            $monthlyGrowth = User::where('type', UserType::USER->value)
                ->select(
                    DB::raw($this->dateFormat('%Y-%m') . " as month"),
                    DB::raw('COUNT(*) as count')
                )
                ->whereDate('created_at', '>', $now->copy()->subMonths(12))
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn ($row) => ['month' => $row->month, 'count' => (int) $row->count]);

            $topByOrders = User::where('type', UserType::USER->value)
                ->withoutGlobalScope('order')
                ->withCount(['orders' => fn ($q) => $q->where('status', 'completed')])
                ->reorder('orders_count', 'desc')
                ->take(10)
                ->get(['id', 'name', 'email'])
                ->map(fn ($u) => [
                    'id'     => $u->id,
                    'name'   => $u->name,
                    'email'  => $u->email,
                    'orders' => (int) $u->orders_count,
                ]);

            $topByRevenue = User::where('type', UserType::USER->value)
                ->withoutGlobalScope('order')
                ->select('users.id', 'users.name', 'users.email')
                ->join('orders', 'users.id', '=', 'orders.user_id')
                ->where('orders.status', 'completed')
                ->selectRaw('SUM(orders.total_price) as total_revenue')
                ->groupBy('users.id', 'users.name', 'users.email')
                ->reorder('total_revenue', 'desc')
                ->take(10)
                ->get()
                ->map(fn ($u) => [
                    'id'     => $u->id,
                    'name'   => $u->name,
                    'email'  => $u->email,
                    'revenue' => round((float) $u->total_revenue, 2),
                ]);

            $clv = User::where('type', UserType::USER->value)
                ->withoutGlobalScope('order')
                ->select('users.id', 'users.name', 'users.email')
                ->join('orders', 'users.id', '=', 'orders.user_id')
                ->where('orders.status', 'completed')
                ->selectRaw('SUM(orders.total_price) as lifetime_value')
                ->groupBy('users.id', 'users.name', 'users.email')
                ->reorder('lifetime_value', 'desc')
                ->take(10)
                ->get()
                ->map(fn ($u) => [
                    'id'             => $u->id,
                    'name'           => $u->name,
                    'email'          => $u->email,
                    'lifetime_value' => round((float) $u->lifetime_value, 2),
                ]);

            $active7 = User::where('type', UserType::USER->value)
                ->whereHas('orders', fn ($q) => $q->whereDate('created_at', '>', $now->copy()->subDays(7)))
                ->count();

            $active30 = User::where('type', UserType::USER->value)
                ->whereHas('orders', fn ($q) => $q->whereDate('created_at', '>', $now->copy()->subDays(30)))
                ->count();

            $active90 = User::where('type', UserType::USER->value)
                ->whereHas('orders', fn ($q) => $q->whereDate('created_at', '>', $now->copy()->subDays(90)))
                ->count();

            return [
                'new_vs_returning' => [
                    'new_customers'       => $customersInLast30,
                    'returning_customers' => $returningCustomers,
                ],
                'monthly_growth'        => $monthlyGrowth,
                'top_customers'         => [
                    'by_orders' => $topByOrders,
                    'by_revenue' => $topByRevenue,
                ],
                'customer_lifetime_value' => $clv,
                'active_customers'        => [
                    'last_7_days'  => $active7,
                    'last_30_days' => $active30,
                    'last_90_days' => $active90,
                ],
            ];
        });
    }

    // =========================================================================
    // 3. Product Analytics
    // =========================================================================

    public function getProductAnalytics(Request $request): array
    {
        return Cache::remember('dashboard_product_analytics', 300, function () {
            $limit = 10;

            $bestSelling = Product::where('sold_quantity', '>', 0)
                ->orderBy('sold_quantity', 'desc')
                ->take($limit)
                ->get(['id', 'name', 'slug', 'price', 'sold_quantity']);

            $worstSelling = Product::where('sold_quantity', '>', 0)
                ->orderBy('sold_quantity', 'asc')
                ->take($limit)
                ->get(['id', 'name', 'slug', 'price', 'sold_quantity']);

            $neverSold = Product::where(function ($q) {
                $q->where('sold_quantity', 0)->orWhereNull('sold_quantity');
            })->take($limit)->get(['id', 'name', 'slug', 'price', 'sold_quantity']);

            $outOfStock = Product::physical()
                ->where('stock_quantity', 0)
                ->take($limit)
                ->get(['id', 'name', 'slug', 'price', 'quantity']);

            // Inventory value is a PHYSICAL-stock concept. Digital products
            // have no stock semantics and must not inflate this figure.
            $inventoryValue = (float) Product::physical()
                ->selectRaw('SUM(price * stock_quantity) as total')
                ->where('stock_quantity', '>', 0)
                ->value('total');

            return [
                'best_selling'    => $bestSelling,
                'worst_selling'   => $worstSelling,
                'never_sold'      => $neverSold,
                'out_of_stock'    => $outOfStock,
                'inventory_value' => round($inventoryValue, 2),
                // Additive digital-goods block (counts only).
                'digital'         => $this->getDigitalAnalytics(),
            ];
        });
    }

    // =========================================================================
    // 4. Order Analytics
    // =========================================================================

    public function getOrderAnalytics(Request $request): array
    {
        return Cache::remember('dashboard_order_analytics', 300, function () {
            $now = Carbon::now();

            $timelineDaily = Order::select(
                    DB::raw("DATE(created_at) as date"),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(COALESCE(converted_total_price, total_price)) as revenue')
                )
                ->whereDate('created_at', '>', $now->copy()->subDays(30))
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(fn ($r) => [
                    'date'    => $r->date,
                    'orders'  => (int) $r->count,
                    'revenue' => round((float) $r->revenue, 2),
                ]);

            $timelineWeekly = Order::select(
                    DB::raw($this->dateFormat('%Y-%u') . " as week"),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(COALESCE(converted_total_price, total_price)) as revenue')
                )
                ->whereDate('created_at', '>', $now->copy()->subMonths(6))
                ->groupBy('week')
                ->orderBy('week')
                ->get()
                ->map(fn ($r) => [
                    'week'    => (int) $r->week,
                    'orders'  => (int) $r->count,
                    'revenue' => round((float) $r->revenue, 2),
                ]);

            $timelineMonthly = Order::select(
                    DB::raw($this->dateFormat('%Y-%m') . " as month"),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(COALESCE(converted_total_price, total_price)) as revenue')
                )
                ->whereDate('created_at', '>', $now->copy()->subYears(2))
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn ($r) => [
                    'month'   => $r->month,
                    'orders'  => (int) $r->count,
                    'revenue' => round((float) $r->revenue, 2),
                ]);

            $totalOrders = Order::count();
            $completedOrders = Order::where('status', 'completed')->count();
            $cancelledOrders = Order::where('status', 'cancelled')->count();

            $refundedCount = DB::table('refunds')
                ->where('status', 'approved')
                ->distinct('order_id')
                ->count('order_id');

            $successRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $cancelledRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
            $refundRate = $completedOrders > 0 ? round(($refundedCount / $completedOrders) * 100, 2) : 0;

            return [
                'timeline' => [
                    'daily'   => $timelineDaily,
                    'weekly'  => $timelineWeekly,
                    'monthly' => $timelineMonthly,
                ],
                'success_rate' => [
                    'completed' => $successRate,
                    'cancelled' => $cancelledRate,
                    'refunded'  => $refundRate,
                    'total'     => $totalOrders,
                ],
                'refund_rate' => $refundRate,
            ];
        });
    }

    // =========================================================================
    // 5. Category Analytics
    // =========================================================================

    public function getCategoryAnalytics(Request $request): array
    {
        return Cache::remember('dashboard_category_analytics', 300, function () {
            $now = Carbon::now();

            $productCounts = DB::table('category_product')
                ->select(
                    'categories.id as category_id',
                    'categories.name as category_name',
                    DB::raw('COUNT(category_product.product_id) as product_count')
                )
                ->join('products', 'category_product.product_id', '=', 'products.id')
                ->join('categories', 'category_product.category_id', '=', 'categories.id')
                ->groupBy('categories.id', 'categories.name')
                ->orderBy('product_count', 'desc')
                ->limit(15)
                ->get();

            $revenueByCategory = DB::table('categories')
                ->select(
                    'categories.id as category_id',
                    'categories.name as category_name',
                    DB::raw('COALESCE(SUM(order_products.product_quantity * order_products.product_price * COALESCE(orders.currency_rate, 1)), 0) as revenue')
                )
                ->leftJoin('category_product', 'category_product.category_id', '=', 'categories.id')
                ->leftJoin('products', 'category_product.product_id', '=', 'products.id')
                ->leftJoin('order_products', 'order_products.product_id', '=', 'products.id')
                ->leftJoin('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->groupBy('categories.id', 'categories.name')
                ->orderBy('revenue', 'desc')
                ->limit(15)
                ->get()
                ->map(fn ($r) => [
                    'category_id'   => $r->category_id,
                    'category_name' => $r->category_name,
                    'revenue'       => round((float) $r->revenue, 2),
                ]);

            $currentMonthRevenue = DB::table('categories')
                ->select(
                    'categories.id as category_id',
                    'categories.name as category_name',
                    DB::raw('COALESCE(SUM(order_products.product_quantity * order_products.product_price * COALESCE(orders.currency_rate, 1)), 0) as revenue')
                )
                ->leftJoin('category_product', 'category_product.category_id', '=', 'categories.id')
                ->leftJoin('products', 'category_product.product_id', '=', 'products.id')
                ->leftJoin('order_products', 'order_products.product_id', '=', 'products.id')
                ->leftJoin('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->whereYear('orders.created_at', $now->year)
                ->whereMonth('orders.created_at', $now->month)
                ->groupBy('categories.id', 'categories.name')
                ->pluck('revenue', 'category_id');

            $prevMonthRevenue = DB::table('categories')
                ->select(
                    'categories.id as category_id',
                    DB::raw('COALESCE(SUM(order_products.product_quantity * order_products.product_price * COALESCE(orders.currency_rate, 1)), 0) as revenue')
                )
                ->leftJoin('category_product', 'category_product.category_id', '=', 'categories.id')
                ->leftJoin('products', 'category_product.product_id', '=', 'products.id')
                ->leftJoin('order_products', 'order_products.product_id', '=', 'products.id')
                ->leftJoin('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->whereYear('orders.created_at', $now->copy()->subMonth()->year)
                ->whereMonth('orders.created_at', $now->copy()->subMonth()->month)
                ->groupBy('categories.id')
                ->pluck('revenue', 'category_id');

            $categoryGrowth = $revenueByCategory->map(function ($cat) use ($currentMonthRevenue, $prevMonthRevenue) {
                $current = (float) ($currentMonthRevenue[$cat['category_id']] ?? 0);
                $previous = (float) ($prevMonthRevenue[$cat['category_id']] ?? 0);

            return [
                    'category_id'   => $cat['category_id'],
                    'category_name' => $cat['category_name'],
                    'current_month' => round($current, 2),
                    'previous_month' => round($previous, 2),
                    'change'        => $this->percentageChange($previous, $current),
                ];
            })->values();

            $highestRevenue = $revenueByCategory->take(5)->values();
            $lowestRevenue = $revenueByCategory->reverse()->take(5)->values();

            return [
                'product_distribution' => $productCounts,
                'highest_revenue'      => $highestRevenue,
                'lowest_revenue'       => $lowestRevenue,
                'category_growth'      => $categoryGrowth,
            ];
        });
    }

    // =========================================================================
    // 6. Coupon Analytics
    // =========================================================================

    public function getCouponAnalytics(Request $request): array
    {
        return Cache::remember('dashboard_coupon_analytics', 300, function () {
            $totalUsage = DB::table('coupon_usages')->count();

            $topCoupons = DB::table('coupon_usages')
                ->join('coupons', 'coupon_usages.coupon_id', '=', 'coupons.id')
                ->select('coupons.id', 'coupons.code', 'coupons.name', DB::raw('COUNT(*) as usage_count'))
                ->groupBy('coupons.id', 'coupons.code', 'coupons.name')
                ->orderBy('usage_count', 'desc')
                ->take(10)
                ->get();

            $revenueByCoupon = DB::table('orders')
                ->whereNotNull('coupon')
                ->where('status', 'completed')
                ->select('coupon', DB::raw('SUM(COALESCE(converted_total_price, total_price)) as revenue'))
                ->groupBy('coupon')
                ->orderBy('revenue', 'desc')
                ->take(10)
                ->get()
                ->map(fn ($r) => [
                    'code'    => $r->coupon,
                    'revenue' => round((float) $r->revenue, 2),
                ]);

            $totalDiscount = (float) Order::whereNotNull('coupon_discount')
                ->sum('coupon_discount');

            return [
                'total_usage'        => $totalUsage,
                'top_coupons'        => $topCoupons,
                'revenue_by_coupon'  => $revenueByCoupon,
                'total_coupon_discount' => round($totalDiscount, 2),
            ];
        });
    }

    // =========================================================================
    // 7. Cart Analytics
    // =========================================================================

    public function getCartAnalytics(Request $request): array
    {
        return Cache::remember('dashboard_cart_analytics', 300, function () {
            $totalCarts = DB::table('carts')->count();
            $abandonedCarts = DB::table('carts')
                ->whereIn('status', ['active', 'expired'])
                ->count();

            $abandonmentRate = $totalCarts > 0
                ? round(($abandonedCarts / $totalCarts) * 100, 2)
                : 0;

            $mostAdded = DB::table('cart_items')
                ->join('products', 'cart_items.product_id', '=', 'products.id')
                ->select('products.id', 'products.name', 'products.slug', 'products.price',
                    DB::raw('SUM(cart_items.quantity) as total_added'))
                ->groupBy('products.id', 'products.name', 'products.slug', 'products.price')
                ->orderBy('total_added', 'desc')
                ->take(10)
                ->get()
                ->map(fn ($r) => [
                    'id'          => $r->id,
                    'name'        => $r->name,
                    'slug'        => $r->slug,
                    'price'       => round((float) $r->price, 2),
                    'total_added' => (int) $r->total_added,
                ]);

            $avgCartValue = (float) DB::table('carts')
                ->whereIn('status', ['active', 'checked_out'])
                ->avg('total_price');

            $totalCheckouts = DB::table('carts')
                ->where('status', 'checked_out')
                ->count();

            $totalCartCreations = DB::table('carts')->count();
            $checkoutDropoffRate = $totalCartCreations > 0
                ? round((1 - ($totalCheckouts / $totalCartCreations)) * 100, 2)
                : 0;

            return [
                'abandonment_rate'      => $abandonmentRate,
                'most_added_products'   => $mostAdded,
                'average_cart_value'    => round($avgCartValue, 2),
                'checkout_dropoff_rate' => $checkoutDropoffRate,
            ];
        });
    }

    // =========================================================================
    // 8. Finance Analytics
    // =========================================================================

    public function getFinanceAnalytics(Request $request): array
    {
        return Cache::remember('dashboard_finance_analytics', 300, function () {
            $grossRevenue = (float) Order::where('status', 'completed')
                ->selectRaw('SUM(' . self::BASE_REVENUE_RAW . ') as agg')
                ->value('agg');

            // REFUND CURRENCY: see refundByCurrency()/refundCurrencyContext().
            // The scalar below is kept for backward compatibility; the
            // authoritative split is `refund_by_currency` (+ flag) in this
            // response. Gateway (online) refunds are NOT aggregated here —
            // they live per-transaction in the payment ledger and are always
            // authorized in the original transaction currency
            // (see PaymentRefundService). Do not subtract this scalar from a
            // base-denominated gross when multiple currencies are in play.
            $refundAmount = (float) DB::table('refunds')
                ->where('refunds.status', 'approved')
                ->sum('amount');

            $couponDiscount = (float) Order::whereNotNull('coupon_discount')
                ->sum('coupon_discount');
            $promotionDiscount = (float) Order::where('promotion_discount', '>', 0)
                ->sum('promotion_discount');
            // SCOPE NOTE (S2): the two scalars above span ALL order statuses
            // (legacy behavior, preserved), while discount_by_currency below
            // covers completed orders only — matching gross_by_currency and
            // the realized-revenue semantics of this endpoint. Buckets
            // therefore reconcile with each other, not with total_discount.
            $totalDiscount = $couponDiscount + $promotionDiscount;

            $netRevenue = $grossRevenue - $refundAmount;

            $shippingRevenue = (float) Order::where('status', 'completed')
                ->selectRaw('COALESCE(SUM(shipping_price * COALESCE(currency_rate, 1)), 0) + COALESCE(SUM(fast_shipping_fee * COALESCE(currency_rate, 1)), 0) as total')
                ->value('total');

            // Per-currency discount split (order/transaction currency): the
            // total_discount scalar adds coupon + promotion discounts that are
            // each denominated in their order's currency_code.
            $discountByCurrency = Order::where('status', 'completed')
                ->selectRaw("COALESCE(currency_code, 'UNKNOWN') as currency, SUM(COALESCE(coupon_discount, 0) + COALESCE(promotion_discount, 0)) as total")
                ->groupBy('currency')
                ->orderBy('currency')
                ->pluck('total', 'currency')
                ->map(fn ($total) => round((float) $total, 2))
                ->all();

            // Per-base-era shipping split: each row is converted with its own
            // STORED currency_rate (never today's rate); buckets keep eras
            // separate so the scalar is not mistaken for single-unit truth.
            $shippingByBase = Order::where('status', 'completed')
                ->selectRaw("COALESCE(base_currency_code, 'UNKNOWN') as base_currency_code, COALESCE(SUM((COALESCE(shipping_price, 0) + COALESCE(fast_shipping_fee, 0)) * COALESCE(currency_rate, 1)), 0) as total")
                ->groupBy('base_currency_code')
                ->orderBy('base_currency_code')
                ->pluck('total', 'base_currency_code')
                ->map(fn ($total) => round((float) $total, 2))
                ->all();

            // Per-order-currency NET (§9 business rule): net is computed WITHIN
            // each transaction currency — completed gross minus marketplace
            // refunds (completed-scope join) minus gateway-ledger refunds —
            // so no two different units are ever added or subtracted. Gross here
            // is SUM(total_price) in order currency (NOT the base-denominated
            // gross_revenue scalar). The refund side is scoped to refunds on
            // COMPLETED, non-trashed orders — the same population gross
            // covers (orphan/pending/trashed refunds stay visible ONLY in
            // refund_by_currency and are never netted). NULL currency →
            // UNKNOWN on both sides; UNKNOWN buckets are never authoritative.
            $grossByTxn = Order::where('status', 'completed')
                ->selectRaw("COALESCE(currency_code, 'UNKNOWN') as currency, SUM(total_price) as total")
                ->groupBy('currency')
                ->orderBy('currency')
                ->pluck('total', 'currency')
                ->map(fn ($total) => round((float) $total, 2))
                ->all();

            $netRefundBuckets = DB::table('refunds')
                ->join('orders as refund_orders', 'refund_orders.id', '=', 'refunds.order_id')
                ->where('refunds.status', 'approved')
                ->where('refund_orders.status', 'completed')
                ->whereNull('refund_orders.deleted_at')
                ->selectRaw("COALESCE(refund_orders.currency_code, 'UNKNOWN') as currency, SUM(refunds.amount) as total")
                ->groupBy('currency')
                ->orderBy('currency')
                ->pluck('total', 'currency')
                ->map(fn ($total) => round((float) $total, 2))
                ->all();

            $netByCurrency = [];
            // Net within one unit: completed gross minus marketplace refunds
            // (completed-scope join) minus gateway-ledger refunds (txn
            // currency). All three maps share the order/transaction currency
            // key space, so per-key subtraction never mixes units.
            $gatewayBuckets = $this->gatewayRefundByCurrency();
            foreach (array_unique(array_merge(array_keys($grossByTxn), array_keys($netRefundBuckets), array_keys($gatewayBuckets))) as $code) {
                $netByCurrency[$code] = round(
                    (float) ($grossByTxn[$code] ?? 0)
                    - (float) ($netRefundBuckets[$code] ?? 0)
                    - (float) ($gatewayBuckets[$code] ?? 0),
                    2
                );
            }
            ksort($netByCurrency);

            return [
                'gross_revenue'    => round($grossRevenue, 2),
                'net_revenue'      => round(max($netRevenue, 0), 2),
                'refund_amount'    => round($refundAmount, 2),
                'total_discount'   => round($totalDiscount, 2),
                'shipping_revenue' => round($shippingRevenue, 2),
                // Additive: per-currency gross breakdown so multi-currency
                // stores can see the raw split that feeds net_revenue.
                'gross_by_currency' => $this->revenueByCurrency(
                    Order::where('status', 'completed')
                ),
                // NET REVENUE POLICY (unresolved business rule): net_revenue
                // = base-denominated gross minus order-currency refunds, with
                // NO conversion policy when units differ. Authoritative splits
                // are discount_by_currency / shipping_by_base_currency /
                // refund_by_currency; see FINAL report §11.
                'discount_by_currency' => $discountByCurrency,
                'mixed_discount_currencies' => count(array_keys(array_filter(
                    $discountByCurrency,
                    fn ($total) => (float) $total != 0.0
                ))) > 1,
                'shipping_by_base_currency' => $shippingByBase,
                // Within-currency net (same-unit subtraction only); the legacy
                // net_revenue scalar is kept for backward compatibility.
                'net_revenue_by_currency' => $netByCurrency,
                'mixed_net_currencies' => count(array_keys(array_filter(
                    $netByCurrency,
                    fn ($total) => (float) $total != 0.0
                ))) > 1,
            ] + $this->revenueCurrencyContext(
                Order::where('status', 'completed')
            ) + $this->refundCurrencyContext(
                DB::table('refunds')->where('refunds.status', 'approved')
            );
        });
    }

    // =========================================================================
    // 9. Payment Reconciliation
    // =========================================================================

    public function getReconciliationSummary(): array
    {
        $totalChecked = Transaction::query()
            ->whereNotNull('gateway_transaction_id')
            ->where('status', '!=', 'failed')
            ->count();

        $totalMismatches = PaymentReconciliationResult::count();
        $pendingMismatches = PaymentReconciliationResult::unresolved()->count();
        $resolvedMismatches = PaymentReconciliationResult::resolved()->count();
        $lastRun = PaymentReconciliationResult::query()
            ->latest('created_at')
            ->first()
            ?->created_at;

        return [
            'total_checked' => $totalChecked,
            'total_mismatches' => $totalMismatches,
            'pending_mismatches' => $pendingMismatches,
            'resolved_mismatches' => $resolvedMismatches,
            'last_run' => $lastRun,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function dateFormat(string $format): string
    {
        // MySQL-only date formatting (SQLite support removed with the
        // project-wide MySQL standardization; DATE_FORMAT/YEARWEEK are canonical).
        $map = [
            '%M' => "DATE_FORMAT(created_at, '%M')",
            '%Y-%m' => "DATE_FORMAT(created_at, '%Y-%m')",
            '%Y-%u' => "YEARWEEK(created_at, 1)",
            '%c' => "DATE_FORMAT(created_at, '%c')",
        ];

        return $map[$format] ?? "DATE_FORMAT(created_at, '{$format}')";
    }

    private function percentageChange(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }

    /**
     * Per-currency refund buckets: currency => total.
     *
     * REFUND CURRENCY CONTRACT (Model A + C): the marketplace `refunds` table
     * carries no currency column, but creation requires `order_id`
     * (RefundController OpenAPI: title/description/order_id/amount) and
     * execution/partial-full comparison are denominated in the order's
     * currency (`$gateway->refund($refund->order, $refund->amount)`,
     * `$refund->amount >= $order->total`). Refund currency is therefore
     * provably `orders.currency_code` via the order join. Refunds with no
     * linked order fall into 'UNKNOWN' and must never merge into a real
     * currency bucket. Gateway (online) refunds are NOT in this table — they
     * live per-transaction in the payment ledger in txn currency.
     *
     * Scope matches the paired scalar exactly so buckets reconcile with it.
     * Trashed orders remain joined BY DESIGN: DB::table applies no
     * SoftDeletes scope, so a refund on a soft-deleted order keeps its
     * currency (the refund itself is still real money). Revenue buckets use
     * Eloquent Order and therefore exclude trashed rows — the two paths
     * intentionally differ here.
     *
     * Payload-shape note: responses are cached up to 300s across deploys, so
     * API clients must null-tolerate the additive bucket keys.
     */
    private function refundByCurrency($refundQuery): array
    {
        return (clone $refundQuery)
            ->leftJoin('orders', 'orders.id', '=', 'refunds.order_id')
            ->selectRaw("COALESCE(orders.currency_code, 'UNKNOWN') as currency, SUM(refunds.amount) as total")
            ->groupBy('currency')
            ->orderBy('currency')
            ->pluck('total', 'currency')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    private function refundCurrencyContext($refundQuery): array
    {
        $buckets = $this->refundByCurrency($refundQuery);
        $gatewayBuckets = $this->gatewayRefundByCurrency();
        $active = array_keys(array_filter(
            array_merge($buckets, $gatewayBuckets),
            fn ($total) => (float) $total != 0.0
        ));

        return [
            // Marketplace approval-workflow refunds (scalar parity).
            'refund_by_currency' => $buckets,
            // Provider-executed (online) refunds from each transaction's
            // own _refunds ledger, keyed by that transaction's currency.
            // These never touch the marketplace table by design.
            'gateway_refund_by_currency' => $gatewayBuckets,
            // SEMANTICS (widened): true when EITHER refund system spans
            // currencies. Previously marketplace-only; gateway refunds were
            // invisible to this flag (false negatives). Documented change.
            'mixed_refund_currencies' => count($active) > 1,
        ];
    }

    /**
     * Gateway (online) refund buckets from transaction ledgers.
     *
     * Every entry in a transaction's gateway_response._refunds ledger is
     * denominated in that transaction's own currency (enforced by
     * PaymentRefundService: request, provider result and ledger math must
     * all match $txn->currency). Grouping ledger amounts by txn currency
     * is therefore unit-safe with no join and no conversion.
     * Scope (completed orders) matches the gross side of net math.
     */
    private function gatewayRefundByCurrency(): array
    {
        $buckets = [];

        Transaction::query()
            ->join('orders', 'orders.id', '=', 'transactions.order_id')
            ->where('orders.status', 'completed')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('transactions.gateway_response')
            ->select('transactions.currency', 'transactions.gateway_response')
            ->orderBy('transactions.id')
            ->chunk(500, function ($txns) use (&$buckets) {
                foreach ($txns as $txn) {
                    $response = $txn->gateway_response;
                    $ledger = is_array($response) ? ($response['_refunds'] ?? null) : null;
                    if (!is_array($ledger)) {
                        continue;
                    }
                    $code = strtoupper(trim((string) $txn->currency)) ?: 'UNKNOWN';
                    foreach ($ledger as $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }
                        $buckets[$code] = round(($buckets[$code] ?? 0) + (float) ($entry['amount'] ?? 0), 2);
                    }
                }
            });

        ksort($buckets);

        return $buckets;
    }

    /**
     * Base-currency-safe revenue expression helper (see BASE_REVENUE_RAW).
     * Orders created after the currency feature carry converted_total_price
     * (base-denominated at purchase-time rate); legacy orders are already
     * base-denominated in total_price.
     *
     * FINANCIAL REPORTING CONTRACT: a raw SUM of converted_total_price is only
     * meaningful when every summed row shares one base_currency_code. These
     * bucket helpers expose the per-era split so no consumer ever mistakes a
     * mixed-era scalar for a single-currency total. Scalars are preserved for
     * backward compatibility; buckets are the authoritative breakdown.
     */
    private function revenueByCurrency($orderQuery): array
    {
        return (clone $orderQuery)
            // LEGACY LABEL (compat): NULL txn currency lands in 'BASE' here.
            // All newer maps use 'UNKNOWN'; this key is preserved unchanged
            // so existing consumers of revenue_by_currency keep working.
            ->selectRaw("COALESCE(currency_code, 'BASE') as currency_code, SUM(total_price) as total")
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->pluck('total', 'currency_code')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    /**
     * Per-base-era revenue buckets: base_currency_code => total, where each
     * total is a SUM of converted_total_price rows that all share that base
     * code. Rows with a NULL base (pre-snapshot legacy) fall into 'UNKNOWN'
     * and must never be merged into a base-denominated bucket.
     */
    private function revenueByBaseCurrency($orderQuery): array
    {
        return (clone $orderQuery)
            ->selectRaw("COALESCE(base_currency_code, 'UNKNOWN') as base_currency_code, SUM(" . self::BASE_REVENUE_RAW . ') as total')
            ->groupBy('base_currency_code')
            ->orderBy('base_currency_code')
            ->pluck('total', 'base_currency_code')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    /**
     * Currency context for every base-denominated scalar in this service.
     *
     * - revenue_currency: the CURRENT global base code (unit of new rows).
     * - revenue_by_base_currency: per-era buckets (authoritative split).
     * - mixed_base_eras: true when completed rows span >1 base code, in which
     *   case any ungrouped scalar mixes units and must not be presented as a
     *   single-currency total.
     */
    private function revenueCurrencyContext($orderQuery): array
    {
        try {
            $buckets = $this->revenueByBaseCurrency($orderQuery);
        } catch (\Throwable $e) {
            // Partial/legacy schema without the snapshot columns: the legacy
            // scalars above already assume those columns, so only the additive
            // buckets degrade (never the endpoint itself).
            report($e);
            $buckets = [];
        }

        // Every nonzero bucket — including 'UNKNOWN' legacy rows resolved via
        // the COALESCE fallback — contributes a distinct unit to any ungrouped
        // scalar, so more than one means the scalar mixes units.
        $activeCodes = array_keys(array_filter(
            $buckets,
            fn ($total) => (float) $total != 0.0
        ));

        try {
            $currentBase = strtoupper((string) app(\App\Services\Currency\CurrencyService::class)->getBaseCode());
        } catch (\Throwable $e) {
            $currentBase = strtoupper((string) config('shop.default_currency', 'USD'));
        }

        return [
            'revenue_currency' => $currentBase,
            'revenue_by_base_currency' => $buckets,
            'mixed_base_eras' => count($activeCodes) > 1,
        ];
    }

    /**
     * Aggregated digital-goods analytics (counts only — no license
     * secrets, no customer PII).
     */
    private function getDigitalAnalytics(): array
    {
        $now = Carbon::now();

        return [
            'digital_products' => (int) Product::digital()->count(),
            'digital_units_sold' => (int) Product::digital()->sum('sold_quantity'),
            'entitlements' => [
                'active' => (int) DigitalEntitlement::query()
                    ->where('status', DigitalEntitlement::STATUS_DELIVERED)
                    ->whereNull('revoked_at')
                    ->where(function ($q) use ($now) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                    })
                    ->count(),
                'revoked' => (int) DigitalEntitlement::whereNotNull('revoked_at')->count(),
                'expired' => (int) DigitalEntitlement::query()
                    ->whereNull('revoked_at')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $now)
                    ->count(),
            ],
            'downloads' => [
                'total' => (int) DigitalDownloadLog::count(),
                'last_30_days' => (int) DigitalDownloadLog::where('downloaded_at', '>', $now->copy()->subDays(30))->count(),
            ],
            'licenses' => DigitalLicenseKey::select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($c) => (int) $c)
                ->all(),
        ];
    }
}

<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class OrderAnalyticsService
{
    private int $cacheTTL = 300; // 5 minutes

    public function getDashboardOverview(string $period = '24h'): array
    {
        $cacheKey = "analytics:dashboard:overview:{$period}";

        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($period) {
            $dateFrom = $this->getPeriodStart($period);

            return [
                'period' => $period,
                'date_from' => $dateFrom->toIso8601String(),
                'date_to' => now()->toIso8601String(),
                'orders' => $this->getOrderMetrics($dateFrom),
                'revenue' => $this->getRevenueMetrics($dateFrom),
                'performance' => $this->getPerformanceMetrics($dateFrom),
                'customers' => $this->getCustomerMetrics($dateFrom),
                'notifications' => $this->getNotificationMetrics($dateFrom),
            ];
        });
    }

    private function getOrderMetrics(Carbon $dateFrom): array
    {
        $current = DB::table('orders')
            ->where('created_at', '>=', $dateFrom)
            ->selectRaw('
                COUNT(*) as total,
                COUNT(DISTINCT user_id) as unique_customers,
                AVG(total_price) as avg_value,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status IN ("pending", "processing") THEN 1 ELSE 0 END) as in_progress
            ')
            ->first();

        $periodLength = now()->diffInSeconds($dateFrom);
        $previousFrom = $dateFrom->copy()->subSeconds($periodLength);

        $previous = DB::table('orders')
            ->where('created_at', '>=', $previousFrom)
            ->where('created_at', '<', $dateFrom)
            ->selectRaw('COUNT(*) as total')
            ->first();

        $growthRate = $previous && $previous->total > 0
            ? round((($current->total - $previous->total) / $previous->total) * 100, 2)
            : 0;

        return [
            'total' => (int) ($current->total ?? 0),
            'unique_customers' => (int) ($current->unique_customers ?? 0),
            'avg_order_value' => round($current->avg_value ?? 0, 2),
            'delivered' => (int) ($current->delivered ?? 0),
            'cancelled' => (int) ($current->cancelled ?? 0),
            'in_progress' => (int) ($current->in_progress ?? 0),
            'growth_rate_percent' => $growthRate,
            'conversion_rate_percent' => ($current->total ?? 0) > 0
                ? round((($current->delivered ?? 0) / $current->total) * 100, 2)
                : 0,
        ];
    }

    private function getRevenueMetrics(Carbon $dateFrom): array
    {
        $data = DB::table('orders')
            ->where('created_at', '>=', $dateFrom)
            ->where('payment_status', 'payment-success')
            ->selectRaw('
                SUM(total_price) as total_revenue,
                AVG(total_price) as avg_order_value,
                currency_code,
                COUNT(*) as paid_orders
            ')
            ->groupBy('currency_code')
            ->get();

        $byCurrency = [];
        $totalRevenue = 0;
        $totalOrders = 0;

        foreach ($data as $row) {
            $code = $row->currency_code ?? 'EGP';
            $byCurrency[$code] = [
                'revenue' => round($row->total_revenue ?? 0, 2),
                'orders' => (int) ($row->paid_orders ?? 0),
                'avg_value' => round($row->avg_order_value ?? 0, 2),
            ];
            $totalRevenue += $row->total_revenue ?? 0;
            $totalOrders += $row->paid_orders ?? 0;
        }

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_paid_orders' => $totalOrders,
            'avg_order_value' => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0,
            'by_currency' => $byCurrency,
        ];
    }

    private function getPerformanceMetrics(Carbon $dateFrom): array
    {
        // Check if view exists
        $hasView = $this->hasView('order_performance_metrics');

        if (!$hasView) {
            return [
                'avg_time_to_processing_minutes' => 0,
                'avg_time_to_shipped_minutes' => 0,
                'avg_time_to_delivered_minutes' => 0,
                'avg_payment_verification_minutes' => 0,
                'delayed_orders' => 0,
                'sla_compliance_percent' => 0,
            ];
        }

        $data = DB::table('order_performance_metrics')
            ->where('order_created_at', '>=', $dateFrom)
            ->selectRaw('
                AVG(minutes_to_processing) as avg_to_processing,
                AVG(minutes_to_shipped) as avg_to_shipped,
                AVG(minutes_to_delivered) as avg_to_delivered,
                AVG(minutes_to_payment_verified) as avg_to_payment_verified,
                SUM(CASE WHEN is_delayed = 1 THEN 1 ELSE 0 END) as delayed_count,
                SUM(CASE WHEN sla_status = "met" THEN 1 ELSE 0 END) as sla_met,
                COUNT(*) as total
            ')
            ->first();

        // Fallback for SQLite where is_delayed is integer
        $delayed = (int) ($data->delayed_count ?? 0);
        $slaMet = (int) ($data->sla_met ?? 0);
        $total = (int) ($data->total ?? 0);

        return [
            'avg_time_to_processing_minutes' => round($data->avg_to_processing ?? 0, 1),
            'avg_time_to_shipped_minutes' => round($data->avg_to_shipped ?? 0, 1),
            'avg_time_to_delivered_minutes' => round($data->avg_to_delivered ?? 0, 1),
            'avg_payment_verification_minutes' => round($data->avg_to_payment_verified ?? 0, 1),
            'delayed_orders' => $delayed,
            'sla_compliance_percent' => $total > 0 ? round(($slaMet / $total) * 100, 2) : 0,
        ];
    }

    private function getCustomerMetrics(Carbon $dateFrom): array
    {
        $newCustomers = DB::table('users')
            ->where('created_at', '>=', $dateFrom)
            ->count();

        $repeatOrders = DB::table('orders')
            ->where('created_at', '>=', $dateFrom)
            ->whereIn('user_id', function ($query) use ($dateFrom) {
                $query->select('user_id')
                    ->from('orders')
                    ->where('created_at', '<', $dateFrom)
                    ->groupBy('user_id');
            })
            ->count();

        $totalOrders = DB::table('orders')
            ->where('created_at', '>=', $dateFrom)
            ->count();

        return [
            'new_customers' => $newCustomers,
            'repeat_orders' => $repeatOrders,
            'repeat_rate_percent' => $totalOrders > 0 ? round(($repeatOrders / $totalOrders) * 100, 2) : 0,
        ];
    }

    private function getNotificationMetrics(Carbon $dateFrom): array
    {
        if (!$this->hasTable('order_notifications')) {
            return ['by_channel' => []];
        }

        $data = DB::table('order_notifications')
            ->where('created_at', '>=', $dateFrom)
            ->selectRaw('
                channel,
                COUNT(*) as total,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
            ')
            ->groupBy('channel')
            ->get();

        $byChannel = [];
        foreach ($data as $row) {
            $byChannel[$row->channel] = [
                'total' => (int) $row->total,
                'sent' => (int) $row->sent,
                'failed' => (int) $row->failed,
                'success_rate_percent' => $row->total > 0 ? round(($row->sent / $row->total) * 100, 2) : 0,
            ];
        }

        return ['by_channel' => $byChannel];
    }

    public function getTimeSeries(string $metric, string $period = '7d', string $granularity = 'day'): array
    {
        $cacheKey = "analytics:timeseries:{$metric}:{$period}:{$granularity}";

        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($metric, $period, $granularity) {
            $dateFrom = $this->getPeriodStart($period);
            $dateFormat = $this->getDateFormat($granularity);

            return match ($metric) {
                'orders' => $this->getOrdersTimeSeries($dateFrom, $dateFormat),
                'revenue' => $this->getRevenueTimeSeries($dateFrom, $dateFormat),
                'avg_order_value' => $this->getAvgOrderValueTimeSeries($dateFrom, $dateFormat),
                default => [],
            };
        });
    }

    private function getOrdersTimeSeries(Carbon $dateFrom, string $dateFormat): array
    {
        $driver = DB::getDriverName();
        $formatExpr = $driver === 'sqlite' ? $this->sqliteDateFormat($dateFormat) : "DATE_FORMAT(created_at, '{$dateFormat}')";

        return DB::table('orders')
            ->where('created_at', '>=', $dateFrom)
            ->selectRaw("{$formatExpr} as date, COUNT(*) as value")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => ['date' => $row->date, 'value' => (int) $row->value])
            ->toArray();
    }

    private function getRevenueTimeSeries(Carbon $dateFrom, string $dateFormat): array
    {
        $driver = DB::getDriverName();
        $formatExpr = $driver === 'sqlite' ? $this->sqliteDateFormat($dateFormat) : "DATE_FORMAT(created_at, '{$dateFormat}')";

        return DB::table('orders')
            ->where('created_at', '>=', $dateFrom)
            ->where('payment_status', 'payment-success')
            ->selectRaw("{$formatExpr} as date, SUM(total_price) as value")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => ['date' => $row->date, 'value' => round($row->value ?? 0, 2)])
            ->toArray();
    }

    private function getAvgOrderValueTimeSeries(Carbon $dateFrom, string $dateFormat): array
    {
        $driver = DB::getDriverName();
        $formatExpr = $driver === 'sqlite' ? $this->sqliteDateFormat($dateFormat) : "DATE_FORMAT(created_at, '{$dateFormat}')";

        return DB::table('orders')
            ->where('created_at', '>=', $dateFrom)
            ->where('payment_status', 'payment-success')
            ->selectRaw("{$formatExpr} as date, AVG(total_price) as value")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => ['date' => $row->date, 'value' => round($row->value ?? 0, 2)])
            ->toArray();
    }

    private function sqliteDateFormat(string $mysqlFormat): string
    {
        // Map MySQL DATE_FORMAT to SQLite strftime
        return match ($mysqlFormat) {
            '%Y-%m-%d %H:00:00' => "strftime('%Y-%m-%d %H:00:00', created_at)",
            '%Y-%m-%d' => "strftime('%Y-%m-%d', created_at)",
            '%Y-%W' => "strftime('%Y-%W', created_at)",
            '%Y-%m' => "strftime('%Y-%m', created_at)",
            default => "strftime('%Y-%m-%d', created_at)",
        };
    }

    public function getTopCustomers(int $limit = 10): array
    {
        $cacheKey = "analytics:top_customers:{$limit}";

        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($limit) {
            if (!$this->hasView('customer_lifetime_value')) {
                return [];
            }
            return DB::table('customer_lifetime_value')
                ->orderBy('lifetime_value', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        });
    }

    public function getCustomerSegmentation(): array
    {
        $cacheKey = "analytics:customer_segmentation";

        return Cache::remember($cacheKey, $this->cacheTTL, function () {
            if (!$this->hasView('customer_lifetime_value')) {
                return [];
            }
            return DB::table('customer_lifetime_value')
                ->selectRaw('
                    customer_segment,
                    COUNT(*) as customer_count,
                    SUM(lifetime_value) as total_value,
                    AVG(lifetime_value) as avg_value
                ')
                ->groupBy('customer_segment')
                ->get()
                ->keyBy('customer_segment')
                ->toArray();
        });
    }

    private function getPeriodStart(string $period): Carbon
    {
        return match ($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDay(),
        };
    }

    private function getDateFormat(string $granularity): string
    {
        return match ($granularity) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-%W',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };
    }

    public function clearCache(): void
    {
        // For array driver, just forget known keys
        try {
            if (method_exists(Cache::getStore(), 'flush')) {
                // Use tags if supported, otherwise flush is overkill; just forget analytics keys
                Cache::flush();
            }
        } catch (\Throwable $e) {}
        // Also try to clear specific keys
        $keys = ['analytics:dashboard:overview:24h', 'analytics:dashboard:overview:7d', 'analytics:dashboard:overview:30d'];
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    private function hasView(string $view): bool
    {
        try {
            DB::table($view)->limit(1)->get();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

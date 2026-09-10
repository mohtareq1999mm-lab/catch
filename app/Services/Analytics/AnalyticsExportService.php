<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsExportService
{
    public function exportOrders(Carbon $dateFrom, Carbon $dateTo, ?string $status = null): string
    {
        $query = DB::table('orders')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if ($status) {
            $query->where('status', $status);
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        return $this->generateCSV($orders, [
            'id',
            'order_number',
            'user_id',
            'status',
            'payment_status',
            'fulfillment_status',
            'total_price',
            'currency_code',
            'payment_method',
            'created_at',
        ], 'orders');
    }

    public function exportCustomerLTV(): string
    {
        // Check view exists
        try {
            DB::table('customer_lifetime_value')->limit(1)->get();
        } catch (\Throwable $e) {
            // Fallback to direct query if view missing
            $customers = DB::table('users')
                ->leftJoin('orders', 'orders.user_id', '=', 'users.id')
                ->selectRaw('users.id as user_id, users.email, users.name, COUNT(orders.id) as total_orders, COALESCE(SUM(CASE WHEN orders.payment_status = "payment-success" THEN orders.total_price ELSE 0 END),0) as lifetime_value')
                ->groupBy('users.id', 'users.email', 'users.name')
                ->orderBy('lifetime_value', 'desc')
                ->get();

            return $this->generateCSV($customers, [
                'user_id',
                'email',
                'name',
                'total_orders',
                'lifetime_value',
            ], 'customer_ltv');
        }

        $customers = DB::table('customer_lifetime_value')
            ->orderBy('lifetime_value', 'desc')
            ->get();

        return $this->generateCSV($customers, [
            'user_id',
            'email',
            'name',
            'total_orders',
            'completed_orders',
            'lifetime_value',
            'avg_order_value',
            'customer_segment',
            'customer_status',
            'first_order_at',
            'last_order_at',
            'days_since_last_order',
        ], 'customer_ltv');
    }

    public function exportPerformanceMetrics(Carbon $dateFrom, Carbon $dateTo): string
    {
        try {
            DB::table('order_performance_metrics')->limit(1)->get();
            $metrics = DB::table('order_performance_metrics')
                ->whereBetween('order_created_at', [$dateFrom, $dateTo])
                ->get();
        } catch (\Throwable $e) {
            $metrics = collect([]);
        }

        return $this->generateCSV($metrics, [
            'order_id',
            'order_number',
            'status',
            'minutes_to_processing',
            'minutes_to_shipped',
            'minutes_to_delivered',
            'is_delayed',
            'sla_status',
            'order_created_at',
        ], 'performance_metrics');
    }

    private function generateCSV($data, array $columns, string $filename): string
    {
        // Use league/csv if available, otherwise fallback to native
        if (class_exists(\League\Csv\Writer::class)) {
            $csv = \League\Csv\Writer::createFromString();
            $csv->insertOne($columns);
            foreach ($data as $row) {
                $rowData = [];
                foreach ($columns as $col) {
                    $rowData[] = $row->{$col} ?? $row[$col] ?? '';
                }
                $csv->insertOne($rowData);
            }
            $content = $csv->toString();
        } else {
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, $columns);
            foreach ($data as $row) {
                $rowData = [];
                foreach ($columns as $col) {
                    $rowData[] = $row->{$col} ?? $row[$col] ?? '';
                }
                fputcsv($handle, $rowData);
            }
            rewind($handle);
            $content = stream_get_contents($handle);
            fclose($handle);
        }

        $filepath = storage_path("app/exports/{$filename}_" . now()->format('Y-m-d_His') . ".csv");

        if (!is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        file_put_contents($filepath, $content);

        return $filepath;
    }
}

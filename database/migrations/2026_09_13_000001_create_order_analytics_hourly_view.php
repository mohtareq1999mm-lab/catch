<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite-compatible view (without currency grouping complexity, use strftime)
            DB::statement("DROP VIEW IF EXISTS order_analytics_hourly");
            DB::statement("
                CREATE VIEW order_analytics_hourly AS
                SELECT
                    strftime('%Y-%m-%d %H:00:00', created_at) as hour_bucket,
                    COUNT(*) as total_orders,
                    COUNT(DISTINCT user_id) as unique_customers,
                    SUM(total_price) as total_revenue,
                    AVG(total_price) as avg_order_value,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count,
                    SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped_count,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                    SUM(CASE WHEN payment_status = 'payment-success' THEN 1 ELSE 0 END) as paid_count,
                    SUM(CASE WHEN payment_status = 'payment-pending' THEN 1 ELSE 0 END) as payment_pending_count,
                    SUM(CASE WHEN payment_status = 'payment-failed' THEN 1 ELSE 0 END) as payment_failed_count,
                    SUM(CASE WHEN payment_status = 'payment-success' THEN total_price ELSE 0 END) as paid_revenue,
                    SUM(CASE WHEN payment_method = 'online' THEN 1 ELSE 0 END) as online_payment_count,
                    SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END) as cash_payment_count,
                    currency_code,
                    MIN(created_at) as first_order_at,
                    MAX(created_at) as last_order_at
                FROM orders
                WHERE created_at >= datetime('now', '-90 days')
                GROUP BY hour_bucket, currency_code
            ");
        } else {
            DB::statement("DROP VIEW IF EXISTS order_analytics_hourly");
            DB::statement("
                CREATE VIEW order_analytics_hourly AS
                SELECT
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_bucket,
                    COUNT(*) as total_orders,
                    COUNT(DISTINCT user_id) as unique_customers,
                    SUM(total_price) as total_revenue,
                    AVG(total_price) as avg_order_value,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count,
                    SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped_count,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                    SUM(CASE WHEN payment_status = 'payment-success' THEN 1 ELSE 0 END) as paid_count,
                    SUM(CASE WHEN payment_status = 'payment-pending' THEN 1 ELSE 0 END) as payment_pending_count,
                    SUM(CASE WHEN payment_status = 'payment-failed' THEN 1 ELSE 0 END) as payment_failed_count,
                    SUM(CASE WHEN payment_status = 'payment-success' THEN total_price ELSE 0 END) as paid_revenue,
                    SUM(CASE WHEN payment_method = 'online' THEN 1 ELSE 0 END) as online_payment_count,
                    SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END) as cash_payment_count,
                    currency_code,
                    MIN(created_at) as first_order_at,
                    MAX(created_at) as last_order_at
                FROM orders
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY hour_bucket, currency_code
            ");
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS order_analytics_hourly');
    }
};

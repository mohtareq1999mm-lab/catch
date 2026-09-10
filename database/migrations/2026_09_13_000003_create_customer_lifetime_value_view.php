<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("DROP VIEW IF EXISTS customer_lifetime_value");
            DB::statement("
                CREATE VIEW customer_lifetime_value AS
                SELECT
                    u.id as user_id,
                    u.email,
                    u.name,
                    u.created_at as customer_since,
                    COUNT(o.id) as total_orders,
                    COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) as completed_orders,
                    COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) as cancelled_orders,
                    COALESCE(SUM(CASE WHEN o.payment_status = 'payment-success' THEN o.total_price ELSE 0 END), 0) as lifetime_value,
                    COALESCE(AVG(CASE WHEN o.payment_status = 'payment-success' THEN o.total_price END), 0) as avg_order_value,
                    MIN(o.created_at) as first_order_at,
                    MAX(o.created_at) as last_order_at,
                    CAST(julianday(MAX(o.created_at)) - julianday(MIN(o.created_at)) AS INTEGER) as days_active,
                    CAST(julianday('now') - julianday(MAX(o.created_at)) AS INTEGER) as days_since_last_order,
                    CASE
                        WHEN COUNT(o.id) = 0 THEN 'no_orders'
                        WHEN COUNT(o.id) = 1 THEN 'one_time'
                        WHEN COUNT(o.id) BETWEEN 2 AND 5 THEN 'occasional'
                        WHEN COUNT(o.id) > 5 THEN 'frequent'
                    END as customer_segment,
                    CASE
                        WHEN MAX(o.created_at) >= datetime('now', '-30 days') THEN 'active'
                        WHEN MAX(o.created_at) >= datetime('now', '-90 days') THEN 'at_risk'
                        ELSE 'churned'
                    END as customer_status
                FROM users u
                LEFT JOIN orders o ON o.user_id = u.id
                GROUP BY u.id, u.email, u.name, u.created_at
            ");
        } else {
            DB::statement("DROP VIEW IF EXISTS customer_lifetime_value");
            DB::statement("
                CREATE VIEW customer_lifetime_value AS
                SELECT
                    u.id as user_id,
                    u.email,
                    u.name,
                    u.created_at as customer_since,
                    COUNT(o.id) as total_orders,
                    COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) as completed_orders,
                    COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) as cancelled_orders,
                    COALESCE(SUM(CASE WHEN o.payment_status = 'payment-success' THEN o.total_price ELSE 0 END), 0) as lifetime_value,
                    COALESCE(AVG(CASE WHEN o.payment_status = 'payment-success' THEN o.total_price END), 0) as avg_order_value,
                    MIN(o.created_at) as first_order_at,
                    MAX(o.created_at) as last_order_at,
                    DATEDIFF(MAX(o.created_at), MIN(o.created_at)) as days_active,
                    DATEDIFF(NOW(), MAX(o.created_at)) as days_since_last_order,
                    CASE
                        WHEN COUNT(o.id) = 0 THEN 'no_orders'
                        WHEN COUNT(o.id) = 1 THEN 'one_time'
                        WHEN COUNT(o.id) BETWEEN 2 AND 5 THEN 'occasional'
                        WHEN COUNT(o.id) > 5 THEN 'frequent'
                    END as customer_segment,
                    CASE
                        WHEN MAX(o.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'active'
                        WHEN MAX(o.created_at) >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 'at_risk'
                        ELSE 'churned'
                    END as customer_status
                FROM users u
                LEFT JOIN orders o ON o.user_id = u.id
                GROUP BY u.id, u.email, u.name, u.created_at
            ");
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS customer_lifetime_value');
    }
};

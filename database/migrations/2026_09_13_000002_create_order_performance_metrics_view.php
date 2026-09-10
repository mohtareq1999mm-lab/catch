<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // For SQLite, use simpler view without TIMESTAMPDIFF/JSON_EXTRACT
        if ($driver === 'sqlite') {
            DB::statement("DROP VIEW IF EXISTS order_performance_metrics");
            DB::statement("
                CREATE VIEW order_performance_metrics AS
                SELECT
                    o.id as order_id,
                    o.order_number,
                    o.user_id,
                    o.status,
                    o.payment_status,
                    o.fulfillment_status,
                    o.total_price,
                    o.currency_code,
                    o.created_at as order_created_at,
                    NULL as minutes_to_processing,
                    NULL as minutes_to_shipped,
                    NULL as minutes_to_delivered,
                    (SELECT COUNT(*) FROM order_status_history osh WHERE osh.order_id = o.id) as total_status_changes,
                    NULL as minutes_to_payment_verified,
                    CASE WHEN o.status NOT IN ('delivered', 'cancelled') AND (julianday('now') - julianday(o.created_at)) * 24 > 24 THEN 1 ELSE 0 END as is_delayed,
                    CASE
                        WHEN o.status = 'delivered' THEN 'met'
                        WHEN (julianday('now') - julianday(o.created_at)) * 24 > 72 THEN 'at_risk'
                        ELSE 'on_track'
                    END as sla_status
                FROM orders o
                WHERE o.created_at >= datetime('now', '-90 days')
            ");
        } else {
            DB::statement("DROP VIEW IF EXISTS order_performance_metrics");
            DB::statement("
                CREATE VIEW order_performance_metrics AS
                SELECT
                    o.id as order_id,
                    o.order_number,
                    o.user_id,
                    o.status,
                    o.payment_status,
                    o.fulfillment_status,
                    o.total_price,
                    o.currency_code,
                    o.created_at as order_created_at,
                    (
                        SELECT TIMESTAMPDIFF(MINUTE, o.created_at, osh.changed_at)
                        FROM order_status_history osh
                        WHERE osh.order_id = o.id
                          AND osh.new_status = 'processing'
                        ORDER BY osh.changed_at ASC
                        LIMIT 1
                    ) as minutes_to_processing,
                    (
                        SELECT TIMESTAMPDIFF(MINUTE, o.created_at, osh.changed_at)
                        FROM order_status_history osh
                        WHERE osh.order_id = o.id
                          AND osh.new_status = 'shipped'
                        ORDER BY osh.changed_at ASC
                        LIMIT 1
                    ) as minutes_to_shipped,
                    (
                        SELECT TIMESTAMPDIFF(MINUTE, o.created_at, osh.changed_at)
                        FROM order_status_history osh
                        WHERE osh.order_id = o.id
                          AND osh.new_status = 'delivered'
                        ORDER BY osh.changed_at ASC
                        LIMIT 1
                    ) as minutes_to_delivered,
                    (
                        SELECT COUNT(*)
                        FROM order_status_history osh
                        WHERE osh.order_id = o.id
                    ) as total_status_changes,
                    CASE
                        WHEN o.payment_status = 'payment-success' THEN
                            (
                                SELECT TIMESTAMPDIFF(MINUTE, o.created_at, osh.changed_at)
                                FROM order_status_history osh
                                WHERE osh.order_id = o.id
                                  AND osh.new_status = 'processing'
                                ORDER BY osh.changed_at ASC
                                LIMIT 1
                            )
                        ELSE NULL
                    END as minutes_to_payment_verified,
                    CASE
                        WHEN o.status NOT IN ('delivered', 'cancelled')
                          AND TIMESTAMPDIFF(HOUR, o.created_at, NOW()) > 24
                        THEN TRUE
                        ELSE FALSE
                    END as is_delayed,
                    CASE
                        WHEN o.status = 'delivered'
                          AND TIMESTAMPDIFF(HOUR, o.created_at, (
                              SELECT osh.changed_at
                              FROM order_status_history osh
                              WHERE osh.order_id = o.id AND osh.new_status = 'delivered'
                              ORDER BY osh.changed_at ASC LIMIT 1
                          )) <= 72
                        THEN 'met'
                        WHEN o.status = 'delivered'
                        THEN 'missed'
                        WHEN TIMESTAMPDIFF(HOUR, o.created_at, NOW()) > 72
                        THEN 'at_risk'
                        ELSE 'on_track'
                    END as sla_status
                FROM orders o
                WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ");
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS order_performance_metrics');
    }
};

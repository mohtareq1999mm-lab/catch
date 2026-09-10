<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("DROP VIEW IF EXISTS notification_performance_hourly");
            DB::statement("
                CREATE VIEW notification_performance_hourly AS
                SELECT
                    strftime('%Y-%m-%d %H:00:00', created_at) as hour_bucket,
                    channel,
                    event_type,
                    COUNT(*) as total_notifications,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped_count,
                    ROUND(
                        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) * 100.0 / COUNT(*),
                        2
                    ) as delivery_rate_percent,
                    NULL as avg_delivery_time_seconds,
                    MIN(created_at) as first_notification_at,
                    MAX(created_at) as last_notification_at
                FROM order_notifications
                WHERE created_at >= datetime('now', '-30 days')
                GROUP BY hour_bucket, channel, event_type
            ");
        } else {
            DB::statement("DROP VIEW IF EXISTS notification_performance_hourly");
            DB::statement("
                CREATE VIEW notification_performance_hourly AS
                SELECT
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_bucket,
                    channel,
                    event_type,
                    COUNT(*) as total_notifications,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped_count,
                    ROUND(
                        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) * 100.0 / COUNT(*),
                        2
                    ) as delivery_rate_percent,
                    AVG(
                        CASE WHEN status = 'delivered' AND delivered_at IS NOT NULL
                        THEN TIMESTAMPDIFF(SECOND, sent_at, delivered_at)
                        END
                    ) as avg_delivery_time_seconds,
                    MIN(created_at) as first_notification_at,
                    MAX(created_at) as last_notification_at
                FROM order_notifications
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY hour_bucket, channel, event_type
            ");
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS notification_performance_hourly');
    }
};

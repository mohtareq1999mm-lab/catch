<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\CancelUnpaidOrders::class,
        \App\Console\Commands\ExpireCouponReservations::class,
        \App\Console\Commands\ExpireCouponClaims::class,
        \App\Console\Commands\MigrateInventoryReservations::class,
        \App\Console\Commands\NotifyAbandonedCarts::class,
        \App\Console\Commands\NotifyPromotionsEndingSoon::class,
        \App\Console\Commands\NotifyFlashSalesEndingSoon::class,
        \App\Console\Commands\SyncCurrencyRates::class,
        \App\Console\Commands\SweepExpiredPickingClaims::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('orders:cancel-unpaid')->everyFiveMinutes()->withoutOverlapping();
        // Phase 15: picking-claim leases (default 15 min) recycle on the same
        // cadence so disconnected workers free tasks promptly.
        $schedule->command('picking:sweep-expired-claims')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('coupons:expire-reservations')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('coupons:expire-claims')->hourly()->withoutOverlapping();
        // F-06: read-only coupon reconciliation detectors (INV-01–INV-07).
        // Report-only, never repairs; exit 1 on issues for monitoring/alerting.
        // withoutOverlapping + onOneServer prevents duplicate executions.
        $schedule->command('coupons:reconcile')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('cart:notify-abandoned')->hourly()->withoutOverlapping();
        $schedule->command('promotions:notify-ending-soon')->daily()->withoutOverlapping();
        $schedule->command('flash-sales:notify-ending-soon')->daily()->withoutOverlapping();
        // Permanently remove products soft-deleted more than 30 days ago.
        $schedule->command('products:purge-old-deleted --days=30')->dailyAt('02:30')->withoutOverlapping();

        // Payment gateway reconciliation: dispatches the existing
        // PaymentReconciliationJob (medium queue via config queue.queues.medium, tries=1). withoutOverlapping
        // prevents concurrent reconciliation runs.
        // P2-3: Increased from hourly to every 15 minutes to reduce
        // pending → paid window from 60m to 15m for missed callbacks.
        $schedule->command('payments:reconcile')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Phase 0: prune permanently-failed jobs older than 30 days. The
        // failed_jobs table remains the failure record during that window;
        // HandleFailedQueueJob alerts on every final failure at occurrence time.
        $schedule->command('queue:prune-failed --hours=720')->dailyAt('03:15')->withoutOverlapping();
        $schedule->command('imports:prune --days=14')->dailyAt('03:30')->withoutOverlapping();

        // Activity log retention: keep the latest 90 days. Runs quarterly.
        // Retention (90 days) is independent of the quarterly schedule frequency.
        $schedule->command('activitylog:prune --days=90')
            ->cron('0 3 1 */3 *')
            ->timezone('UTC')
            ->withoutOverlapping();

        $schedule->command('currency:sync-rates')
            ->everySixHours()
            ->timezone('UTC')
            ->withoutOverlapping(60)
            ->onOneServer()
            ->when(fn () => (bool) config('currency.enabled', false));

        $schedule->call(function () {
            \App\Services\Metrics\OrderTrackingMetrics::reset();
        })->daily()->name('reset-order-tracking-metrics');

        // Coupon distribution event backbone (RabbitMQ transport, MySQL truth).
        // The immediate after-commit publish job carries fresh events; the
        // sweep covers crashes between commit and that job. Activation scan
        // is deduplicated (repeat ticks converge to one run per tree).
        $schedule->command('coupons:publish-outbox --batch=100')->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->command('coupons:detect-activations')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('coupons:detect-expiry')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('coupons:detect-public')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('coupons:prune-events --days=90')->monthly()->withoutOverlapping()->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

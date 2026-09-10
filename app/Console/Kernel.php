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
        \App\Console\Commands\MigrateInventoryReservations::class,
        \App\Console\Commands\NotifyAbandonedCarts::class,
        \App\Console\Commands\NotifyPromotionsEndingSoon::class,
        \App\Console\Commands\NotifyFlashSalesEndingSoon::class,
        \App\Console\Commands\SyncCurrencyRates::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('orders:cancel-unpaid')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('coupons:expire-reservations')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('cart:notify-abandoned')->hourly()->withoutOverlapping();
        $schedule->command('promotions:notify-ending-soon')->daily()->withoutOverlapping();
        $schedule->command('flash-sales:notify-ending-soon')->daily()->withoutOverlapping();
        // Permanently remove products soft-deleted more than 30 days ago.
        $schedule->command('products:purge-old-deleted --days=30')->dailyAt('02:30')->withoutOverlapping();

        // Payment gateway reconciliation: dispatches the existing
        // PaymentReconciliationJob (meem-medium, tries=1). withoutOverlapping
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
        $schedule->command('currency:sync-rates')
            ->everySixHours()
            ->timezone('UTC')
            ->withoutOverlapping(60)
            ->onOneServer()
            ->when(fn () => (bool) config('currency.enabled', false));

        $schedule->call(function () {
            \App\Services\Metrics\OrderTrackingMetrics::reset();
        })->daily()->name('reset-order-tracking-metrics');
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

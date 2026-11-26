<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Shipping\ShipmentNotificationDispatcher;
use App\Models\Shipping\ShipmentNotification;
use App\Services\Shipment\ShipmentNotificationRetryService;
use Illuminate\Console\Command;

/**
 * #183 recovery worker: process due shipment-notification retries.
 *
 * Runs every five minutes from Kernel::schedule(); withoutOverlapping and
 * onOneServer prevent concurrent sweeps. The retry service owns claims,
 * backoff, attempt bounds and history; this command only supplies the channel
 * adapter seam and reports the batch size.
 */
class RetryShipmentNotifications extends Command
{
    protected $signature = 'shipping:retry-notifications {--limit=100 : Maximum due rows per sweep}';

    protected $description = 'Process due shipment-notification retries with bounded attempts and backoff (#183).';

    public function __construct(
        private readonly ShipmentNotificationRetryService $retries,
        private readonly ShipmentNotificationDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $processed = $this->retries->processDue(
            fn (ShipmentNotification $notification) => ($this->dispatcher)($notification),
            (int) $this->option('limit')
        );

        $this->info("Processed {$processed} shipment notification retry(ies).");

        return self::SUCCESS;
    }
}

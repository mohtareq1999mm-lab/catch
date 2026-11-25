<?php

declare(strict_types=1);

namespace App\Console\Commands\Shipping;

use App\Services\Shipment\Outbox\ShippingOutboxService;
use Illuminate\Console\Command;

class PublishShippingOutboxCommand extends Command
{
    protected $signature = 'shipping:publish-outbox {--batch=100 : Max due rows to attempt}';

    protected $description = 'Recover and process due shipping outbox rows (lost queue message / unknown reconciliation safety net).';

    public function handle(ShippingOutboxService $outbox): int
    {
        $processed = $outbox->processDue(max(1, (int) $this->option('batch')));
        $this->info("Processed {$processed} shipping outbox row(s).");

        return self::SUCCESS;
    }
}

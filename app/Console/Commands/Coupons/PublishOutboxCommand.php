<?php

namespace App\Console\Commands\Coupons;

use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Console\Command;

class PublishOutboxCommand extends Command
{
    protected $signature = 'coupons:publish-outbox {--batch=100 : Max pending rows to attempt}';

    protected $description = 'Sweep due coupon outbox rows to RabbitMQ (safety net behind the immediate after-commit job).';

    public function handle(CouponOutboxService $outbox): int
    {
        $published = $outbox->publishDue((int) $this->option('batch'));

        $this->info("Published {$published} outbox event(s).");

        return self::SUCCESS;
    }
}

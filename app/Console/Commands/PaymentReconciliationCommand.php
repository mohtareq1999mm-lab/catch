<?php

namespace App\Console\Commands;

use App\Jobs\PaymentReconciliationJob;
use Illuminate\Console\Command;

class PaymentReconciliationCommand extends Command
{
    protected $signature = 'payments:reconcile';
    protected $description = 'Dispatch the payment reconciliation job';

    public function handle(): int
    {
        $this->info('Dispatching PaymentReconciliationJob...');

        PaymentReconciliationJob::dispatch();

        $this->info('PaymentReconciliationJob dispatched successfully.');

        return self::SUCCESS;
    }
}

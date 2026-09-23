<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Services\Invoice\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class GenerateInvoiceListener implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    public $afterCommit = true;
    public $tries = 5;

    public $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        private InvoiceService $invoiceService,
    ) {}

    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order;

        try {
            $this->invoiceService->generateFromOrder($order);
        } catch (\App\Exceptions\CurrencyMismatchException $e) {
            // Deterministic validation failure (e.g. currency allowlist):
            // retrying cannot succeed. Log + report, do NOT rethrow (would
            // poison the queue ×tries and 500 sync callers after commit).
            Log::error('Skipping invoice for order ' . ($order?->id ?? 'unknown') . ': ' . $e->getMessage());
            report($e);
        } catch (\Throwable $e) {
            Log::error('Failed to generate invoice for order ' . ($order?->id ?? 'unknown') . ': ' . $e->getMessage());
            report($e);
            throw $e;
        }
    }
}

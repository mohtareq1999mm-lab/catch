<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Services\Invoice\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class GenerateInvoiceListener implements ShouldQueue
{
    public $afterCommit = true;

    public $queue = 'meem-high';

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
        } catch (\Throwable $e) {
            Log::error('Failed to generate invoice for order ' . ($order?->id ?? 'unknown') . ': ' . $e->getMessage());
            report($e);
            throw $e;
        }
    }
}

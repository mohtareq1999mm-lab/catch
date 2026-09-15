<?php

namespace App\Listeners;

use App\Audit\ActivityAuditService;
use App\Events\InvoiceCreated;
use Illuminate\Support\Facades\Log;

class LogInvoiceCreated
{
    public function handle(InvoiceCreated $event): void
    {
        $invoice = $event->invoice;

        ActivityAuditService::recordSubject(
            \App\Models\Invoice::class,
            (int) $invoice->id,
            'invoice_created',
            'invoices',
            __('activity.invoice_created', ['number' => $invoice->invoice_number ?? $invoice->id]) ?: 'Invoice created',
            new: [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number ?? null,
                'order_id' => $invoice->order_id ?? null,
                'user_id' => $invoice->user_id ?? null,
                'total' => $invoice->total ?? null,
                'currency' => $invoice->currency ?? null,
            ],
            context: ['source' => 'queue', 'job' => self::class],
        );

        Log::info('Invoice created', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'order_id' => $invoice->order_id,
            'user_id' => $invoice->user_id,
        ]);
    }
}

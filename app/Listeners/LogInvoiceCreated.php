<?php

namespace App\Listeners;

use App\Events\InvoiceCreated;
use Illuminate\Support\Facades\Log;

class LogInvoiceCreated
{
    public function handle(InvoiceCreated $event): void
    {
        $invoice = $event->invoice;

        Log::info('Invoice created', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'order_id' => $invoice->order_id,
            'user_id' => $invoice->user_id,
            'total' => $invoice->total,
            'currency' => $invoice->currency,
        ]);
    }
}

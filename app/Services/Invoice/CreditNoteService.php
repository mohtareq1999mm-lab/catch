<?php

namespace App\Services\Invoice;

use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function __construct(
        private InvoiceNumberService $numberService,
    ) {}

    public function generateForRefund(
        Invoice $invoice,
        float $amount,
        string $reason,
        ?int $refundTransactionId = null,
        ?int $createdBy = null,
    ): CreditNote {
        return DB::transaction(function () use ($invoice, $amount, $reason, $refundTransactionId, $createdBy) {
            $numberData = $this->numberService->generateNext('CN');

            return CreditNote::create([
                'invoice_id' => $invoice->id,
                'credit_note_number' => $numberData['number'],
                'credit_note_series' => $numberData['series'],
                'sequence_number' => $numberData['sequence'],
                'sequence_year' => $numberData['year'],
                'reason' => $reason,
                'type' => 'refund',
                'amount' => $amount,
                'currency' => $invoice->currency,
                'refund_transaction_id' => $refundTransactionId,
                'created_by' => $createdBy,
                'line_items' => $invoice->data['items'] ?? [],
                'notes' => "Credit note for {$invoice->invoice_number}",
                'issued_at' => now(),
            ]);
        });
    }

    public function generateForCancellation(
        Invoice $invoice,
        float $amount,
        string $reason,
        ?int $createdBy = null,
    ): CreditNote {
        return DB::transaction(function () use ($invoice, $amount, $reason, $createdBy) {
            $numberData = $this->numberService->generateNext('CN');

            return CreditNote::create([
                'invoice_id' => $invoice->id,
                'credit_note_number' => $numberData['number'],
                'credit_note_series' => $numberData['series'],
                'sequence_number' => $numberData['sequence'],
                'sequence_year' => $numberData['year'],
                'reason' => $reason,
                'type' => 'cancellation',
                'amount' => $amount,
                'currency' => $invoice->currency,
                'created_by' => $createdBy,
                'line_items' => $invoice->data['items'] ?? [],
                'notes' => "Cancellation credit note for {$invoice->invoice_number}",
                'issued_at' => now(),
            ]);
        });
    }
}

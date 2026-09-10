<?php

namespace App\Services\Invoice;

use App\Models\DebitNote;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class DebitNoteService
{
    public function __construct(
        private InvoiceNumberService $numberService,
    ) {}

    public function generate(
        Invoice $invoice,
        float $amount,
        string $reason,
        ?int $createdBy = null,
    ): DebitNote {
        return DB::transaction(function () use ($invoice, $amount, $reason, $createdBy) {
            $numberData = $this->numberService->generateNext('DN');

            return DebitNote::create([
                'invoice_id' => $invoice->id,
                'debit_note_number' => $numberData['number'],
                'debit_note_series' => $numberData['series'],
                'sequence_number' => $numberData['sequence'],
                'sequence_year' => $numberData['year'],
                'reason' => $reason,
                'type' => 'correction',
                'amount' => $amount,
                'currency' => $invoice->currency,
                'created_by' => $createdBy,
                'line_items' => $invoice->data['items'] ?? [],
                'notes' => "Debit note for {$invoice->invoice_number}",
                'issued_at' => now(),
            ]);
        });
    }
}

<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\InvoiceTimeline;

class InvoiceTimelineService
{
    public function record(
        Invoice $invoice,
        string $event,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null,
    ): InvoiceTimeline {
        $request = request();

        return InvoiceTimeline::create([
            'invoice_id' => $invoice->id,
            'event' => $event,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'actor_type' => $request?->user()?->getMorphClass(),
            'actor_id' => $request?->user()?->id,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
        ]);
    }

    public function recordGenerated(Invoice $invoice): void
    {
        $this->record($invoice, 'generated', null, 'generated', [
            'invoice_number' => $invoice->invoice_number,
            'total' => $invoice->total,
            'currency' => $invoice->currency,
        ]);
    }

    public function recordVerified(Invoice $invoice): void
    {
        $this->record($invoice, 'verified', $invoice->status, 'verified');
    }

    public function recordDownloaded(Invoice $invoice): void
    {
        $this->record($invoice, 'downloaded', $invoice->status, 'downloaded');
    }

    public function recordPrinted(Invoice $invoice): void
    {
        $this->record($invoice, 'printed', $invoice->status, 'printed');
    }

    public function recordPdfRegenerated(Invoice $invoice): void
    {
        $this->record($invoice, 'pdf_regenerated', null, null);
    }

    public function recordCorrected(Invoice $invoice, string $reason): void
    {
        $this->record($invoice, 'corrected', $invoice->status, 'corrected', [
            'reason' => $reason,
        ]);
    }

    public function recordCancelled(Invoice $invoice, string $reason): void
    {
        $this->record($invoice, 'cancelled', $invoice->status, 'cancelled', [
            'reason' => $reason,
        ]);
    }

    public function recordArchived(Invoice $invoice): void
    {
        $this->record($invoice, 'archived', $invoice->status, 'archived');
    }
}

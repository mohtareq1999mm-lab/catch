<?php

namespace App\Listeners;

use App\Events\RefundApproved;
use App\Models\Invoice;
use App\Services\Invoice\CreditNoteService;
use App\Services\Invoice\InvoiceTimelineService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class GenerateCreditNoteOnRefund implements ShouldQueue
{
    public $afterCommit = true;

    public $queue = 'meem-medium';

    public function __construct(
        private CreditNoteService $creditNoteService,
        private InvoiceTimelineService $timelineService,
    ) {}

    public function handle(RefundApproved $event): void
    {
        try {
            $refund = $event->refund;
            $order = $refund->order;

            if (!$order) {
                return;
            }

            $invoice = Invoice::where('order_id', $order->id)
                ->whereIn('status', ['generated', 'ready', 'verified', 'downloaded', 'printed'])
                ->latest()
                ->first();

            if (!$invoice) {
                Log::warning('No active invoice found for refund credit note', [
                    'order_id' => $order->id,
                    'refund_id' => $refund->id,
                ]);
                return;
            }

            $this->creditNoteService->generateForRefund(
                $invoice,
                (float) ($refund->amount ?? $order->total_price ?? 0),
                'Refund approved: ' . ($refund->title ?? 'No reason provided'),
                null,
            );

            $invoice->update([
                'status' => 'corrected',
                'corrected_at' => now(),
                'correction_reason' => 'Refund approved for order #' . $order->id,
            ]);

            $this->timelineService->recordCorrected($invoice, 'Refund approved');
        } catch (Exception $e) {
            Log::error('Failed to generate credit note on refund', [
                'refund_id' => $event->refund?->id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

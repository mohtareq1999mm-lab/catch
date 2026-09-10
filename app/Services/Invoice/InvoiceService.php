<?php

namespace App\Services\Invoice;

use App\Events\InvoiceCreated;
use App\Models\Invoice;
use App\Jobs\GenerateInvoicePdfJob;
use App\Services\Invoice\InvoiceTimelineService;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;

class InvoiceService
{
    public function __construct(
        private InvoiceSnapshotService $snapshotService,
        private InvoiceSnapshotValidator $snapshotValidator,
        private SnapshotIntegrityService $integrityService,
        private InvoiceNumberService $numberService,
        private InvoiceTimelineService $timelineService,
    ) {}

    public function generateFromOrder(Order $order): ?Invoice
    {
        return DB::transaction(function () use ($order) {
            $existing = Invoice::where('order_id', $order->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $snapshot = $this->snapshotService->buildFullSnapshot($order);

            $this->snapshotValidator->validate($snapshot);

            $snapshotHash = $this->integrityService->computeHash($snapshot);

            $numberData = $this->numberService->generateNext();

            $paidTransaction = $order->transactions()
                ->where('status', 'paid')
                ->latest()
                ->first();

            $subtotal = (float) ($order->price ?? 0);
            $promotionDiscount = (float) ($order->promotion_discount ?? 0);
            $couponDiscount = (float) ($order->coupon_discount ?? 0);
            $shippingPrice = (float) ($order->shipping_price ?? 0);
            $totalDiscount = $promotionDiscount + $couponDiscount;
            $total = (float) ($order->total_price ?? 0);

            $invoice = Invoice::create([
                'order_id' => $order->id,
                'transaction_id' => $paidTransaction?->id,
                'user_id' => $order->user_id,
                'invoice_number' => $numberData['number'],
                'invoice_series' => $numberData['series'],
                'sequence_number' => $numberData['sequence'],
                'sequence_year' => $numberData['year'],
                'subtotal' => $subtotal,
                'shipping_price' => $shippingPrice,
                'coupon_discount' => $couponDiscount,
                'promotion_discount' => $promotionDiscount,
                'total_discount' => $totalDiscount,
                'total' => $total,
                'amount_paid' => $total,
                'currency' => $paidTransaction?->currency ?? $order->currency_code ?? $order->base_currency_code ?? 'EGP',
                'payment_method' => $order->payment_method,
                'payment_gateway' => $order->payment_gateway,
                'status' => 'generated',
                'data' => $snapshot,
                'snapshot_hash' => $snapshotHash,
                'verification_hash' => $this->computeVerificationHash($snapshotHash),
                'generated_at' => now(),
                'generated_by' => 'system',
            ]);

            $this->timelineService->recordGenerated($invoice);

            DB::afterCommit(function () use ($invoice) {
                InvoiceCreated::dispatch($invoice);

                try {
                    GenerateInvoicePdfJob::dispatch($invoice);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return $invoice;
        });
    }

    public function verifyInvoice(string $uuid): ?array
    {
        $invoice = Invoice::where('uuid', $uuid)
            ->with(['order', 'user'])
            ->first();

        if (!$invoice) {
            return null;
        }

        $expectedHash = $this->computeVerificationHash($invoice->snapshot_hash);
        $authentic = hash_equals($expectedHash, $invoice->verification_hash ?? '');

        return [
            'authentic' => $authentic,
            'invoice' => $authentic ? $invoice : null,
            'tampered' => !$authentic,
        ];
    }

    public function correctInvoice(int $originalId, array $overrides, string $reason, ?int $adminId = null): Invoice
    {
        return DB::transaction(function () use ($originalId, $overrides, $reason, $adminId) {
            $original = Invoice::lockForUpdate()->findOrFail($originalId);

            if (!in_array($original->status, ['generated', 'ready', 'verified', 'downloaded', 'printed'], true)) {
                throw new \RuntimeException("Invoice {$original->id} cannot be corrected from status '{$original->status}'");
            }

            $numberData = $this->numberService->generateNext();

            $snapshot = $original->data;
            $snapshot['audit']['correction_reason'] = $reason;
            $snapshot['audit']['generated_by'] = 'admin:' . ($adminId ?? 'system');

            foreach ($overrides as $key => $value) {
                data_set($snapshot, $key, $value);
            }

            $snapshotHash = $this->integrityService->computeHash($snapshot);

            $correction = Invoice::create([
                'order_id' => $original->order_id,
                'transaction_id' => $original->transaction_id,
                'user_id' => $original->user_id,
                'correction_to_id' => $original->id,
                'invoice_number' => $numberData['number'],
                'invoice_series' => $numberData['series'],
                'sequence_number' => $numberData['sequence'],
                'sequence_year' => $numberData['year'],
                'subtotal' => $overrides['total'] ?? $original->subtotal,
                'shipping_price' => $overrides['shipping_price'] ?? $original->shipping_price,
                'coupon_discount' => $original->coupon_discount,
                'promotion_discount' => $original->promotion_discount,
                'total_discount' => $original->total_discount,
                'total' => $overrides['total'] ?? $original->total,
                'amount_paid' => $overrides['amount_paid'] ?? $original->amount_paid,
                'currency' => $original->currency,
                'payment_method' => $original->payment_method,
                'payment_gateway' => $original->payment_gateway,
                'status' => 'generated',
                'data' => $snapshot,
                'snapshot_hash' => $snapshotHash,
                'verification_hash' => $this->computeVerificationHash($snapshotHash),
                'is_correction' => true,
                'correction_reason' => $reason,
                'corrected_at' => now(),
                'generated_at' => now(),
                'generated_by' => 'admin:' . ($adminId ?? 'system'),
            ]);

            $original->update([
                'status' => 'corrected',
                'corrected_at' => now(),
                'correction_reason' => $reason,
            ]);

            $this->timelineService->recordCorrected($original, $reason);
            $this->timelineService->recordGenerated($correction);

            DB::afterCommit(function () use ($correction) {
                InvoiceCreated::dispatch($correction);

                try {
                    GenerateInvoicePdfJob::dispatch($correction);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return $correction;
        });
    }

    public function cancelInvoice(int $id, string $reason, ?int $adminId = null): Invoice
    {
        return DB::transaction(function () use ($id, $reason, $adminId) {
            $invoice = Invoice::lockForUpdate()->findOrFail($id);

            $allowed = ['generated', 'ready', 'failed', 'corrected', 'verified', 'downloaded', 'printed'];
            if (!in_array($invoice->status, $allowed, true)) {
                throw new \RuntimeException("Invoice {$invoice->id} cannot be cancelled from status '{$invoice->status}'");
            }

            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->timelineService->recordCancelled($invoice, $reason);

            return $invoice->fresh();
        });
    }

    private function computeVerificationHash(string $snapshotHash): string
    {
        $secret = config('app.key', 'default-secret');
        return hash('sha256', $snapshotHash . $secret);
    }
}

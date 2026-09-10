<?php

namespace App\Http\Resources\Invoice;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'order_id' => $this->order_id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'subtotal' => $this->roundMoney($this->subtotal),
            'shipping_price' => $this->roundMoney($this->shipping_price),
            'coupon_discount' => $this->roundMoney($this->coupon_discount),
            'promotion_discount' => $this->roundMoney($this->promotion_discount),
            'total_discount' => $this->roundMoney($this->total_discount),
            'total' => $this->roundMoney($this->total),
            'amount_paid' => $this->roundMoney($this->amount_paid),
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'payment_gateway' => $this->payment_gateway,
            'snapshot_hash' => $this->snapshot_hash,
            'verification_hash' => $this->verification_hash,
            'pdf_generated_at' => $this->pdf_generated_at?->toIso8601String(),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'generation_attempts' => $this->generation_attempts ?? 0,
            'last_generation_error' => $this->last_generation_error,
            'is_correction' => (bool) $this->is_correction,
            'correction_reason' => $this->correction_reason,
            'corrected_at' => $this->corrected_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'downloaded_at' => $this->downloaded_at?->toIso8601String(),
            'printed_at' => $this->printed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'verify_count' => $this->verify_count ?? 0,
            'created_at' => $this->created_at?->toIso8601String(),
            'verification_url' => $this->when($this->uuid, fn () =>
                url('/api/v1/general/invoices/verify/' . $this->uuid)
            ),
            'qr_content' => $this->when($this->uuid, fn () => [
                'uuid' => $this->uuid,
                'invoice_number' => $this->invoice_number,
                'verification_hash' => $this->verification_hash,
                'issued_at' => $this->generated_at?->toIso8601String(),
                'verification_url' => url('/api/v1/general/invoices/verify/' . $this->uuid),
            ]),
            'view_url' => $this->when($this->id, fn () =>
                url('/api/v1/invoices/' . $this->id)
            ),
            'download_url' => $this->when($this->uuid && $this->pdf_path, fn () =>
                url('/api/v1/invoices/' . $this->uuid . '/download')
            ),
            'snapshot' => $this->when($this->data, fn () =>
                InvoiceSnapshotResource::make($this->resource)
            ),
            'timeline' => $this->when($this->relationLoaded('timeline'), fn () =>
                $this->timeline->take(10)->map(fn ($t) => [
                    'event' => $t->event,
                    'old_status' => $t->old_status,
                    'new_status' => $t->new_status,
                    'created_at' => $t->created_at?->toIso8601String(),
                ])
            ),
            'credit_notes_summary' => $this->when($this->relationLoaded('creditNotes'), fn () => [
                'count' => $this->creditNotes->count(),
                'total_amount' => $this->roundMoney($this->creditNotes->sum('amount')),
            ]),
            'debit_notes_summary' => $this->when($this->relationLoaded('debitNotes'), fn () => [
                'count' => $this->debitNotes->count(),
                'total_amount' => $this->roundMoney($this->debitNotes->sum('amount')),
            ]),
        ];
    }

    private function roundMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }
}

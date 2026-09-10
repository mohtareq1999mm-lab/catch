<?php

namespace App\Http\Resources\Invoice;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Lightweight invoice summary for LIST endpoints (my-invoices).
 *
 * Deliberately excludes the immutable snapshot — it stays available on the
 * detail endpoints via CustomerInvoiceResource / InvoiceSnapshotResource.
 * view_url / download_url are temporary SIGNED urls (10 min) so the frontend
 * can open them without a Sanctum header.
 */
class CustomerInvoiceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'subtotal' => $this->roundMoney($this->subtotal),
            'shipping_price' => $this->roundMoney($this->shipping_price),
            'total_discount' => $this->roundMoney($this->total_discount),
            'total' => $this->roundMoney($this->total),
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'payment_gateway' => $this->payment_gateway,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'pdf_generated_at' => $this->pdf_generated_at?->toIso8601String(),
            'verification_url' => $this->when($this->uuid, fn () =>
                url('/api/v1/general/invoices/verify/' . $this->uuid)
            ),
            'view_url' => $this->when($this->uuid, fn () =>
                URL::temporarySignedRoute('general.invoices.view', now()->addMinutes(10), ['uuid' => $this->uuid])
            ),
            'download_url' => $this->when($this->uuid, fn () =>
                URL::temporarySignedRoute('general.invoices.download', now()->addMinutes(10), ['uuid' => $this->uuid])
            ),
        ];
    }

    private function roundMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }
}

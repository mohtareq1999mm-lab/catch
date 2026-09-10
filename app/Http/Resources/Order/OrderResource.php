<?php

namespace App\Http\Resources\Order;

use App\Services\Currency\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Marvel\Http\Resources\Order\OrderTransactionResource;
use Marvel\Http\Resources\ShopResource;
use App\Models\Invoice;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'subtotal' => $this->roundMoney($this->price),
            'discount' => $this->roundMoney(((float) ($this->coupon_discount ?? 0)) + ((float) ($this->promotion_discount ?? 0))),
            'coupon' => $this->coupon,
            'coupon_discount' => $this->roundMoney($this->coupon_discount),
            'coupon_discount_type' => $this->coupon_discount_type,
            'promotion_discount' => $this->roundMoney($this->promotion_discount),
            'total' => $this->roundMoney($this->total_price),
            'converted_total' => $this->roundMoney($this->converted_total_price),
            'currency' => $this->currency_code ?? $this->fallbackBaseCode(),
            'base_currency' => $this->base_currency_code ?? $this->fallbackBaseCode(),
            'catalog_currency' => $this->catalog_currency_code ?? $this->base_currency_code ?? $this->fallbackBaseCode(),
            'exchange_rate' => $this->currency_rate,
            'promotion' => $this->promotion_id ? [
                'id' => $this->promotion_id,
                'type' => $this->promotion_type,
                'code' => $this->promotion_code,
            ] : null,
            'tax' => [
                'product_taxable_amount' => $this->roundMoney($this->product_taxable_amount ?? 0),
                'product_tax_amount' => $this->roundMoney($this->product_tax_amount ?? 0),
                'order_tax_rate' => $this->order_tax_rate !== null ? (float) $this->order_tax_rate : null,
                'order_taxable_amount' => $this->roundMoney($this->order_taxable_amount ?? 0),
                'order_tax_amount' => $this->roundMoney($this->order_tax_amount ?? 0),
            ],
            'fulfillment_type' => $this->fulfillment_type,
            'payment_method' => $this->payment_method,
            'shipping_price' => $this->roundMoney($this->shipping_price),
            'fast_shipping_fee' => $this->roundMoney($this->fast_shipping_fee),
            'pickup_location' => $this->when($this->fulfillment_type === 'pickup', fn() => $this->resolvePickupLocation()),
            'invoice_summary' => $this->when($this->relationLoaded('invoices'), function () {
                $invoice = $this->invoices->last();
                if (!$invoice) {
                    return null;
                }
                return [
                    'uuid' => $invoice->uuid,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                    'total' => $this->roundMoney($invoice->total),
                    'currency' => $invoice->currency,
                    'verification_url' => url('/api/v1/general/invoices/verify/' . $invoice->uuid),
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'order_items' => OrderItemResource::collection($this->whenLoaded('orderItems')),
            'digital_downloads' => $this->when($this->relationLoaded('digitalEntitlements') && $this->digitalEntitlements->isNotEmpty(), fn() => $this->resolveDigitalDownloads()),
            // 'pickup_location_id' => $this->pickup_location_id,
            'payment_gateway' => $this->payment_gateway,
            'order_has_invoice' => $this->latestInvoice !== null,
            'invoice_id' => $this->latestInvoice?->uuid,
        ];
    }

    private function fallbackBaseCode(): ?string
    {
        return app(CurrencyService::class)->getBaseCode();
    }

    /**
     * Digital entitlement summary for delivered digital lines.
     * Never exposes storage paths or physical filenames of stored assets —
     * downloads happen through short-lived signed URLs issued separately.
     */
    private function resolveDigitalDownloads(): array
    {
        return $this->digitalEntitlements
            ->filter(fn ($e) => $e->status === \App\Models\DigitalEntitlement::STATUS_DELIVERED)
            ->map(function ($entitlement) {
                return [
                    'uuid' => $entitlement->uuid,
                    'order_item_id' => $entitlement->order_product_id,
                    'status' => $entitlement->status,
                    'download_limit' => (int) $entitlement->download_limit,
                    'download_count' => (int) $entitlement->download_count,
                    'delivered_at' => $entitlement->delivered_at?->toIso8601String(),
                    // BD1 Option B — product-scoped: late uploads included.
                    'assets' => $entitlement->currentAssets()->map(fn ($asset) => [
                        'uuid' => $asset->uuid,
                        'type' => $asset->type,
                        'original_name' => $asset->original_name,
                        'mime' => $asset->mime,
                        'size' => (int) $asset->size,
                    ])->values()->all(),
                ];
            })->values()->all();
    }

    private function resolvePickupLocation(): ?array
    {
        if ($this->relationLoaded('pickupLocation') && $this->pickupLocation) {
            return [
                'id' => $this->pickupLocation->id,
                'store_name' => $this->pickupLocation->store_name,
            ];
        }

        if ($this->pickup_location_name) {
            return [
                'id' => $this->pickup_location_id,
                'store_name' => $this->pickup_location_name,
            ];
        }

        return null;
    }

    private function roundMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}

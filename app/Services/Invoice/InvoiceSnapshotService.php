<?php

namespace App\Services\Invoice;

use Marvel\Database\Models\Order;

class InvoiceSnapshotService
{
    public function buildFullSnapshot(Order $order): array
    {
        $paidTransaction = $order->transactions->firstWhere('status', 'paid');

        return [
            'snapshot_version' => '2.1.0',
            'snapshot_schema' => 3,

            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'fulfillment_status' => $order->fulfillment_status,
            ],

            'customer' => [
                'id' => $order->user_id,
                'name' => $order->name,
                'email' => $order->user_email,
                'phone' => $order->user_phone,
            ],

            'billing_address' => $this->resolveAddress($order),
            'shipping_address' => $this->resolveAddress($order),

            'fulfillment' => [
                'type' => $order->fulfillment_type,
                'shipping_method' => $order->shipping_method,
                'shipping_price' => (float) $order->shipping_price,
                'fast_shipping_fee' => (float) ($order->fast_shipping_fee ?? 0),
                'expected_delivery_at' => $order->expected_delivery_at?->toIso8601String(),
            ],

            'pickup_location' => $order->fulfillment_type === 'pickup' ? [
                'id' => $order->pickup_location_id,
                'name' => $order->pickup_location_name,
                'address' => $order->pickup_location_address,
                'phone' => $order->pickup_location_phone,
                'coordinates' => $order->pickup_location_coordinates,
            ] : null,

            'items' => $order->orderItems->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'attributes' => $item->attributes,
                'quantity' => (int) $item->product_quantity,
                'unit_price' => (float) $item->product_price,
                'original_price' => (float) $item->product_price,
                'effective_unit_price' => (float) ($item->product_discount_price ?? $item->product_flash_sale_price ?? $item->product_price),
                'discount_price' => $item->product_discount_price ? (float) $item->product_discount_price : null,
                'flash_sale_price' => $item->product_flash_sale_price ? (float) $item->product_flash_sale_price : null,
                'promotion_discount_amount' => $item->promotion_discount_amount ? (float) $item->promotion_discount_amount : null,
                'product_tax_rate' => $item->product_tax_rate !== null ? (float) $item->product_tax_rate : null,
                'product_taxable_amount' => $item->product_taxable_amount !== null ? (float) $item->product_taxable_amount : null,
                'product_tax_amount' => (float) ($item->product_tax_amount ?? 0),
                'total_price' => (float) $item->product_total_price,
                'is_gift' => (bool) $item->is_gift,
                'promotion_id' => $item->promotion_id,
                'images' => [],
            ])->toArray(),

            'pricing_breakdown' => [
                'subtotal' => (float) $order->price,
                'promotion_discount' => (float) $order->promotion_discount,
                'coupon_discount' => (float) $order->coupon_discount,
                'product_taxable_amount' => (float) ($order->product_taxable_amount ?? 0),
                'product_tax_amount' => (float) ($order->product_tax_amount ?? 0),
                'order_tax_rate' => $order->order_tax_rate !== null ? (float) $order->order_tax_rate : null,
                'order_taxable_amount' => (float) ($order->order_taxable_amount ?? 0),
                'order_tax_amount' => (float) ($order->order_tax_amount ?? 0),
                'shipping_price' => (float) $order->shipping_price,
                'fast_shipping_fee' => (float) ($order->fast_shipping_fee ?? 0),
                'total' => (float) $order->total_price,
                'currency' => $paidTransaction?->currency ?? $order->currency_code ?? $order->base_currency_code ?? 'EGP',
                'exchange_rate' => null,
                'coupon' => $order->coupon ? [
                    'code' => $order->coupon,
                    'type' => $order->coupon_discount_type,
                    'discount' => (float) $order->coupon_discount,
                    'max_discount_amount' => $order->coupon_discount_max_amount ? (float) $order->coupon_discount_max_amount : null,
                ] : null,
                'promotion' => $order->promotion_id ? [
                    'id' => (int) $order->promotion_id,
                    'code' => $order->promotion_code,
                    'type' => $order->promotion_type,
                    'discount' => (float) $order->promotion_discount,
                ] : null,
            ],

            'payment' => [
                'method' => $order->payment_method,
                'gateway' => $order->payment_gateway,
                'transaction_id' => $paidTransaction?->id,
                'gateway_transaction_id' => $paidTransaction?->gateway_transaction_id,
                'paid_at' => $paidTransaction?->paid_at?->toIso8601String(),
                'gateway_invoice_id' => null,
                'gateway_response_summary' => null,
            ],

            'taxes' => (($order->order_tax_amount ?? 0) > 0 || ($order->product_tax_amount ?? 0) > 0) ? [
                [
                    'product_taxable_amount' => (float) ($order->product_taxable_amount ?? 0),
                    'product_tax_amount' => (float) ($order->product_tax_amount ?? 0),
                    'order_tax_rate' => $order->order_tax_rate !== null ? (float) $order->order_tax_rate : null,
                    'order_taxable_amount' => (float) ($order->order_taxable_amount ?? 0),
                    'order_tax_amount' => (float) ($order->order_tax_amount ?? 0),
                ],
            ] : [],

            'metadata' => [
                'system_version' => config('app.version', '1.0.0'),
                'locale' => app()->getLocale(),
                'ip_address' => null,
                'user_agent' => null,
                'generated_at' => now()->toIso8601String(),
            ],

            'notes' => $order->notes,

            'audit' => [
                'generated_by' => 'system',
                'generation_attempts' => 1,
                'correction_reason' => null,
                'cancellation_reason' => null,
            ],
        ];
    }

    private function resolveAddress(Order $order): array
    {
        $address = $order->address ?? [];

        return [
            'street' => $address['street'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'governorate' => $order->governorate?->name,
            'zip' => $address['zip'] ?? null,
            'country' => $address['country'] ?? null,
            'coordinates' => $address['coordinates'] ?? null,
        ];
    }
}

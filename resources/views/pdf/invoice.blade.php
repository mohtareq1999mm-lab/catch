<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Arial, DejaVu Sans, sans-serif; font-size: 12px; color: #333; }
        .header { width: 100%; margin-bottom: 30px; }
        .header-left { float: left; width: 50%; }
        .header-right { float: right; width: 50%; text-align: right; }
        .clearfix { clear: both; }
        h1 { font-size: 24px; color: #1a1a1a; margin: 0 0 5px; }
        h2 { font-size: 18px; color: #1a1a1a; margin: 0 0 5px; }
        .invoice-details { margin-bottom: 30px; }
        .invoice-details table { width: 100%; }
        .invoice-details td { padding: 2px 0; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 14px; font-weight: bold; color: #1a1a1a; border-bottom: 2px solid #019376; padding-bottom: 5px; margin-bottom: 10px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.items th { background-color: #019376; color: #fff; padding: 8px; text-align: left; font-size: 12px; }
        table.items th.right { text-align: right; }
        table.items th.center { text-align: center; }
        table.items td { padding: 6px 8px; border-bottom: 1px solid #d4d4d4; }
        table.items td.right { text-align: right; }
        table.items td.center { text-align: center; }
        table.items tr:nth-child(even) { background-color: #f9f9f9; }
        .totals { float: right; width: 45%; }
        .totals table { width: 100%; }
        .totals td { padding: 4px 8px; }
        .totals .label { color: #6b7280; }
        .totals .value { text-align: right; }
        .totals .grand-total td { font-weight: bold; font-size: 14px; border-top: 2px solid #1a1a1a; padding-top: 8px; }
        .footer { margin-top: 40px; padding-top: 10px; border-top: 1px solid #d4d4d4; font-size: 10px; color: #999; text-align: center; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        .badge-correction { background-color: #fff3cd; color: #856404; }
        .status { margin-top: 10px; }
    </style>
</head>
<body>
    @php
        $data = $invoice->data;
        $order = $data['order'] ?? [];
        $customer = $data['customer'] ?? [];
        $billing = $data['billing_address'] ?? [];
        $shipping = $data['shipping_address'] ?? [];
        $items = $data['items'] ?? [];
        $pricing = $data['pricing_breakdown'] ?? [];
        $fulfillment = $data['fulfillment'] ?? [];
        $payment = $data['payment'] ?? [];
        $notes = $data['notes'] ?? null;
        $currency = $pricing['currency'] ?? 'EGP';
    @endphp

    <div class="header">
        <div class="header-left">
            <h1>{{ config('app.name') }}</h1>
            <p>{{ __('Invoice') }}</p>
        </div>
        <div class="header-right">
            @if ($invoice->is_correction)
                <span class="badge badge-correction">{{ __('Corrected Invoice') }}</span>
            @endif
        </div>
        <div class="clearfix"></div>
    </div>

    <div class="invoice-details">
        <table>
            <tr>
                <td><strong>{{ __('Invoice No.') }}</strong></td>
                <td>{{ $invoice->invoice_number }}</td>
                <td><strong>{{ __('Date') }}</strong></td>
                <td>{{ $invoice->generated_at?->format('jS F, Y') ?? $invoice->created_at->format('jS F, Y') }}</td>
            </tr>
            @if($order['order_number'] ?? null)
            <tr>
                <td><strong>{{ __('Order No.') }}</strong></td>
                <td>{{ $order['order_number'] }}</td>
                <td><strong>{{ __('Due Date') }}</strong></td>
                <td>{{ $invoice->generated_at?->format('jS F, Y') ?? $invoice->created_at->format('jS F, Y') }}</td>
            </tr>
            @endif
            <tr>
                <td><strong>{{ __('Status') }}</strong></td>
                <td colspan="3">{{ ucfirst($invoice->status) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div style="width: 48%; float: left;">
            <div class="section-title">{{ __('Customer') }}</div>
            <p>
                <strong>{{ $customer['name'] ?? 'N/A' }}</strong><br>
                {{ $customer['email'] ?? '' }}<br>
                {{ $customer['phone'] ?? '' }}
            </p>
        </div>
        <div style="width: 48%; float: right;">
            <div class="section-title">{{ __('Shipping Address') }}</div>
            <p>
                {{ $shipping['street'] ?? '' }}<br>
                {{ $shipping['city'] ?? '' }}{{ isset($shipping['state']) ? ', ' . $shipping['state'] : '' }}<br>
                {{ $shipping['governorate'] ?? '' }} {{ $shipping['zip'] ?? '' }}<br>
                {{ $shipping['country'] ?? '' }}
            </p>
        </div>
        <div class="clearfix"></div>
    </div>

    <div class="section">
        <div class="section-title">{{ __('Products') }}</div>
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 50%;">{{ __('Product') }}</th>
                    <th class="center" style="width: 10%;">{{ __('Qty') }}</th>
                    <th class="right" style="width: 20%;">{{ __('Unit Price') }}</th>
                    <th class="right" style="width: 20%;">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                <tr>
                    <td>
                        {{ $item['product_name'] }}
                        @if(!empty($item['attributes']))
                            <br><small>{{ is_string($item['attributes']) ? $item['attributes'] : json_encode($item['attributes']) }}</small>
                        @endif
                        @if($item['is_gift'] ?? false)
                            <br><small style="color: #019376;">{{ __('Gift') }}</small>
                        @endif
                    </td>
                    <td class="center">{{ $item['quantity'] }}</td>
                    <td class="right">{{ number_format((float) ($item['effective_unit_price'] ?? $item['unit_price']), 2) }} {{ $currency }}</td>
                    <td class="right">{{ number_format((float) $item['total_price'], 2) }} {{ $currency }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="center">{{ __('No items') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="totals">
        <table>
            <tr>
                <td class="label">{{ __('Subtotal') }}</td>
                <td class="value">{{ number_format((float) ($pricing['subtotal'] ?? 0), 2) }} {{ $currency }}</td>
            </tr>
            @if(($pricing['promotion_discount'] ?? 0) > 0)
            <tr>
                <td class="label">{{ __('Promotion Discount') }}</td>
                <td class="value">-{{ number_format((float) $pricing['promotion_discount'], 2) }} {{ $currency }}</td>
            </tr>
            @endif
            @if(($pricing['coupon_discount'] ?? 0) > 0)
            <tr>
                <td class="label">{{ __('Coupon Discount') }}</td>
                <td class="value">-{{ number_format((float) $pricing['coupon_discount'], 2) }} {{ $currency }}</td>
            </tr>
            @endif
            @if(($pricing['shipping_price'] ?? 0) > 0)
            <tr>
                <td class="label">{{ __('Shipping') }}</td>
                <td class="value">{{ number_format((float) $pricing['shipping_price'], 2) }} {{ $currency }}</td>
            </tr>
            @endif
            <tr class="grand-total">
                <td class="label">{{ __('Total') }}</td>
                <td class="value">{{ number_format((float) ($pricing['total'] ?? 0), 2) }} {{ $currency }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Paid') }}</td>
                <td class="value">{{ number_format((float) $invoice->amount_paid, 2) }} {{ $currency }}</td>
            </tr>
        </table>
    </div>
    <div class="clearfix"></div>

    @if($notes)
    <div class="section">
        <div class="section-title">{{ __('Notes') }}</div>
        <p>{{ $notes }}</p>
    </div>
    @endif

    @if($invoice->correction_reason)
    <div class="section">
        <div class="section-title">{{ __('Correction Reason') }}</div>
        <p>{{ $invoice->correction_reason }}</p>
    </div>
    @endif

    <div class="footer">
        <p>{{ config('app.name') }} - {{ __('Invoice') }} {{ $invoice->invoice_number }}</p>
        <p>{{ __('Generated on') }} {{ $invoice->generated_at?->format('Y-m-d H:i:s') ?? $invoice->created_at->format('Y-m-d H:i:s') }}</p>
    </div>
</body>
</html>

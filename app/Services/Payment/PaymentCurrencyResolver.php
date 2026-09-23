<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Currency\CurrencyService;
use Marvel\Database\Models\Order;

/**
 * Catalog currency authority for the payment path.
 *
 * Payment/order currency is ALWAYS the Admin catalog currency.
 */
final class PaymentCurrencyResolver
{
    public function __construct(
        private CurrencyService $currencyService,
    ) {}

    public function current(): string
    {
        return strtoupper(trim($this->currencyService->getCatalogCode()));
    }

    public function forOrder(Order $order): string
    {
        $catalog = isset($order->catalog_currency_code) && $order->catalog_currency_code !== null && trim((string) $order->catalog_currency_code) !== ''
            ? trim((string) $order->catalog_currency_code)
            : null;

        if ($catalog !== null) {
            return strtoupper($catalog);
        }

        $currency = isset($order->currency_code) && $order->currency_code !== null && trim((string) $order->currency_code) !== ''
            ? trim((string) $order->currency_code)
            : null;

        if ($currency !== null) {
            return strtoupper($currency);
        }

        try {
            return strtoupper(trim($this->currencyService->getCatalogCode()));
        } catch (\Throwable $e) {
            return strtoupper(trim((string) config('payment.default_currency', 'KWD')));
        }
    }
}

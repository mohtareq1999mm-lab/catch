<?php

namespace App\Http\Resources\Product;

use App\Services\Currency\CurrencyService;

trait ConvertsProductPrice
{
    protected function convertCatalogPrice($catalogPrice): ?float
    {
        if ($catalogPrice === null || $catalogPrice === '') {
            return null;
        }

        $currencyService = app(CurrencyService::class);

        return $currencyService->convertPrice(
            $catalogPrice,
            $currencyService->getCatalogCode(),
            $currencyService->getEffectiveCode(),
        );
    }

    protected function effectiveCurrency(): ?array
    {
        $currency = app(CurrencyService::class)->getEffectiveCurrency();

        if (!$currency) {
            return null;
        }

        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->getTranslations('name'),
            'symbol' => $currency->getTranslations('symbol') ?: ['en' => $currency->code],
            'country_name' => $currency->getTranslations('country_name'),
            'icon' => $currency->icon,
        ];
    }
}

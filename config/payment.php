<?php

return [
    'default_gateway' => env('DEFAULT_PAYMENT_GATEWAY', 'myfatoorah'),
    'default_currency' => env('DEFAULT_CURRENCY', 'KWD'),
    'order_timeout_hours' => env('ORDER_TIMEOUT_HOURS', 24),
    'cod_order_timeout_hours' => env('COD_ORDER_TIMEOUT_HOURS', 24 * 7),

    'gateways' => [
        'myfatoorah' => [
            'class' => App\Services\Gateway\MyFatoorahGateway::class,
            'api_key' => env('MYFATOORAH_API_KEY'),
            'base_url' => env('MYFATOORAH_BASE_URL', 'https://apitest.myfatoorah.com/v2/'),
            // Currencies supported by MyFatoorah. Checkout is blocked when the
            // order currency is not in this list (no silent base-currency fallback).
            'supported_currencies' => array_values(array_filter(array_map(
                'strtoupper',
                explode(',', (string) env('MYFATOORAH_SUPPORTED_CURRENCIES', 'KWD,SAR,AED,BHD,QAR,OMR,EGP'))
            ))),
        ],
    ],
];

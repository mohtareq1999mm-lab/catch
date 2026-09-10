<?php

namespace App\Services\Payment;

use App\Exceptions\UnsupportedGatewayException;
use App\Services\Gateway\MyFatoorahGateway;
use App\Services\Payment\Contracts\PaymentGatewayContract;

class PaymentGatewayFactory
{
    public function make(string $gateway): PaymentGatewayContract
    {
        return match ($gateway) {
            'myfatoorah' => app(MyFatoorahGateway::class),
            default => throw new UnsupportedGatewayException($gateway),
        };
    }
}

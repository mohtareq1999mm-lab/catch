<?php

namespace App\Services\Payment\Contracts;

use App\DTOs\GatewayResult;
use Marvel\Database\Models\Order;

interface PaymentGatewayContract
{
    public function createInvoice(
        Order $order,
        float $amount,
        string $callbackUrl,
        string $errorUrl,
        array $metadata = []
    ): GatewayResult;

    public function verifyPayment(string $gatewayTransactionId): GatewayResult;

    public function refund(
        Order $order,
        float $amount,
        ?string $reason = null
    ): GatewayResult;

    public function name(): string;

    public function supportsCurrency(string $currencyCode): bool;
}

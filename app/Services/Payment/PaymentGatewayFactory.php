<?php

namespace App\Services\Payment;

use App\Services\Payment\Contracts\PaymentGatewayContract;

/**
 * Thin resolution seam over PaymentGatewayRegistry.
 *
 * INITIATE vs VERIFY split (enforced by callers, not here):
 * - Initiate (createInvoice, refund): must pass canInitiate() first —
 *   disabled, misconfigured, or currency-unsupported gateways fail closed
 *   with 422 and no provider call (see PaymentCheckoutHandler,
 *   PaymentRefundService).
 * - Verify (verifyPayment): intentionally bypasses the enabled/configured
 *   gates (registry canVerify() only checks the class resolves) so in-flight
 *   payments still complete after a mid-payment disable (see OrderController
 *   callbacks, PaymentWebhookController). Factory mocks keep intercepting in
 *   tests on both paths.
 */
class PaymentGatewayFactory
{
    public function __construct(
        private PaymentGatewayRegistry $registry,
    ) {}

    public function make(string $gateway): PaymentGatewayContract
    {
        return $this->registry->resolve($gateway);
    }
}

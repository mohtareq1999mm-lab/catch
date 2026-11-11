<?php

namespace App\Services\Payment\Contracts;

use App\DTOs\GatewayResult;

/**
 * PM-7: capability contract for gateways whose payment lifecycle includes a
 * MERCHANT-initiated capture of an approved (but not yet captured) provider
 * order. Implemented only by gateways that actually support this (PayPal),
 * following the AuthorisationGatewayContract pattern: separate from the base
 * contract so unrelated gateways are never forced into fake implementations;
 * callers MUST instanceof-check before use.
 *
 * capturePayment() is an EXPLICIT financial command:
 * - Must be idempotent per provider order (same stable provider idempotency
 *   key on every retry of the same logical capture).
 * - Must only be invoked after a server-side read-only probe confirms the
 *   provider state legitimately authorizes capture (never from a probe,
 *   never from an unauthenticated payload, never as a side effect).
 * - A timeout/inconclusive capture answer is UNKNOWN: reconcile with a
 *   read-only probe (COMPLETED vs APPROVED) before any further capture.
 */
interface CapturingGatewayContract extends PaymentGatewayContract
{
    public function capturePayment(string $gatewayTransactionId): GatewayResult;
}

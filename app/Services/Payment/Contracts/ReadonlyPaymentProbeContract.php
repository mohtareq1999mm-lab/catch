<?php

namespace App\Services\Payment\Contracts;

use App\DTOs\GatewayResult;

/**
 * PM-5: STRICTLY read-only provider payment-status retrieval.
 *
 * Capability contract following the repo's established pattern
 * (AuthorisationGatewayContract): implemented ONLY by gateways that can
 * answer status without any provider-side mutation, `instanceof`-checked by
 * every infrastructure caller (Reaper, PaymentReconciliationJob, webhook
 * completion, PM-7 recovery). Callers MUST fall back to verifyPayment() for
 * contracts that do not implement it (test doubles / future adapters).
 *
 * A probe MUST NEVER: capture funds, authorize funds, refund, cancel/void,
 * or create a new provider session.
 *
 * Result semantics (aligned with existing adapter conventions):
 * - success=true  + status='paid'    → provider-confirmed completed payment.
 * - success=true  + status='pending' → provider-confirmed OPEN session
 *   (still collectable / awaiting merchant action). For PayPal the raw
 *   response carries '_probe_provider_status' (APPROVED vs CREATED) so the
 *   capture flow can distinguish without a second call.
 * - success=false + status='failed'  → provider-CONFIRMED terminal failure
 *   (denied / expired / voided) — the only evidence class that may mark a
 *   local attempt failed (T-03: never elapsed time alone).
 * - success=false + status='pending' → INCONCLUSIVE (transport/provider
 *   error): deduce NOTHING, keep Unknown, retry later.
 */
interface ReadonlyPaymentProbeContract extends PaymentGatewayContract
{
    public function probePaymentStatus(string $gatewayTransactionId): GatewayResult;
}

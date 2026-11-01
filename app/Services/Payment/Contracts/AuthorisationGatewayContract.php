<?php

namespace App\Services\Payment\Contracts;

use App\DTOs\GatewayResult;
use Marvel\Database\Models\Order;

/**
 * Capability contract for gateways with a separate post-verification money
 * lifecycle: AUTHORISATION first (money obligation), CAPTURE later (settle-
 * ment, tied to shipment), and a pre-capture provider CANCEL.
 *
 * Implemented ONLY by gateways that actually support this split (Tamara).
 * It extends PaymentGatewayContract (verify/refund/name/code/configure/
 * currency stay the baseline) so type-checks never over-promise, but stays
 * SEPARATE from it so unrelated gateways are never forced into fake no-op
 * implementations of these capabilities (Task Part 4 rule 4). Callers MUST
 * instanceof-check before use.
 *
 * Semantics:
 * - authorise() — confirm an approved/awaiting-authorisation provider order.
 *   Duplicate calls are safe (the adapter fresh-reads first and only acts when
 *   the provider state demands it); a refusal bubbles as success=false and
 *   the caller arbitrates from a fresh read (the payload never decides).
 * - captureFromShipment() — $shipment array shape (per
 *   TamaraGateway::captureFromShipment docs): items[] = actual shipped lines
 *   (reference_id, sku, name, quantity, unit_price, tax_amount,
 *   discount_amount?, total_amount), plus shipped_at/shipping_company/
 *   tracking_number/fulfillment_id. Capture is settlement bookkeeping —
 *   NEVER a payment completion.
 * - cancelAuthorised() — provider cancellation for an authorised-but-NOT-
 *   captured order. Captured orders must NOT be cancelled (refund instead);
 *   tamara: a cancellation that raced past the fresh read 409s (409
 *   transition_not_allowed) and resolves via a fresh read.
 */
interface AuthorisationGatewayContract extends PaymentGatewayContract
{
    public function authorise(string $gatewayTransactionId): GatewayResult;

    public function captureFromShipment(Order $order, string $gatewayTransactionId, array $shipment): GatewayResult;

    public function cancelAuthorised(Order $order, string $gatewayTransactionId): GatewayResult;
}

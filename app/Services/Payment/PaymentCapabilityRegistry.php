<?php

namespace App\Services\Payment;

/**
 * D. Gateway Capability Registry — fail-closed activation.
 *
 * The single source of truth for what each gateway is PROVEN to support.
 * Every capability is gated on explicit evidence (repository verification
 * and/or official provider documentation and/or sandbox verification).
 * Capabilities are NEVER inferred from another gateway's behavior; a
 * capability without evidence stays false and every consuming code path
 * must fail closed (disabled / Unknown / operational-review).
 *
 * Activation evidence (verified 2026-11, HEAD 12a9de9):
 * - webhook_auth: Stripe signature (PaymentWebhookController:105-117),
 *   PayPal SDK verify (:241), Tap HMAC (:302-368), Tabby auth header
 *   (:447-472), Tamara JWT (TamaraWebhookVerifier). MyFatoorah has NO
 *   webhook at all (routes/api.php:137).
 * - read_only_retrieval: probePaymentStatus implemented on all six adapters
 *   with financial side effects stripped (PayPal/Tabby/Tamara).
 * - merchant_reference_correlation: Stripe metadata (StripeGateway:121),
 *   PayPal custom_id (resolver candidates PaymentWebhookController:1790),
 *   Tabby order.reference_id (echo documented at :413), Tamara
 *   order_reference_id (:644). Tap echo INFERRED — disabled until sandbox
 *   echo verification succeeds. MyFatoorah: NO carrier → false forever
 *   until evidence exists.
 * - idempotent_session_creation: PayPal Request-Id (OD), Stripe
 *   Idempotency-Key (OD, PM-4), Tap reference.idempotent 24h (OD). Tabby
 *   order-derived reference (INFERRED). Tamara: fresh UUID per call → false.
 * - idempotent_financial_operations: PayPal capture-{id} (VERIFIED);
 *   Tabby refund reference_id (VERIFIED docblock). Others unproven → false.
 * - session_invalidation: Tamara cancelAuthorised (repo contract, VERIFIED).
 *   Stripe Expire API exists (OD) but is NOT wired — false until
 *   implemented + tested. PayPal/Tap/Tabby/MyFatoorah: no verified
 *   merchant invalidation → false.
 * - provider_confirmed_expiration: Stripe (expired session status, OD),
 *   Tabby (EXPIRED terminal, OD), Tap (charge expiry/terminal statuses, OD).
 *   PayPal/Tamara/MyFatoorah: unproven → false.
 * - stable_event_identity: Stripe event.id + PayPal body.id (VERIFIED).
 *   Tabby/Tamara/Tap: NO stable id → fingerprint fallback (W-1).
 * - sandbox_verified_correlation: per-gateway flag flipped ONLY by recorded
 *   sandbox evidence. Tap = false (disabled until echo verified).
 */
final class PaymentCapabilityRegistry
{
    private const CAPABILITIES = [
        'myfatoorah' => [
            'webhook_auth' => false,
            'merchant_reference_correlation' => false,
            'read_only_retrieval' => true,
            'idempotent_session_creation' => false,
            'idempotent_financial_operations' => false,
            'session_invalidation' => false,
            'provider_confirmed_expiration' => false,
            'stable_event_identity' => false,
            'sandbox_verified_correlation' => false,
        ],
        'stripe' => [
            'webhook_auth' => true,
            'merchant_reference_correlation' => true,
            'read_only_retrieval' => true,
            'idempotent_session_creation' => true,
            'idempotent_financial_operations' => false,
            'session_invalidation' => false,
            'provider_confirmed_expiration' => true,
            'stable_event_identity' => true,
            'sandbox_verified_correlation' => false,
        ],
        'paypal' => [
            'webhook_auth' => true,
            'merchant_reference_correlation' => true,
            'read_only_retrieval' => true,
            'idempotent_session_creation' => true,
            'idempotent_financial_operations' => true,
            'session_invalidation' => false,
            'provider_confirmed_expiration' => false,
            'stable_event_identity' => true,
            'sandbox_verified_correlation' => false,
        ],
        'tabby' => [
            'webhook_auth' => true,
            'merchant_reference_correlation' => true,
            'read_only_retrieval' => true,
            'idempotent_session_creation' => false,
            'idempotent_financial_operations' => true,
            'session_invalidation' => false,
            'provider_confirmed_expiration' => true,
            'stable_event_identity' => false,
            'sandbox_verified_correlation' => false,
        ],
        'tamara' => [
            'webhook_auth' => true,
            'merchant_reference_correlation' => true,
            'read_only_retrieval' => true,
            'idempotent_session_creation' => false,
            'idempotent_financial_operations' => false,
            'session_invalidation' => true,
            'provider_confirmed_expiration' => false,
            'stable_event_identity' => false,
            'sandbox_verified_correlation' => false,
        ],
        'tap' => [
            'webhook_auth' => true,
            'merchant_reference_correlation' => false, // DISABLED until sandbox echo verification (D)
            'read_only_retrieval' => true,
            'idempotent_session_creation' => true,
            'idempotent_financial_operations' => false,
            'session_invalidation' => false,
            'provider_confirmed_expiration' => true,
            'stable_event_identity' => false,
            'sandbox_verified_correlation' => false,
        ],
    ];

    public static function supports(string $gateway, string $capability): bool
    {
        return self::CAPABILITIES[strtolower($gateway)][$capability] ?? false;
    }

    /** Correlation is active only when the capability is proven AND the
     * gateway's echo is documented/sandbox-verified (Tap stays disabled
     * until its echo verification is recorded). */
    public static function correlationActive(string $gateway): bool
    {
        $gateway = strtolower($gateway);

        if (!self::supports($gateway, 'merchant_reference_correlation')) {
            return false;
        }

        // Gateways whose webhook echo is not yet proven (Tap) stay disabled
        // until sandbox evidence flips sandbox_verified_correlation.
        if (in_array($gateway, ['tap', 'myfatoorah'], true)) {
            return self::supports($gateway, 'sandbox_verified_correlation');
        }

        return true; // Stripe / PayPal / Tabby / Tamara: documented echo
    }
}

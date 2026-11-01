<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Tabby payment-webhook verification — Tabby's documented self-service
 * mechanism (docs.tabby.ai/pay-in-4-custom-integration/webhooks.md).
 *
 * Webhooks are registered per merchant_code + secret key with a CUSTOM
 * header `{title, value}` (POST /api/v1/webhooks); Tabby echoes the
 * registered header with its registered value on EVERY delivery. There is
 * no hashing/HMAC step on the payment-webhook family — verification IS
 * the shared-secret comparison of the raw header value against the
 * configured secret.
 *
 * Fail-closed: an empty received value or empty configured secret returns
 * false (caller rejects 401/503; money movement is decided exclusively by
 * the server-side re-verification path — TabbyGateway::verifyPayment —
 * not by any webhook payload field).
 *
 * Comparison uses hash_equals (timing-safe); values are compared exactly
 * as received (no trimming — the secret is an opaque string).
 */
final class TabbyWebhookVerifier
{
    /**
     * @param string $receivedValue the TABBY-* header value as delivered
     * @param string $secret        the TABBY_WEBHOOK_SECRET (registered
     *                             header value)
     */
    public function verify(string $receivedValue, string $secret): bool
    {
        if ($receivedValue === '' || $secret === '') {
            return false;
        }

        return hash_equals($secret, $receivedValue);
    }
}

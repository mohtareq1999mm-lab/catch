<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Firebase\JWT\ExpiredException as JwtExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException as JwtSignatureInvalidException;

/**
 * Tamara payment-webhook verification — Tamara's documented self-service
 * mechanism (docs.tamara.co/docs/transaction-authorisation, webhook
 * registration step in the partner portal).
 *
 * Tamara deliveries carry a `tamaraToken` in BOTH the query string and the
 * `Authorization: Bearer` header. That token is an HS256 JWT signed with
 * the merchant's Notification Token (a SEPARATE credential from the API
 * Token that performs API calls). Sample claims per the docs:
 * {exp, iat, iss: "Tamara"}.
 *
 * Verification IS the JWT cryptographic check (firebase/php-jwt v6, already
 * vendored via kreait/firebase-php — no new dependency): signature against
 * the configured Notification Token, algorithm pinned to HS256, `exp`
 * enforced by the library. A token that merely base64-decodes is NEVER
 * accepted — the HMAC must validate.
 *
 * Token source precedence (documented dual-channel):
 *   1. BOTH present and DIFFERENT ⇒ reject (one channel could be attacker-
 *      controlled; a mismatch means the request is not a tamper-free
 *      delivery — fail closed).
 *   2. Either channel present ⇒ verify that one.
 *   3. Neither present ⇒ reject (unsigned delivery).
 *
 * Fail-closed on: missing token, malformed JWT, invalid signature, expired
 * token, empty configured secret (caller rejects 503/401). `iss` is NOT
 * hard-required: the docs show "Tamara" but over-asserting an extra claim
 * risks dropping valid notifications — revisit on first live payload
 * (documented INFERENCE, not a verified contract).
 *
 * The Notification Token is never logged; callers log outcome + refs only.
 */
final class TamaraWebhookVerifier
{
    // Firebase\JWT\JWT::$leeway is static/global; no leeway is configured in
    // this app's other JWT consumers, and clock skew between Tamara and the
    // merchant server is handled by Tamara's token TTLs (exp/iat), so leeway
    // stays 0 — the strictest correct posture.

    /**
     * @param string|null $queryToken  tamaraToken from the query string (raw).
     * @param string|null $bearerToken Authorization: Bearer <token> (raw).
     */
    public function verify(?string $queryToken, ?string $bearerToken): bool
    {
        $secret = trim((string) config('payment.gateways.tamara.notification_token', ''));

        if ($secret === '') {
            // Fail-closed: an unconfigured secret must never verify anything.
            return false;
        }

        $queryToken = is_string($queryToken) ? trim($queryToken) : '';
        $bearerToken = is_string($bearerToken) ? trim($bearerToken) : '';

        if ($queryToken !== '' && $bearerToken !== '' && !hash_equals($queryToken, $bearerToken)) {
            // Both channels present but contradictory: not a tamper-free
            // delivery shape. Fail closed.
            return false;
        }

        $token = $queryToken !== '' ? $queryToken : $bearerToken;

        if ($token === '') {
            return false;
        }

        try {
            JWT::decode($token, new Key($secret, 'HS256'));

            return true;
        } catch (JwtSignatureInvalidException) {
            return false;
        } catch (JwtExpiredException) {
            return false;
        } catch (\Throwable) {
            // Malformed JWT / wrong key type / unexpected algorithm: any
            // failure outside the specific catches is still a rejection.
            return false;
        }
    }
}

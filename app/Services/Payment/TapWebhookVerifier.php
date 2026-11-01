<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Tap webhook (hashstring) verification — Tap's OFFICIAL documented
 * mechanism (developers.tap.company/docs/webhook, "Validate the webhook
 * (hashstring)"). No invented headers or algorithms.
 *
 * Official sample (reproduced exactly):
 *
 *   $toBeHashedString = 'x_id' . $id . 'x_amount' . $amount . 'x_currency'
 *     . $currency . 'x_gateway_reference' . $gateway_reference
 *     . 'x_payment_reference' . $payment_reference . 'x_status' . $status
 *     . 'x_created' . $created;
 *   $myHashString = hash_hmac('sha256', $toBeHashedString, $SecretAPIKey);
 *
 * Additional documented step: the amount is rounded to the currency's
 * standard ISO decimal value BEFORE hashing (AED 2.00, BHD 3.000,
 * KWD 3.000, OMR 3.000, QAR 2.00, SAR 2.00, USD 2.00, EUR 2.00, GBP 2.00,
 * EGP 2.00, JOD 3.000) — CurrencyPrecision::decimalsFor matches this
 * table for every listed currency, so it is the rounding authority here.
 *
 * The doc states gateway_reference (charge.reference.gateway) must be an
 * EMPTY VALUE when absent; payment_reference is treated the same way.
 * Comparison uses hash_equals (timing-safe) — a deliberate upgrade from
 * the doc sample's `==`.
 *
 * Fail-closed: any missing/non-scalar/missing-field input returns false;
 * the caller (PaymentWebhookController::tap) then rejects with 401 and
 * money movement is decided by the server-side re-verification path.
 */
final class TapWebhookVerifier
{
    /**
     * @param array<string, mixed> $payload decoded webhook body (charge
     *        object as POSTed by Tap)
     */
    public function verify(array $payload, string $postedHash, string $secret): bool
    {
        if ($postedHash === '' || $secret === '') {
            return false;
        }

        $id = $this->hashValue($payload['id'] ?? null);
        $currency = $this->hashValue($payload['currency'] ?? null);
        $status = $this->hashValue($payload['status'] ?? null);
        $reference = is_array($payload['reference'] ?? null) ? $payload['reference'] : [];
        $gatewayReference = $this->hashValue($reference['gateway'] ?? null);
        $paymentReference = $this->hashValue($reference['payment'] ?? null);
        $created = $this->hashValue(is_array($payload['transaction'] ?? null) ? ($payload['transaction']['created'] ?? null) : null);

        $amount = $payload['amount'] ?? null;

        if ($id === '' || $currency === '' || $status === '' || !is_numeric($amount)) {
            return false;
        }

        $decimals = CurrencyPrecision::decimalsFor($currency);
        $roundedAmount = number_format(round((float) $amount, $decimals), $decimals, '.', '');

        $toBeHashed = 'x_id' . $id
            . 'x_amount' . $roundedAmount
            . 'x_currency' . $currency
            . 'x_gateway_reference' . $gatewayReference
            . 'x_payment_reference' . $paymentReference
            . 'x_status' . $status
            . 'x_created' . $created;

        $computed = hash_hmac('sha256', $toBeHashed, $secret);

        return hash_equals($computed, $postedHash);
    }

    /**
     * Faithful string representation of a posted hash component: strings
     * pass through unmodified (no trimming — the hash covers the exact
     * posted bytes), scalars are cast, anything else (null/arrays) is the
     * documented "empty value" case.
     */
    private function hashValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}

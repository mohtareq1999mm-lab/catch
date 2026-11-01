<?php

namespace Tests\Unit;

use App\Services\Payment\TapWebhookVerifier;
use Tests\TestCase;

/**
 * Tap webhook (hashstring) verification against the OFFICIAL documented
 * algorithm (developers.tap.company/docs/webhook):
 *
 *   hash_hmac('sha256', 'x_id'.$id.'x_amount'.$amount.'x_currency'.$currency
 *     .'x_gateway_reference'.$gateway_reference
 *     .'x_payment_reference'.$payment_reference
 *     .'x_status'.$status.'x_created'.$created, SecretAPIKey)
 *
 * with the amount rounded to the currency's ISO exponent as an additional
 * step. Expected hashes below are built EXPLICITLY from the documented
 * concatenation (never through a shared helper) so any implementation
 * drift breaks these tests.
 *
 * DB-free unit tests (MySQL unreachable in this environment — see
 * docs/audits/tap-payments-integration-audit.md §13/§15).
 */
class TapWebhookVerifierTest extends TestCase
{
    private const SECRET = 'sk_test_unit_webhook_secret';

    /** @test */
    public function it_verifies_the_documented_hashstring_algorithm()
    {
        // Mirrors the official doc's charges webhook example shape
        // (SAR, 2-decimal amount, reference.gateway present, string created).
        $payload = [
            'id' => 'chg_TS05A4120230736x9K22710693',
            'status' => 'CAPTURED',
            'amount' => 1.0,
            'currency' => 'SAR',
            'reference' => [
                'gateway' => 'mada_pg70983e7a-a686-40ba-83e2-c5e9f4074fe5',
                'payment' => '4327230736106619650',
            ],
            'transaction' => [
                'created' => '1698392202943',
            ],
        ];

        $expected = hash_hmac(
            'sha256',
            'x_id' . 'chg_TS05A4120230736x9K22710693'
            . 'x_amount' . '1.00'
            . 'x_currency' . 'SAR'
            . 'x_gateway_reference' . 'mada_pg70983e7a-a686-40ba-83e2-c5e9f4074fe5'
            . 'x_payment_reference' . '4327230736106619650'
            . 'x_status' . 'CAPTURED'
            . 'x_created' . '1698392202943',
            self::SECRET,
        );

        $this->assertTrue((new TapWebhookVerifier())->verify($payload, $expected, self::SECRET));
    }

    /** @test */
    public function it_rounds_three_decimal_currency_amounts_for_the_hash()
    {
        // KWD exponent 3 (doc table: KWD - 3.000). 13.2549 → "13.255".
        $payload = [
            'id' => 'chg_kwd_1',
            'status' => 'CAPTURED',
            'amount' => 13.2549,
            'currency' => 'KWD',
            'reference' => [],
            'transaction' => ['created' => '1698392202943'],
        ];

        $expected = hash_hmac('sha256', 'x_idchg_kwd_1x_amount13.255x_currencyKWDx_gateway_referencex_payment_referencex_statusCAPTUREDx_created1698392202943', self::SECRET);

        $verifier = new TapWebhookVerifier();

        $this->assertTrue($verifier->verify($payload, $expected, self::SECRET));
        // An unrounded amount string must NOT verify (the rounding step is
        // documented as mandatory).
        $unrounded = hash_hmac('sha256', 'x_idchg_kwd_1x_amount13.2549x_currencyKWDx_gateway_referencex_payment_referencex_statusCAPTUREDx_created1698392202943', self::SECRET);
        $this->assertFalse($verifier->verify($payload, $unrounded, self::SECRET));
    }

    /** @test */
    public function it_hashes_gateway_reference_as_empty_when_absent()
    {
        // Doc: gateway_reference "If value is not available, please pass
        // empty value." payment_reference treated the same way.
        $payload = [
            'id' => 'chg_noref',
            'status' => 'CAPTURED',
            'amount' => 10.0,
            'currency' => 'USD',
            'transaction' => ['created' => '1698392202943'],
        ];

        $expected = hash_hmac('sha256', 'x_idchg_norefx_amount10.00x_currencyUSDx_gateway_referencex_payment_referencex_statusCAPTUREDx_created1698392202943', self::SECRET);

        $this->assertTrue((new TapWebhookVerifier())->verify($payload, $expected, self::SECRET));
    }

    /** @test */
    public function it_rejects_a_tampered_payload_field()
    {
        $payload = [
            'id' => 'chg_tamper',
            'status' => 'CAPTURED',
            'amount' => 1.0,
            'currency' => 'SAR',
            'reference' => ['gateway' => 'gw', 'payment' => 'pay'],
            'transaction' => ['created' => '1698392202943'],
        ];

        $expected = hash_hmac('sha256', 'x_idchg_tamperx_amount1.00x_currencySARx_gateway_referencegwx_payment_referencepayx_statusCAPTUREDx_created1698392202943', self::SECRET);

        // Same valid hash, but the AMOUNT was inflated after signing.
        $tampered = $payload;
        $tampered['amount'] = 999.0;

        $verifier = new TapWebhookVerifier();

        $this->assertTrue($verifier->verify($payload, $expected, self::SECRET));
        $this->assertFalse($verifier->verify($tampered, $expected, self::SECRET));

        // Tampered status.
        $failedStatus = $payload;
        $failedStatus['status'] = 'FAILED';
        $this->assertFalse($verifier->verify($failedStatus, $expected, self::SECRET));
    }

    /** @test */
    public function it_rejects_a_wrong_secret()
    {
        $payload = [
            'id' => 'chg_secret',
            'status' => 'CAPTURED',
            'amount' => 1.0,
            'currency' => 'SAR',
            'reference' => [],
            'transaction' => ['created' => '1698392202943'],
        ];

        $expected = hash_hmac('sha256', 'x_idchg_secretx_amount1.00x_currencySARx_gateway_referencex_payment_referencex_statusCAPTUREDx_created1698392202943', 'sk_test_DIFFERENT_key');

        $this->assertFalse((new TapWebhookVerifier())->verify($payload, $expected, self::SECRET));
    }

    /** @test */
    public function it_fails_closed_on_missing_or_non_numeric_amount()
    {
        $verifier = new TapWebhookVerifier();

        $noAmount = [
            'id' => 'chg_x', 'status' => 'CAPTURED', 'currency' => 'SAR',
            'reference' => [], 'transaction' => ['created' => '1698392202943'],
        ];

        $nonNumeric = [
            'id' => 'chg_x', 'status' => 'CAPTURED', 'currency' => 'SAR',
            'amount' => 'not-a-number',
            'reference' => [], 'transaction' => ['created' => '1698392202943'],
        ];

        $this->assertFalse($verifier->verify($noAmount, hash('sha256', 'anything'), self::SECRET));
        $this->assertFalse($verifier->verify($nonNumeric, hash('sha256', 'anything'), self::SECRET));
    }

    /** @test */
    public function it_fails_closed_on_missing_core_fields_or_empty_inputs()
    {
        $verifier = new TapWebhookVerifier();

        $this->assertFalse($verifier->verify([], hash('sha256', 'x'), self::SECRET));
        $this->assertFalse($verifier->verify([
            'id' => 'chg_x', 'status' => 'CAPTURED', 'amount' => 1.0,
        ], hash('sha256', 'x'), self::SECRET));

        // Empty posted hash / empty secret.
        $this->assertFalse($verifier->verify([
            'id' => 'chg_x', 'status' => 'CAPTURED', 'amount' => 1.0, 'currency' => 'SAR',
        ], '', self::SECRET));
        $this->assertFalse($verifier->verify([
            'id' => 'chg_x', 'status' => 'CAPTURED', 'amount' => 1.0, 'currency' => 'SAR',
        ], hash('sha256', 'x'), ''));
    }

    /** @test */
    public function it_accepts_numeric_created_values_from_the_posted_payload()
    {
        // Some payloads may carry the millisecond timestamp unquoted; the
        // hash must cover the identical string either way.
        $payload = [
            'id' => 'chg_int_created',
            'status' => 'CAPTURED',
            'amount' => 1.0,
            'currency' => 'SAR',
            'reference' => [],
            'transaction' => ['created' => 1698392202943],
        ];

        $expected = hash_hmac('sha256', 'x_idchg_int_createdx_amount1.00x_currencySARx_gateway_referencex_payment_referencex_statusCAPTUREDx_created1698392202943', self::SECRET);

        $this->assertTrue((new TapWebhookVerifier())->verify($payload, $expected, self::SECRET));
    }
}

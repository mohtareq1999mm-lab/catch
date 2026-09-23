<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Gateway\PayPalGateway;
use App\Services\Payment\PaymentCurrencyResolver;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * MUST-FIX #2: PayPal capture idempotency.
 *
 * verifyPayment() captures APPROVED orders with a deterministic
 * PayPal-Request-Id ('capture-{paypalOrderId}') so a double verify collapses
 * into ONE provider-side capture, and an ORDER_ALREADY_CAPTURED race
 * re-fetches the order (COMPLETED ⇒ success) instead of failing.
 */
class PayPalCaptureIdempotencyTest extends CurrencyTestCase
{
    private CaptureFakePayPalClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();
        config(['payment.gateways.paypal.mode' => 'sandbox']);
        config(['payment.gateways.paypal.client_id' => 'test-client-id']);
        config(['payment.gateways.paypal.client_secret' => 'test-client-secret']);
        config(['payment.gateways.paypal.supported_currencies' => ['KWD', 'USD', 'EUR']]);

        $this->fake = new CaptureFakePayPalClient();
    }

    private function gateway(): PayPalGateway
    {
        return new PayPalGateway(
            app(PaymentCurrencyResolver::class),
            fn () => $this->fake,
        );
    }

    private function approvedPayload(): array
    {
        return [
            'id' => 'PAYPAL-CAP-1',
            'status' => 'APPROVED',
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'order-1',
                'amount' => ['currency_code' => 'KWD', 'value' => '13.255'],
            ]],
        ];
    }

    private function completedPayload(): array
    {
        return [
            'id' => 'PAYPAL-CAP-1',
            'status' => 'COMPLETED',
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'order-1',
                'amount' => ['currency_code' => 'KWD', 'value' => '13.255'],
                'payments' => ['captures' => [[
                    'id' => 'CAP-PAYPAL-CAP-1',
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => 'KWD', 'value' => '13.255'],
                ]]],
            ]],
        ];
    }

    /** @test */
    public function double_verify_same_approved_order_captures_once_and_succeeds_twice(): void
    {
        // First verify sees APPROVED (capture runs); the order is COMPLETED
        // from then on, so the second verify succeeds WITHOUT re-capturing.
        $calls = 0;
        $completed = $this->completedPayload();
        $this->fake->stubs['showOrderDetails'] = function () use (&$calls, $completed) {
            $calls++;

            return $calls === 1 ? $this->approvedPayload() : $completed;
        };
        $this->fake->stubs['capturePaymentOrder'] = $completed;

        $first = $this->gateway()->verifyPayment('PAYPAL-CAP-1');
        $second = $this->gateway()->verifyPayment('PAYPAL-CAP-1');

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertSame('paid', $first->status);
        $this->assertSame('paid', $second->status);

        // Exactly one capture call for two verifies...
        $this->assertCount(1, $this->fake->calls['capturePaymentOrder']);
        // ...sent with the deterministic idempotency key.
        $this->assertSame('capture-PAYPAL-CAP-1', $this->fake->headers['PayPal-Request-Id']);
    }

    /** @test */
    public function already_captured_race_refetches_and_succeeds(): void
    {
        // Concurrent verify won the capture first: our capture call answers
        // ORDER_ALREADY_CAPTURED, the re-fetch shows COMPLETED ⇒ success.
        $this->fake->stubs['showOrderDetails'] = function () {
            static $calls = 0;
            $calls++;

            // Call 1: initial verify sees APPROVED. Call 2: post-race
            // re-fetch sees the winner's COMPLETED order.
            return $calls === 1 ? $this->approvedPayload() : $this->completedPayload();
        };
        // srmklive wraps provider HTTP failures as ['error' => <body>].
        $this->fake->stubs['capturePaymentOrder'] = [
            'error' => '{"name":"ORDER_ALREADY_CAPTURED","message":"Order already captured","details":[{"issue":"ORDER_ALREADY_CAPTURED"}]}',
        ];

        $result = $this->gateway()->verifyPayment('PAYPAL-CAP-1');

        $this->assertTrue($result->success);
        $this->assertSame('paid', $result->status);
        $this->assertSame('KWD', $result->currency);
        $this->assertEqualsWithDelta(13.255, (float) $result->amount, 0.0000001);
    }

    /** @test */
    public function already_captured_race_without_completed_refetch_fails_closed(): void
    {
        // Race marker present but the order never reaches COMPLETED: the
        // verify must fail closed, never report paid.
        $this->fake->stubs['showOrderDetails'] = $this->approvedPayload();
        $this->fake->stubs['capturePaymentOrder'] = [
            'error' => '{"name":"ORDER_ALREADY_CAPTURED"}',
        ];

        $result = $this->gateway()->verifyPayment('PAYPAL-CAP-1');

        $this->assertFalse($result->success);
    }
}

/**
 * In-memory srmklive stand-in with header capture and closure stubs.
 */
final class CaptureFakePayPalClient
{
    /** @var array<string, list<mixed>> */
    public array $calls = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** @var array<string, mixed> */
    public array $stubs = [];

    public function setRequestHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;

        return $this;
    }

    public function createOrder(array $data): mixed
    {
        $this->calls['createOrder'][] = $data;

        return $this->respond('createOrder');
    }

    public function showOrderDetails(string $orderId): mixed
    {
        $this->calls['showOrderDetails'][] = $orderId;

        return $this->respond('showOrderDetails');
    }

    public function capturePaymentOrder(string $orderId, array $data = []): mixed
    {
        $this->calls['capturePaymentOrder'][] = $orderId;

        return $this->respond('capturePaymentOrder');
    }

    public function refundCapturedPayment(string $captureId, string $invoiceId, float $amount, string $note): mixed
    {
        $this->calls['refundCapturedPayment'][] = compact('captureId', 'invoiceId', 'amount', 'note');

        return $this->respond('refundCapturedPayment');
    }

    private function respond(string $method): mixed
    {
        $stub = $this->stubs[$method] ?? null;

        if ($stub instanceof \Throwable) {
            throw $stub;
        }

        if ($stub instanceof \Closure) {
            return $stub();
        }

        return $stub;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\General;

use App\DTOs\GatewayResult;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Exceptions\PaymentMismatchException;
use App\Exceptions\UnsupportedGatewayException;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentCompletionOutcome;
use App\Services\Payment\PaymentCompletionService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PayPalWebhookVerifier;
use App\Services\Payment\StripeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Traits\ApiResponse;

/**
 * Server-to-server payment webhooks (Stripe + PayPal).
 *
 * Thin by design: signature verification first, then transaction lookup,
 * then the canonical PaymentCompletionService::completeLocked under row
 * locks — the same guards as the browser callbacks in OrderController
 * (idempotency token, order-pending, amount x1000, currency, provider ref).
 *
 * MyFatoorah has NO webhook endpoint on purpose: it is a browser-callback
 * only integration (the shopper returns to checkout/callback with the
 * paymentId and the server re-verifies via checkInvoice). There is no
 * provider-signed server event to consume, so adding an unsigned webhook
 * URL would only widen the attack surface.
 *
 * Security: Stripe is verified from the RAW body (never parsed JSON)
 * against the config webhook_secret; PayPal via the SDK
 * verify-webhook-signature check against the config webhook_id. Logs carry
 * order/transaction/gateway/event/result only — never secrets, PII, or
 * payload dumps. Stored gateway_response entries stay on the gateway
 * allowlists plus the _webhook_event_ids dedupe list (capped, pruned).
 */
class PaymentWebhookController extends Controller
{
    use ApiResponse;

    private const WEBHOOK_EVENT_IDS_KEY = '_webhook_event_ids';

    private const WEBHOOK_EVENT_IDS_CAP = 20;

    public function __construct(
        private PaymentGatewayFactory $gatewayFactory,
        private PaymentCompletionService $completionService,
        private StripeWebhookVerifier $stripeVerifier,
        private PayPalWebhookVerifier $paypalVerifier,
        private \App\Services\Payment\PaymentRefundService $refundService,
    ) {}

    // -----------------------------------------------------------------
    // Stripe
    // -----------------------------------------------------------------

    public function stripe(Request $request): JsonResponse
    {
        // Raw body FIRST: verification must run on the exact bytes Stripe
        // signed, never on re-encoded parsed JSON.
        $raw = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secret = trim((string) config('payment.gateways.stripe.webhook_secret', ''));

        if (!is_string($raw) || $raw === '' || $signature === '' || $secret === '') {
            Log::warning('Stripe webhook rejected: unsigned or misconfigured', ['gateway' => 'stripe']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        try {
            $event = $this->stripeVerifier->construct($raw, $signature, $secret);
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook rejected: invalid signature', ['gateway' => 'stripe']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        $type = $this->stripeType($event);
        $eventId = $this->stripeEventId($event);

        if ($type === 'checkout.session.completed') {
            $sessionId = $this->stripeObjectId($event);

            if ($sessionId === '') {
                Log::warning('Stripe webhook rejected: missing session reference', [
                    'gateway' => 'stripe',
                    'event' => $type,
                ]);

                return $this->apiResponse(INVALID_PAYMENT_ID, 400, false);
            }

            return $this->completeFromProviderRef('stripe', $sessionId, $eventId, $type);
        }

        if ($type === 'payment_intent.payment_failed') {
            $paymentIntentId = $this->stripeObjectId($event);

            if ($paymentIntentId === '') {
                Log::warning('Stripe webhook rejected: missing payment-intent reference', [
                    'gateway' => 'stripe',
                    'event' => $type,
                ]);

                return $this->apiResponse(INVALID_PAYMENT_ID, 400, false);
            }

            // Transactions store the cs_* session id, not the pi_* id carried
            // by failure events. Prefer the persisted _payment_intent_id
            // correlation key, then resolve the owning session via the Stripe
            // API. Unresolvable → ack 200 (documented): nothing locally
            // correlates, so provider retries would only repeat the dead end.
            $failureRef = $paymentIntentId;

            if ($this->findTxnByRef($paymentIntentId) === null) {
                $resolvedSessionId = $this->resolveStripeSessionForIntent($paymentIntentId);

                if ($resolvedSessionId === null) {
                    Log::info('Stripe payment_failed unresolvable: no session for payment intent', [
                        'gateway' => 'stripe',
                        'event' => $type,
                        'result' => 'unknown_transaction',
                    ]);

                    return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
                }

                $failureRef = $resolvedSessionId;
            }

            return $this->markFailedByProviderRef('stripe', $failureRef, $eventId, $type, 'failed');
        }

        // NOTE (charge.refunded gap): Stripe refund webhooks (charge.refunded
        // and refund.* events) are intentionally NOT handled here — refunds
        // are initiated from the admin API (PaymentRefundService) and
        // reconciled there. A provider-side refund with no local ledger entry
        // lands below as "unsupported event type" (200 ignored) and is
        // reported for manual reconciliation instead of mutating state.

        Log::info('Stripe webhook ignored: unsupported event type', [
            'gateway' => 'stripe',
            'event' => $type,
            'result' => 'ignored',
        ]);

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
    }

    // -----------------------------------------------------------------
    // PayPal
    // -----------------------------------------------------------------

    public function paypal(Request $request): JsonResponse
    {
        $webhookId = trim((string) config('payment.gateways.paypal.webhook_id', ''));

        if ($webhookId === '') {
            Log::warning('PayPal webhook rejected: webhook_id misconfigured', ['gateway' => 'paypal']);

            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 503, false);
        }

        $raw = $request->getContent();

        if (!is_string($raw) || $raw === '') {
            Log::warning('PayPal webhook rejected: empty body', ['gateway' => 'paypal']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        $body = json_decode($raw, true);

        if (!is_array($body)) {
            Log::warning('PayPal webhook rejected: invalid body', ['gateway' => 'paypal']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        $transmission = [
            'auth_algo' => trim((string) $request->header('Paypal-Auth-Algo', '')),
            'cert_url' => trim((string) $request->header('Paypal-Cert-Url', '')),
            'transmission_id' => trim((string) $request->header('Paypal-Transmission-Id', '')),
            'transmission_sig' => trim((string) $request->header('Paypal-Transmission-Sig', '')),
            'transmission_time' => trim((string) $request->header('Paypal-Transmission-Time', '')),
        ];

        foreach ($transmission as $value) {
            if ($value === '') {
                Log::warning('PayPal webhook rejected: unsigned', ['gateway' => 'paypal']);

                return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
            }
        }

        try {
            $verified = $this->paypalVerifier->verify($transmission, $body);
        } catch (\Throwable $e) {
            Log::warning('PayPal webhook rejected: verification error', [
                'gateway' => 'paypal',
                'result' => 'unverified',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 401, false);
        }

        if (!$verified) {
            Log::warning('PayPal webhook rejected: signature mismatch', [
                'gateway' => 'paypal',
                'result' => 'unverified',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 401, false);
        }

        $eventType = strtoupper(trim((string) ($body['event_type'] ?? '')));
        $eventId = isset($body['id']) && is_string($body['id']) && trim($body['id']) !== ''
            ? trim($body['id'])
            : null;

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $ref = $this->paypalTxnRef($body);

            if ($ref === null) {
                Log::info('Payment webhook for unknown transaction', [
                    'gateway' => 'paypal',
                    'event' => $eventType,
                    'result' => 'unknown_transaction',
                ]);

                return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
            }

            return $this->completeFromProviderRef('paypal', $ref, $eventId, $eventType);
        }

        if ($eventType === 'PAYMENT.CAPTURE.DENIED') {
            return $this->failPayPalEvent($body, $eventId, $eventType, 'failed');
        }

        if (in_array($eventType, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
            return $this->recordPayPalExternalRefund($body, $eventId, $eventType);
        }

        Log::info('PayPal webhook ignored: unsupported event type', [
            'gateway' => 'paypal',
            'event' => $eventType,
            'result' => 'ignored',
        ]);

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
    }

    // -----------------------------------------------------------------
    // Shared completion path (mirrors OrderController callbacks)
    // -----------------------------------------------------------------

    /**
     * Provider-verified paid signal → gateway re-verification → canonical
     * locked completion. Unknown references ack 200 (never 500) so the
     * provider stops retrying a payment we cannot correlate.
     */
    private function completeFromProviderRef(
        string $gateway,
        string $ref,
        ?string $eventId,
        string $eventType,
    ): JsonResponse {
        $probe = $this->findTxnByRef($ref);

        if (!$probe) {
            Log::info('Payment webhook for unknown transaction', [
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        if (($probe->payment_method ?? '') !== $gateway) {
            Log::warning('Payment webhook rejected: gateway mismatch', [
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'rejected',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_METHOD, 400, false);
        }

        $orderId = $probe->order_id;
        $txnId = $probe->id;

        // Cheap pre-verify replay ack: the same event id was already
        // recorded on this row. The locked section re-checks under
        // lockForUpdate, so concurrent duplicates stay safe.
        if ($this->hasWebhookEvent($probe, $eventId)) {
            Log::info('Payment webhook ignored', [
                'order_id' => $orderId,
                'transaction_id' => $txnId,
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'duplicate',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        try {
            // Verify path goes through the factory (which delegates to the
            // registry): disabled gateways still verify existing payments,
            // same as the browser callbacks.
            $adapter = $this->gatewayFactory->make($gateway);
        } catch (UnsupportedGatewayException $e) {
            Log::warning('Payment webhook rejected: unsupported gateway', [
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'rejected',
            ]);

            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 500, false);
        }

        $result = $adapter->verifyPayment($ref);

        if (!$result->success) {
            return $this->markFailedWithResult($txnId, $gateway, $eventId, $eventType, $result);
        }

        $verifiedRef = is_string($result->gatewayTransactionId) && trim($result->gatewayTransactionId) !== ''
            ? trim($result->gatewayTransactionId)
            : $ref;

        $terminal = 'completed';

        try {
            DB::transaction(function () use (
                $ref,
                $verifiedRef,
                $gateway,
                $eventId,
                $eventType,
                $result,
                &$terminal,
            ) {
                $lt = $this->findTxnByRef($ref, true);

                if (!$lt) {
                    $lt = $this->findTxnByRef($verifiedRef, true);
                }

                if (!$lt) {
                    $terminal = 'unknown';

                    return;
                }

                if ($this->hasWebhookEvent($lt, $eventId)) {
                    $terminal = 'duplicate';

                    return;
                }

                // Pre-completion dedupe history: commitLocked replaces
                // gateway_response with the allowlisted verify payload, so
                // the ids are re-attached after a Processed outcome below.
                $priorIds = $this->webhookEventIds($lt);

                if (is_string($eventId) && $eventId !== '' && !in_array($eventId, $priorIds, true)) {
                    $priorIds[] = $eventId;
                }

                $priorIds = array_slice(array_values($priorIds), -self::WEBHOOK_EVENT_IDS_CAP);

                $lockedOrder = $lt->order()->lockForUpdate()->first();

                try {
                    $outcome = $this->completionService->completeLocked($lt, $lockedOrder, $result);
                } catch (PaymentMismatchException $e) {
                    // Fail-closed marking inside the same lock, mirroring the
                    // callbacks: the service kept only the idempotency token.
                    Log::warning('Payment webhook mismatch - blocking order', [
                        'transaction_id' => $lt->id,
                        'order_id' => $lockedOrder?->id,
                        'gateway' => $gateway,
                        'event' => $eventType,
                        'result' => 'mismatch',
                    ]);
                    $merged = is_array($result->rawResponse) ? $result->rawResponse : [];
                    $merged[self::WEBHOOK_EVENT_IDS_KEY] = $priorIds;
                    $lt->update([
                        'status' => 'failed',
                        'gateway_response' => $merged,
                        'error_message' => Str::limit(strip_tags((string) ($result->errorMessage ?? 'Amount or currency mismatch')), 500, ''),
                    ]);
                    $terminal = 'mismatch';

                    return;
                }

                if ($outcome === PaymentCompletionOutcome::Processed) {
                    $current = is_array($lt->gateway_response) ? $lt->gateway_response : [];
                    $current[self::WEBHOOK_EVENT_IDS_KEY] = $priorIds;
                    $lt->update(['gateway_response' => $current]);
                    $terminal = 'completed';
                } elseif ($outcome === PaymentCompletionOutcome::IdempotentReplay) {
                    $terminal = 'duplicate';
                } else {
                    // OrderNotPending / DuplicateHold / UnknownOrder: record
                    // the event id so a replay of THIS event stays a cheap
                    // duplicate instead of re-recording reconciliation rows.
                    $current = is_array($lt->gateway_response) ? $lt->gateway_response : [];
                    $current[self::WEBHOOK_EVENT_IDS_KEY] = $priorIds;
                    $lt->update(['gateway_response' => $current]);
                    $terminal = 'ignored';
                }
            });
        } catch (\App\Exceptions\CouponConsumptionException $e) {
            return $this->markCouponBlocked($ref, $verifiedRef, $gateway, $eventId, $eventType, $e);
        }

        $order = $orderId ? Order::query()->find($orderId) : null;

        if ($terminal === 'completed') {
            // F-14: never dispatch PaymentSucceeded with a missing order.
            try {
                $fresh = $order?->fresh();
                if ($fresh) {
                    event(new PaymentSucceeded($fresh));
                } else {
                    Log::warning('PaymentSucceeded skipped: order missing in webhook', [
                        'transaction_id' => $txnId,
                        'gateway' => $gateway,
                        'event' => $eventType,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            Log::info('Payment webhook completed', [
                'order_id' => $orderId,
                'transaction_id' => $txnId,
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'completed',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'completed']);
        }

        if ($terminal === 'mismatch') {
            try {
                if ($order) {
                    event(new PaymentFailed($order));
                }
            } catch (\Throwable $e) {
                report($e);
            }

            Log::info('Payment webhook mismatch recorded', [
                'order_id' => $orderId,
                'transaction_id' => $txnId,
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'mismatch',
            ]);

            // Terminal ack (fail-safe): the mismatch is recorded visibly;
            // non-2xx would only trigger provider retries of a dead end.
            return $this->apiResponse(PAYMENT_FAILED, 200, false, ['status' => 'failed']);
        }

        Log::info('Payment webhook ignored', [
            'order_id' => $orderId,
            'transaction_id' => $txnId,
            'gateway' => $gateway,
            'event' => $eventType,
            'result' => $terminal,
        ]);

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
    }

    /**
     * Terminal failure signal for a known transaction row (Stripe
     * payment_intent.payment_failed, PayPal DENIED/REFUNDED/REVERSED):
     * pending rows move to failed/refunded, anything else is ignored.
     */
    private function markFailedByProviderRef(
        string $gateway,
        string $ref,
        ?string $eventId,
        string $eventType,
        string $status,
    ): JsonResponse {
        $probe = $this->findTxnByRef($ref);

        if (!$probe) {
            Log::info('Payment webhook for unknown transaction', [
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        if (($probe->payment_method ?? '') !== $gateway) {
            Log::warning('Payment webhook rejected: gateway mismatch', [
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'rejected',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_METHOD, 400, false);
        }

        $txnId = $probe->id;
        $orderId = $probe->order_id;
        $marked = false;

        // Cheap pre-lock replay ack (re-checked under lockForUpdate below).
        if ($this->hasWebhookEvent($probe, $eventId)) {
            Log::info('Payment webhook ignored', [
                'order_id' => $orderId,
                'transaction_id' => $txnId,
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'duplicate',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        DB::transaction(function () use ($txnId, $eventId, $status, &$marked) {
            $lt = Transaction::query()->whereKey($txnId)->lockForUpdate()->first();

            if (!$lt) {
                return;
            }

            if ($this->hasWebhookEvent($lt, $eventId)) {
                return;
            }

            $merged = $this->mergeWebhookEvent(
                is_array($lt->gateway_response) ? $lt->gateway_response : [],
                $lt,
                $eventId,
            );

            if ($lt->status !== 'pending') {
                // Record the dedupe id, leave terminal state untouched.
                $lt->update(['gateway_response' => $merged]);

                return;
            }

            $lt->update([
                'status' => $status,
                'gateway_response' => $merged,
                'error_message' => Str::limit(strip_tags($status === 'refunded' ? 'Payment refunded by gateway' : 'Payment failed at gateway'), 500, ''),
            ]);
            $marked = true;
        });

        if ($marked) {
            $order = $orderId ? Order::query()->find($orderId) : null;

            try {
                if ($order) {
                    event(new PaymentFailed($order));
                }
            } catch (\Throwable $e) {
                report($e);
            }

            Log::info('Payment webhook marked failed', [
                'order_id' => $orderId,
                'transaction_id' => $txnId,
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => $status,
            ]);

            return $this->apiResponse(PAYMENT_FAILED, 200, false, ['status' => 'failed']);
        }

        Log::info('Payment webhook ignored', [
            'order_id' => $orderId,
            'transaction_id' => $txnId,
            'gateway' => $gateway,
            'event' => $eventType,
            'result' => 'ignored',
        ]);

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
    }

    /**
     * Gateway re-verification said "not paid": fail the pending row visibly
     * (same as the callbacks) and ack 200 so the provider stops retrying.
     */
    private function markFailedWithResult(
        int $txnId,
        string $gateway,
        ?string $eventId,
        string $eventType,
        GatewayResult $result,
    ): JsonResponse {
        $orderId = null;

        DB::transaction(function () use ($txnId, $eventId, $eventType, $result, &$orderId) {
            $lt = Transaction::query()->whereKey($txnId)->lockForUpdate()->first();

            if (!$lt) {
                return;
            }

            if ($this->hasWebhookEvent($lt, $eventId)) {
                $orderId = $lt->order_id;

                return;
            }

            $orderId = $lt->order_id;

            if ($lt->status !== 'pending') {
                $lt->update([
                    'gateway_response' => $this->mergeWebhookEvent(
                        is_array($lt->gateway_response) ? $lt->gateway_response : [],
                        $lt,
                        $eventId,
                    ),
                ]);

                return;
            }

            $lt->update([
                'status' => $result->status ?? 'failed',
                'gateway_response' => $this->mergeWebhookEvent(
                    is_array($result->rawResponse) ? $result->rawResponse : [],
                    $lt,
                    $eventId,
                ),
                'error_message' => Str::limit(strip_tags((string) ($result->errorMessage ?? 'Payment failed')), 500, ''),
            ]);
        });

        $order = $orderId ? Order::query()->find($orderId) : null;

        try {
            if ($order) {
                event(new PaymentFailed($order));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        Log::info('Payment webhook verification failed', [
            'order_id' => $orderId,
            'transaction_id' => $txnId,
            'gateway' => $gateway,
            'event' => $eventType,
            'result' => 'failed',
        ]);

        return $this->apiResponse(PAYMENT_FAILED, 200, false, ['status' => 'failed']);
    }

    /**
     * Coupon policy refused completion (fail-closed): the completion
     * transaction rolled back, so record the failure visibly with a rotated
     * token — mirroring the callbacks — and ack 200.
     */
    private function markCouponBlocked(
        string $ref,
        string $verifiedRef,
        string $gateway,
        ?string $eventId,
        string $eventType,
        \App\Exceptions\CouponConsumptionException $e,
    ): JsonResponse {
        $orderId = null;
        $txnId = null;

        try {
            DB::transaction(function () use ($ref, $verifiedRef, $eventId, $e, &$orderId, &$txnId) {
                $lt = $this->findTxnByRef($ref, true);

                if (!$lt) {
                    $lt = $this->findTxnByRef($verifiedRef, true);
                }

                if (!$lt || $lt->status !== 'pending') {
                    return;
                }

                $txnId = $lt->id;
                $orderId = $lt->order_id;

                $merged = is_array($lt->gateway_response) ? $lt->gateway_response : [];
                $merged['_coupon_blocked_at'] = now()->toIso8601String();
                $merged['_coupon_blocked_reason'] = Str::limit(strip_tags($e->getMessage()), 500, '');
                $merged = $this->mergeWebhookEvent($merged, $lt, $eventId);

                $lt->update([
                    'status' => 'failed',
                    'idempotency_key' => null,
                    'gateway_response' => $merged,
                    'error_message' => Str::limit(strip_tags($e->getMessage()), 500, ''),
                ]);
            });
        } catch (\Throwable $ex) {
            report($ex);
        }

        $order = $orderId ? Order::query()->find($orderId) : null;

        try {
            if ($order) {
                $fresh = $order->fresh();

                event(new PaymentFailed($fresh ?? $order));
            }
        } catch (\Throwable $ex) {
            report($ex);
        }

        Log::info('Payment webhook coupon-blocked', [
            'order_id' => $orderId,
            'transaction_id' => $txnId,
            'gateway' => $gateway,
            'event' => $eventType,
            'result' => 'coupon_blocked',
        ]);

        return $this->apiResponse(PAYMENT_FAILED, 200, false, ['status' => 'failed']);
    }

    private function failPayPalEvent(array $body, ?string $eventId, string $eventType, string $status): JsonResponse
    {
        $ref = $this->paypalTxnRef($body);

        if ($ref === null) {
            Log::info('Payment webhook for unknown transaction', [
                'gateway' => 'paypal',
                'event' => $eventType,
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        return $this->markFailedByProviderRef('paypal', $ref, $eventId, $eventType, $status);
    }

    /**
     * Provider-initiated PayPal refund (dashboard refund outside the admin
     * API): append the _refunds ledger row, move txn to refunded/partial,
     * and on full refunds apply the same order side effects as a manual full
     * refund (payment-refunded + inventory restore + coupon release) via
     * PaymentRefundService::recordExternalRefund. Idempotent by provider
     * event id; uncorrelatable or invalid payloads ack 200 (fail-safe).
     */
    private function recordPayPalExternalRefund(array $body, ?string $eventId, string $eventType): JsonResponse
    {
        $ref = $this->paypalTxnRef($body);

        if ($ref === null) {
            Log::info('Payment webhook for unknown transaction', [
                'gateway' => 'paypal',
                'event' => $eventType,
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        $resource = $body['resource'] ?? null;
        $amountData = is_array($resource) ? ($resource['amount'] ?? null) : null;
        $refundAmount = is_array($amountData) && isset($amountData['value']) ? (float) $amountData['value'] : null;
        $refundCurrency = is_array($amountData) && isset($amountData['currency_code'])
            ? strtoupper(trim((string) $amountData['currency_code']))
            : null;
        $providerRef = is_array($resource) && isset($resource['id']) && is_string($resource['id'])
            ? trim($resource['id'])
            : '';
        $dedupeId = $eventId ?? ($providerRef !== '' ? 'refund:'.$providerRef : null);

        if ($refundAmount === null || $refundAmount <= 0 || $refundCurrency === null || $refundCurrency === '' || $dedupeId === null) {
            Log::warning('PayPal external refund ignored: invalid payload', [
                'gateway' => 'paypal',
                'event' => $eventType,
                'result' => 'ignored',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        $summary = null;

        try {
            DB::transaction(function () use ($ref, $providerRef, $refundAmount, $refundCurrency, $dedupeId, &$summary) {
                $lt = $this->findTxnByRef($ref, true);

                if (!$lt || ($lt->payment_method ?? '') !== 'paypal') {
                    return;
                }

                $lockedOrder = $lt->order()->lockForUpdate()->first();

                if (!$lockedOrder) {
                    return;
                }

                $summary = $this->refundService->recordExternalRefund(
                    $lockedOrder,
                    $lt,
                    $providerRef,
                    $refundAmount,
                    $refundCurrency,
                    $dedupeId,
                );
            });
        } catch (\RuntimeException $e) {
            Log::warning('PayPal external refund rejected', [
                'gateway' => 'paypal',
                'event' => $eventType,
                'result' => 'rejected',
                'reason' => $e->getMessage(),
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        if ($summary === null) {
            Log::info('Payment webhook for unknown transaction', [
                'gateway' => 'paypal',
                'event' => $eventType,
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        Log::info('PayPal external refund recorded', [
            'order_id' => $summary['order_id'],
            'transaction_id' => $summary['transaction_id'],
            'gateway' => 'paypal',
            'event' => $eventType,
            'result' => !empty($summary['idempotent_replay']) ? 'duplicate' : 'refunded',
        ]);

        return $this->apiResponse(
            PAYMENT_SUCCESSFUL,
            200,
            true,
            ['status' => !empty($summary['idempotent_replay']) ? 'duplicate' : 'refunded']
        );
    }

    // -----------------------------------------------------------------
    // Lookup + dedupe helpers
    // -----------------------------------------------------------------

    private function findTxnByRef(string $ref, bool $locked = false): ?Transaction
    {
        // The third predicate is the _payment_intent_id correlation key the
        // Stripe adapter persists at verify time: failure webhooks carry only
        // pi_* while the row stores cs_*.
        $query = Transaction::where('gateway_transaction_id', $ref)
            ->orWhere('invoice_id', $ref)
            ->orWhere('gateway_response->_payment_intent_id', $ref);

        if ($locked) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Resolve the Checkout Session id owning a PaymentIntent via the Stripe
     * adapter (sessions->all filtered by payment_intent). Null when the
     * gateway is unresolvable, the adapter is mocked without that method, or
     * the API reports nothing — every failure collapses to "unresolvable".
     */
    private function resolveStripeSessionForIntent(string $paymentIntentId): ?string
    {
        try {
            $adapter = $this->gatewayFactory->make('stripe');
        } catch (\Throwable $e) {
            return null;
        }

        if (!$adapter instanceof \App\Services\Gateway\StripeGateway) {
            return null;
        }

        try {
            return $adapter->findSessionIdByPaymentIntent($paymentIntentId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Processed provider event ids live on the transaction row (capped,
     * pruned) so replays of the same event ack cheaply without touching
     * completion state or re-recording reconciliation rows.
     *
     * @return list<string>
     */
    private function webhookEventIds(Transaction $txn): array
    {
        $response = is_array($txn->gateway_response) ? $txn->gateway_response : [];
        $ids = $response[self::WEBHOOK_EVENT_IDS_KEY] ?? [];

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $id): string => is_string($id) ? $id : (string) $id,
            array_filter($ids, fn (mixed $id): bool => is_string($id) || is_int($id)),
        ), fn (string $id): bool => $id !== ''));
    }

    private function hasWebhookEvent(Transaction $txn, ?string $eventId): bool
    {
        if ($eventId === null || $eventId === '') {
            return false;
        }

        return in_array($eventId, $this->webhookEventIds($txn), true);
    }

    /**
     * @param array<string, mixed> $base allowlisted provider payload to keep.
     *
     * @return array<string, mixed>
     */
    private function mergeWebhookEvent(array $base, Transaction $txn, ?string $eventId): array
    {
        $ids = $this->webhookEventIds($txn);

        if (is_string($eventId) && $eventId !== '' && !in_array($eventId, $ids, true)) {
            $ids[] = $eventId;
        }

        $base[self::WEBHOOK_EVENT_IDS_KEY] = array_slice(array_values($ids), -self::WEBHOOK_EVENT_IDS_CAP);

        return $base;
    }

    // -----------------------------------------------------------------
    // Stripe event accessors (SDK objects or plain arrays)
    // -----------------------------------------------------------------

    private function stripeType(object|array $event): string
    {
        $type = is_object($event) ? ($event->type ?? null) : ($event['type'] ?? null);

        return is_string($type) ? $type : '';
    }

    private function stripeEventId(object|array $event): ?string
    {
        $id = is_object($event) ? ($event->id ?? null) : ($event['id'] ?? null);

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    private function stripeObjectId(object|array $event): string
    {
        $data = is_object($event) ? ($event->data ?? null) : ($event['data'] ?? null);
        $object = is_object($data) ? ($data->object ?? null) : (is_array($data) ? ($data['object'] ?? null) : null);
        $id = is_object($object) ? ($object->id ?? null) : (is_array($object) ? ($object['id'] ?? null) : null);

        return is_string($id) ? trim($id) : '';
    }

    // -----------------------------------------------------------------
    // PayPal resource accessors
    // -----------------------------------------------------------------

    /**
     * Resolve the local transaction reference from a PayPal webhook
     * resource, preferring the PayPal order id linked to the capture.
     * Returns null when nothing correlates (caller acks 200, fail-safe).
     */
    private function paypalTxnRef(array $body): ?string
    {
        $resource = $body['resource'] ?? null;

        if (!is_array($resource)) {
            return null;
        }

        $relatedOrderId = null;
        $supplementary = $resource['supplementary_data'] ?? null;
        if (is_array($supplementary) && is_array($supplementary['related_ids'] ?? null)) {
            $relatedOrderId = $supplementary['related_ids']['order_id'] ?? null;
        }

        foreach ([$relatedOrderId, $resource['invoice_id'] ?? null, $resource['custom_id'] ?? null, $resource['id'] ?? null] as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '' || strlen(trim($candidate)) > 191) {
                continue;
            }

            if ($this->findTxnByRef(trim($candidate)) !== null) {
                return trim($candidate);
            }
        }

        return null;
    }
}

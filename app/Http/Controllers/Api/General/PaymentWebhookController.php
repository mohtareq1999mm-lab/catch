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
use App\Services\Payment\TapWebhookVerifier;
use App\Services\Payment\TabbyWebhookVerifier;
use App\Services\Payment\TamaraWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Traits\ApiResponse;

/**
 * Server-to-server payment webhooks (Stripe + PayPal + Tap + Tabby).
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
 * verify-webhook-signature check against the config webhook_id; Tap via
 * its OFFICIAL documented hashstring HMAC-SHA256 mechanism
 * (developers.tap.company/docs/webhook) with the SAME Secret API Key
 * used for charges. Logs carry
 * order/transaction/gateway/event/result only — never secrets, PII, or
 * payload dumps. Stored gateway_response entries stay on the gateway
 * allowlists plus the _webhook_event_ids dedupe list (capped, pruned).
 */
class PaymentWebhookController extends Controller
{
    use ApiResponse;

    private const WEBHOOK_EVENT_IDS_KEY = '_webhook_event_ids';

    private const WEBHOOK_EVENT_IDS_CAP = 20;

    /**
     * Tap statuses treated as terminal failures (charges doc: CAPTURED =
     * charged; every other listed status is "Payment failed"). Verified
     * hashstring is the trust anchor — mirroring Stripe/PayPal, failure
     * marking does NOT re-verify with the provider.
     */
    private const TAP_TERMINAL_FAILURES = [
        'FAILED', 'DECLINED', 'CANCELLED', 'TIMEDOUT', 'ABANDONED', 'RESTRICTED', 'VOID', 'UNKNOWN',
    ];

    /**
     * Tabby webhook statuses (webhooks use LOWERCASE statuses — docs
     * webhooks guide) whose OUTCOME is arbitrated exclusively by the
     * adapter's fresh server-side read (verifyPayment). The delivered
     * payload status is a wake-up only — never a decision — so a stale
     * terminal-failure delivery (rejected/expired) cannot terminally
     * fail money the provider already captured, ambiguous fresh reads
     * keep the row completable, and confirmed terminal provider states
     * still fail visibly. Everything else (created / update / unknown)
     * is non-terminal and ack-ignored.
     */
    private const TABBY_FRESH_READ_STATUSES = ['authorized', 'closed', 'rejected', 'expired'];

    public function __construct(
        private PaymentGatewayFactory $gatewayFactory,
        private PaymentCompletionService $completionService,
        private StripeWebhookVerifier $stripeVerifier,
        private PayPalWebhookVerifier $paypalVerifier,
        private TapWebhookVerifier $tapVerifier,
        private TabbyWebhookVerifier $tabbyVerifier,
        private TamaraWebhookVerifier $tamaraVerifier,
        private \App\Services\Payment\PaymentRefundService $refundService,
        private \App\Services\Payment\WebhookEventInbox $inbox,
        private \App\Services\Payment\PaymentAttemptLifecycleService $lifecycle,
    ) {}

    /**
     * PM-1 schema guard (repo precedent: CancelUnpaidOrders payment_status
     * guard). Legacy-shaped test databases without the attempt columns keep
     * byte-identical resolution behavior; production always has them.
     */
    private function attemptColumnsAvailable(): bool
    {
        static $cached = null;

        return $cached ??= \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'attempt_state')
            && \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'dispatch_reference');
    }

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
        $account = (string) config('payment.gateways.stripe.account', 'default');
        $objectRef = $this->stripeObjectId($event);

        // W-1 / Constraint C: durably persist the AUTHENTICATED event before
        // any processing; acknowledge only after durable acceptance.
        $stored = $this->inbox->store('stripe', (string) $eventId, $account, $type, $objectRef, null, [
            'event_type' => $type,
            'provider_ref' => $objectRef,
        ]);

        if ($stored === null) {
            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        try {
            $response = $this->routeStripeEvent($event, $type, $eventId);
        } catch (\Throwable $e) {
            $this->inbox->markFailed($stored->id, $e);

            throw $e;
        }

        $this->inbox->markProcessed($stored->id);

        return $response;
    }

    private function routeStripeEvent(object $event, string $type, ?string $eventId): JsonResponse
    {
        if ($type === 'checkout.session.completed') {
            $sessionId = $this->stripeObjectId($event);

            if ($sessionId === '') {
                Log::warning('Stripe webhook rejected: missing session reference', [
                    'gateway' => 'stripe',
                    'event' => $type,
                ]);

                return $this->apiResponse(INVALID_PAYMENT_ID, 400, false);
            }

            return $this->completeFromProviderRef(
                'stripe',
                $sessionId,
                $eventId,
                $type,
                $this->stripeAttemptRef($event)
            );
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

    /** PM-6: the per-attempt dispatch reference echoed in session metadata. */
    private function stripeAttemptRef(object $event): ?string
    {
        try {
            $attemptRef = data_get($event, 'data.object.metadata.attempt_ref');

            return is_string($attemptRef) && trim($attemptRef) !== '' ? trim($attemptRef) : null;
        } catch (\Throwable $e) {
            return null;
        }
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

        // W-1 / Constraint C: durably persist the AUTHENTICATED event before
        // any processing; ack only after durable acceptance. A duplicate is
        // acknowledged without reprocessing (financial idempotency lives in
        // completeLocked regardless).
        $account = (string) config('payment.gateways.paypal.account', 'default');
        $providerRef = $this->paypalTxnRef($body);

        $stored = $this->inbox->store('paypal', (string) $eventId, $account, $eventType, $providerRef, null, [
            'event_type' => $eventType,
            'provider_ref' => $providerRef,
        ]);

        if ($stored === null) {
            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        try {
            $response = $this->routePayPalEvent($body, $eventId, $eventType);
        } catch (\Throwable $e) {
            $this->inbox->markFailed($stored->id, $e);

            throw $e;
        }

        $this->inbox->markProcessed($stored->id);

        return $response;
    }

    private function routePayPalEvent(array $body, ?string $eventId, string $eventType): JsonResponse
    {
        if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
            // PM-7: buyer approval — the webhook-driven capture trigger.
            return $this->handlePayPalApproved($body, $eventId, $eventType);
        }

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

    /**
     * PM-7 — Webhook-driven capture lifecycle for CHECKOUT.ORDER.APPROVED.
     *
     * Sequence: authenticated event → correlate (PM-6: custom_id echoes the
     * per-attempt dispatch reference) → guards (row + gateway + order
     * payable + dedupe) → authoritative probe (READ-ONLY; capture only when
     * the provider itself reports APPROVED) → durable capture intent →
     * atomic lease claim → capturePayment (EXPLICIT financial command,
     * idempotent capture-{id} key, NO database locks during I/O) → outcome
     * persisted → completion exclusively via completeLocked. Concurrent
     * webhook/worker races converge on ONE logical capture via the lease.
     */
    private function handlePayPalApproved(array $body, ?string $eventId, string $eventType): JsonResponse
    {
        $ref = $this->paypalTxnRef($body);

        if ($ref === null) {
            Log::info('PayPal APPROVED: no local correlation', [
                'gateway' => 'paypal',
                'event' => $eventType,
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        $txn = $this->findTxnByRef($ref);

        if (!$txn || ($txn->payment_method ?? '') !== 'paypal') {
            Log::info('PayPal APPROVED: unknown or mismatched transaction', [
                'gateway' => 'paypal',
                'event' => $eventType,
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        if ($this->hasWebhookEvent($txn, $eventId)) {
            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        $order = $txn->order;

        if (!$order || $order->status !== 'pending') {
            // SC-1 containment: never capture for a cancelled/superseded
            // order; if the session later collects, it lands as a
            // DuplicateHold + refund-investigation case.
            Log::info('PayPal APPROVED ignored: order no longer payable', [
                'transaction_id' => $txn->id,
                'order_id' => $txn->order_id,
                'result' => 'superseded_or_cancelled',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        try {
            $adapter = $this->gatewayFactory->make('paypal');
        } catch (\Throwable $e) {
            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 500, false);
        }

        if (!$adapter instanceof \App\Services\Payment\Contracts\CapturingGatewayContract
            || !$adapter instanceof \App\Services\Payment\Contracts\ReadonlyPaymentProbeContract) {
            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 500, false);
        }

        $probe = $adapter->probePaymentStatus($ref);
        $providerStatus = is_array($probe->rawResponse)
            ? (string) ($probe->rawResponse['_probe_provider_status'] ?? '')
            : '';

        if ($probe->success && ($probe->status ?? null) === 'paid') {
            // Capture already happened elsewhere — converge idempotently.
            return $this->completeFromProviderRef('paypal', $ref, $eventId, $eventType);
        }

        if (!$probe->success && ($probe->status ?? null) === 'failed') {
            return $this->markFailedByProviderRef('paypal', $ref, $eventId, $eventType, 'failed');
        }

        if (!$probe->success || $providerStatus !== 'APPROVED') {
            Log::info('PayPal APPROVED probe inconclusive or not approved', [
                'transaction_id' => $txn->id,
                'result' => $probe->success ? ($providerStatus !== '' ? $providerStatus : 'pending') : 'inconclusive',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        // Durable capture intent + atomic ownership claim (lease-based).
        $this->lifecycle->requestCapture($txn);

        if (!$this->lifecycle->claimCapture($txn)) {
            // Another webhook/worker owns the logical capture. PayPal's own
            // retries plus the recovery worker provide completion.
            Log::info('PayPal capture claim lost: in-flight or already owned', [
                'transaction_id' => $txn->id,
                'result' => 'claim_lost',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'capture_in_progress']);
        }

        // Provider I/O with NO database transaction or row lock held.
        $captureResult = $adapter->capturePayment($ref);

        if ($captureResult->success && ($captureResult->status ?? null) === 'paid') {
            $this->lifecycle->persistCaptureOutcome($txn, 'succeeded', 'capture_response');

            return $this->completeFromProviderRef('paypal', $ref, $eventId, $eventType);
        }

        if (!$captureResult->success && ($captureResult->status ?? null) === 'failed') {
            // Capture may have moved money with mismatched totals: NOT a
            // confirmed failure — park as Unknown for probe arbitration and
            // ops investigation (never auto-fail captured money).
            $this->lifecycle->persistCaptureOutcome($txn, 'unknown', 'capture_mismatch');

            Log::warning('PayPal capture totals mismatch: investigation case', [
                'transaction_id' => $txn->id,
                'result' => 'capture_mismatch_investigation',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'capture_unknown']);
        }

        // Ambiguous capture outcome: reconcile via probe before any further
        // capture (recovery worker arbitrates COMPLETED vs APPROVED).
        $this->lifecycle->persistCaptureOutcome($txn, 'unknown', 'capture_ambiguous');

        Log::warning('PayPal capture ambiguous: parked for probe arbitration', [
            'transaction_id' => $txn->id,
            'result' => 'capture_unknown',
        ]);

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'capture_unknown']);
    }

    // -----------------------------------------------------------------
    // Tap
    // -----------------------------------------------------------------

    /**
     * Tap webhook (charge `post.url`): the raw charge object POSTed with
     * `hashstring` (and sometimes `hash`) headers.
     *
     * Verification follows Tap's OFFICIAL documented hashstring mechanism
     * (developers.tap.company/docs/webhook) — HMAC-SHA256 with the SAME
     * Secret API Key used for charges. The official examples show BOTH
     * headers with DIFFERENT values and only document `hashstring`, so the
     * documented header is preferred and `hash` is accepted only when
     * `hashstring` is absent (fail-closed: an unverifiable value is
     * rejected below regardless).
     *
     * Status mapping (charges doc: CAPTURED = charged, every other listed
     * status = "Payment failed"): CAPTURED → provider re-verification +
     * canonical locked completion; terminal failures → markFailedByProviderRef
     * (idempotency token preserved, so a later CAPTURED — e.g. an
     * out-of-order notification — can still complete); INITIATED and any
     * unrecognized status → 200 ignored (non-terminal, order stays pending).
     */
    public function tap(Request $request): JsonResponse
    {
        $secret = trim((string) config('payment.gateways.tap.secret_key', ''));

        if ($secret === '') {
            Log::warning('Tap webhook rejected: secret_key misconfigured', ['gateway' => 'tap']);

            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 503, false);
        }

        $raw = $request->getContent();

        if (!is_string($raw) || $raw === '') {
            Log::warning('Tap webhook rejected: empty body', ['gateway' => 'tap']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        $body = json_decode($raw, true);

        if (!is_array($body)) {
            Log::warning('Tap webhook rejected: invalid body', ['gateway' => 'tap']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        $postedHash = (string) ($request->header('hashstring', '') ?: $request->header('hash', ''));

        if ($postedHash === '') {
            Log::warning('Tap webhook rejected: unsigned', ['gateway' => 'tap']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        try {
            $verified = $this->tapVerifier->verify($body, $postedHash, $secret);
        } catch (\Throwable $e) {
            $verified = false;
        }

        if (!$verified) {
            Log::warning('Tap webhook rejected: hashstring mismatch', [
                'gateway' => 'tap',
                'result' => 'unverified',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 401, false);
        }

        $chargeId = is_string($body['id'] ?? null) ? trim((string) $body['id']) : '';
        $status = strtoupper(trim((string) ($body['status'] ?? '')));

        if ($chargeId === '' || strlen($chargeId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $chargeId)) {
            Log::warning('Tap webhook rejected: missing charge reference', ['gateway' => 'tap']);

            return $this->apiResponse(INVALID_PAYMENT_ID, 400, false);
        }

        // Tap webhooks carry NO event id — the dedupe id is synthesized from
        // the charge reference + status so replays of the same notification
        // ack cheaply (a replayed CAPTURED is also caught by the completion
        // idempotency token; a FAILED-then-CAPTURED sequence keeps distinct
        // ids and stays completable by design).
        $dedupeId = $chargeId . ':' . $status;

        $account = (string) config('payment.gateways.tap.account', 'default');
        $attemptRef = is_array($body['reference'] ?? null) && is_string($body['reference']['idempotent'] ?? null)
            ? trim((string) $body['reference']['idempotent'])
            : null;

        // W-1: no stable provider event id exists — scoped fingerprint over
        // verified identity attributes (charge id + status); distinct status
        // transitions never collapse, identical replays dedupe.
        $stored = $this->inbox->store(
            'tap',
            \App\Services\Payment\WebhookEventInbox::fingerprint('tap', $account, $chargeId, 'charge.' . $status, $status),
            $account,
            'charge.' . $status,
            $chargeId,
            $attemptRef,
            ['event_type' => 'charge.' . $status, 'provider_ref' => $chargeId, 'status' => $status],
        );

        if ($stored === null) {
            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        try {
            $response = $this->routeTapEvent($chargeId, $status, $dedupeId, $attemptRef);
        } catch (\Throwable $e) {
            $this->inbox->markFailed($stored->id, $e);

            throw $e;
        }

        $this->inbox->markProcessed($stored->id);

        return $response;
    }

    private function routeTapEvent(string $chargeId, string $status, string $dedupeId, ?string $attemptRef): JsonResponse
    {
        if ($status === 'CAPTURED') {
            return $this->completeFromProviderRef('tap', $chargeId, $dedupeId, 'charge.CAPTURED', $attemptRef);
        }

        if (in_array($status, self::TAP_TERMINAL_FAILURES, true)) {
            return $this->markFailedByProviderRef('tap', $chargeId, $dedupeId, 'charge.' . $status, 'failed', $attemptRef);
        }

        Log::info('Tap webhook ignored: non-terminal status', [
            'gateway' => 'tap',
            'status' => $status,
            'result' => 'ignored',
        ]);

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
    }

    // -----------------------------------------------------------------
    // Tabby
    // -----------------------------------------------------------------

    /**
     * Tabby webhook (self-service registered via POST /api/v1/webhooks
     * with a custom auth header Tabby echoes on every delivery — docs
     * tabby.ai webhooks guide, verified 2026-10).
     *
     * Verification = constant-time comparison of the registered header
     * VALUE (TabbyWebhookVerifier). The payload IS the payment object with
     * a LOWERCASE status; no event id exists — the dedupe id is
     * synthesized as "{paymentId}:{status}" (same as the Tap precedent),
     * so replayed deliveries ack cheaply and the completion idempotency
     * token stays the double-completion guard.
     *
     * NEVER trust the payload: every conclusive event routes through
     * completeFromProviderRef → TabbyGateway::verifyPayment → a fresh
     * server-side retrieve. A webhook `authorized` is not a capture
     * trigger (the capture attempt happens inside verifyPayment, guarded
     * by the constant capture reference_id); the docs "statuses only move
     * forward" rule is tolerated via fresh arbitration, and
     * markFailedByProviderRef only touches pending rows, so a concurrent
     * completion (out-of-order close-after-authorized) always wins.
     *
     * Unknown payment id → HTTP 404 — a DELIBERATE deviation from the
     * other gateways' 200-ignored, sanctioned by Tabby's own docs
     * ("respond with a non-200 status e.g. 404 ... Tabby treats it as a
     * delivery error and retries"): orders are always created BEFORE the
     * payment session in this repo (PaymentCheckoutHandler), so a real
     * Tabby payment must already exist locally; the retry window covers
     * racing commits and a persistent 404 is ops-visible truth.
     */
    public function tabby(Request $request): JsonResponse
    {
        $secret = trim((string) config('payment.gateways.tabby.webhook_secret', ''));
        $headerName = trim((string) config('payment.gateways.tabby.webhook_header', ''));

        if ($secret === '' || $headerName === '') {
            Log::warning('Tabby webhook rejected: webhook_secret/webhook_header misconfigured', ['gateway' => 'tabby']);

            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 503, false);
        }

        $raw = $request->getContent();

        if (!is_string($raw) || $raw === '') {
            Log::warning('Tabby webhook rejected: empty body', ['gateway' => 'tabby']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        $body = json_decode($raw, true);

        if (!is_array($body)) {
            Log::warning('Tabby webhook rejected: invalid body', ['gateway' => 'tabby']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        $received = (string) ($request->header($headerName, '') ?: '');

        if ($received === '' || !$this->tabbyVerifier->verify($received, $secret)) {
            Log::warning('Tabby webhook rejected: auth header missing or mismatched', [
                'gateway' => 'tabby',
                'result' => 'unverified',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 401, false);
        }

        $paymentId = is_string($body['id'] ?? null) ? trim((string) $body['id']) : '';
        $status = strtolower(trim((string) ($body['status'] ?? '')));

        if ($paymentId === '' || strlen($paymentId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $paymentId)) {
            Log::warning('Tabby webhook rejected: missing payment reference', ['gateway' => 'tabby']);

            return $this->apiResponse(INVALID_PAYMENT_ID, 400, false);
        }

        $attemptRef = is_array($body['order'] ?? null) && is_string($body['order']['reference_id'] ?? null)
            ? trim((string) $body['order']['reference_id'])
            : null;

        $probe = $this->findTxnByRef($paymentId);

        // PM-6 claimed-row fallback (capability-gated): the payment object
        // echoes order.reference_id — the per-attempt dispatch reference.
        if (!$probe && $attemptRef !== null
            && \App\Services\Payment\PaymentCapabilityRegistry::correlationActive('tabby')
        ) {
            $probe = $this->findTxnByRef($attemptRef);
        }

        if (!$probe) {
            Log::info('Tabby webhook for unknown transaction - retry requested', [
                'gateway' => 'tabby',
                'event' => 'payment.' . ($status !== '' ? $status : 'unknown'),
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        if (($probe->payment_method ?? '') !== 'tabby') {
            Log::warning('Tabby webhook rejected: gateway mismatch', [
                'gateway' => 'tabby',
                'event' => 'payment.' . ($status !== '' ? $status : 'unknown'),
                'result' => 'rejected',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_METHOD, 400, false);
        }

        $dedupeId = $paymentId . ':' . ($status !== '' ? $status : 'unknown');
        $eventType = 'payment.' . ($status !== '' ? $status : 'unknown');

        $account = (string) config('payment.gateways.tabby.account', 'default');

        // W-1: no stable provider event id — scoped fingerprint over verified
        // identity attributes. The refund-set signature is part of the
        // identity: DISTINCT refund deliveries of the same payment must NOT
        // collapse into one event (the per-refund ledger dedupe stays in
        // recordExternalRefund); replays of the same delivery do.
        $refundSignature = 'none';
        if (is_array($body['refunds'] ?? null)) {
            $refundSignature = (string) hash(
                'sha256',
                collect($body['refunds'])->pluck('id')->sort()->values()->implode('|')
            );
        }

        $stored = $this->inbox->store(
            'tabby',
            \App\Services\Payment\WebhookEventInbox::fingerprint('tabby', $account, $paymentId, $eventType, $status . '|' . $refundSignature),
            $account,
            $eventType,
            $paymentId,
            $attemptRef,
            ['event_type' => $eventType, 'provider_ref' => $paymentId, 'status' => $status],
        );

        if ($stored === null) {
            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        try {
            $response = $this->routeTabbyEvent($body, $paymentId, $status, $dedupeId, $eventType, $attemptRef);
        } catch (\Throwable $e) {
            $this->inbox->markFailed($stored->id, $e);

            throw $e;
        }

        $this->inbox->markProcessed($stored->id);

        return $response;
    }

    private function routeTabbyEvent(
        array $body,
        string $paymentId,
        string $status,
        string $dedupeId,
        string $eventType,
        ?string $attemptRef,
    ): JsonResponse {
        if (in_array($status, self::TABBY_FRESH_READ_STATUSES, true)) {
            // Single server-authoritative path for EVERY actionable
            // status. An `authorized` webhook whose captures[] is
            // pre-populated is a capture CONFIRMATION — handled inside
            // verifyPayment by the fresh read, never by a capture trigger
            // from the payload.
            $response = $this->completeFromProviderRef('tabby', $paymentId, $dedupeId, $eventType, $attemptRef);

            $this->reconcileTabbyRefunds($body, $paymentId, $eventType);

            return $response;
        }

        Log::info('Tabby webhook ignored: non-terminal status', [
            'gateway' => 'tabby',
            'status' => $status,
            'result' => 'ignored',
        ]);

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
    }

    /**
     * Provider-initiated Tabby refunds (merchant dashboard refunds):
     * append the _refunds ledger row and apply the same order side
     * effects as a manual refund via
     * PaymentRefundService::recordExternalRefund — idempotent per refund
     * id.
     *
     * Processed when the transaction row is locally refundable ('paid'
     * or 'partially_refunded' — the same set the manual refund path
     * works from, PaymentRefundService::refund()): the FIRST external
     * refund flips the row to partially_refunded, so gating on 'paid'
     * alone would permanently drop every LATER distinct refund webhook
     * (Tabby acks 200 and stops retrying). Replays stay idempotent via
     * the per-refund dedupe id, cumulative refunds cannot exceed the
     * captured amount (recordExternalRefund's remaining-amount guard),
     * and rows that never captured money locally (pending/failed) are
     * still deferred.
     */
    private function reconcileTabbyRefunds(array $body, string $paymentId, string $eventType): void
    {
        $refunds = is_array($body['refunds'] ?? null) ? $body['refunds'] : [];
        $currency = is_string($body['currency'] ?? null) ? strtoupper(trim((string) $body['currency'])) : null;

        if ($refunds === [] || $currency === null || $currency === '') {
            return;
        }

        $txn = $this->findTxnByRef($paymentId);

        if (!$txn || !in_array($txn->status, ['paid', 'partially_refunded'], true)) {
            Log::info('Tabby refunds skipped: payment row not locally refundable', [
                'gateway' => 'tabby',
                'event' => $eventType,
                'transaction_id' => $txn->id ?? null,
                'result' => 'skipped',
            ]);

            return;
        }

        foreach ($refunds as $refund) {
            if (!is_array($refund)) {
                continue;
            }

            $refundId = is_string($refund['id'] ?? null) ? trim((string) $refund['id']) : '';
            $refundAmount = isset($refund['amount']) && is_numeric($refund['amount']) ? (float) $refund['amount'] : null;

            if ($refundId === '' || $refundAmount === null || $refundAmount <= 0) {
                Log::warning('Tabby external refund ignored: invalid refund payload', [
                    'gateway' => 'tabby',
                    'event' => $eventType,
                    'result' => 'ignored',
                ]);

                continue;
            }

            $summary = null;

            try {
                DB::transaction(function () use ($paymentId, $refundId, $refundAmount, $currency, &$summary) {
                    $locked = $this->findTxnByRef($paymentId, true);

                    if (!$locked
                        || ($locked->payment_method ?? '') !== 'tabby'
                        || !in_array($locked->status, ['paid', 'partially_refunded'], true)
                    ) {
                        return;
                    }

                    $lockedOrder = $locked->order()->lockForUpdate()->first();

                    if (!$lockedOrder) {
                        return;
                    }

                    $summary = $this->refundService->recordExternalRefund(
                        $lockedOrder,
                        $locked,
                        $refundId,
                        $refundAmount,
                        $currency,
                        'tabby:refund:' . $locked->id . ':' . $refundId,
                    );
                });
            } catch (\RuntimeException $e) {
                Log::warning('Tabby external refund rejected', [
                    'gateway' => 'tabby',
                    'event' => $eventType,
                    'result' => 'rejected',
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            Log::info('Tabby external refund processed', [
                'gateway' => 'tabby',
                'event' => $eventType,
                'transaction_id' => $txn->id,
                'result' => $summary === null
                    ? 'unmatched'
                    : (!empty($summary['idempotent_replay']) ? 'duplicate' : 'refunded'),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Tamara (HS256 Notification-Token JWT — docs.tamara.co/docs/
    // transaction-authorisation, webhook registration in the partner
    // portal). tamaraToken rides BOTH the query string and the
    // Authorization: Bearer header; it is an HS256 JWT signed with the
    // merchant's Notification Token (SEPARATE credential from the API
    // Token). Delivery body: {order_id, order_reference_id, order_number,
    // event_type, data{}}. Tamara documents NO unique delivery id — the
    // dedupe id is synthesized per convention ({event_type}:{order_id}, +
    // :{capture_id} when the payload carries one) exactly as Tabby's
    // {paymentId}:{status} and never trusted as a decision: every
    // actionable family re-verifies server-side through the adapter.
    //
    // Event families are a WAKE-UP ROUTING decision only (payloads never
    // decide money state):
    //   completion (approved / authorised / captured) → shared
    //     completion path (fresh read: authorised+/captured family ⇒ the
    //     canonical completion runs EXACTLY ONCE; stale approved replays
    //     converge idempotently) + fresh-read capture-trail recording.
    //   refund → fresh-read refund reconcile (provider refund ids appended
    //     via recordExternalRefund under the txn→order lock order).
    //   terminated (canceled / declined / expired) → pending-row-only
    //     failure marking (paid rows are never touched).
    //   unknown → 200 ack (non-mutating).
    // -----------------------------------------------------------------

    public function tamara(Request $request): JsonResponse
    {
        $notificationToken = trim((string) config('payment.gateways.tamara.notification_token', ''));
        $apiToken = trim((string) config('payment.gateways.tamara.api_token', ''));

        if ($notificationToken === '' || $apiToken === '') {
            Log::warning('Tamara webhook rejected: api_token/notification_token misconfigured', ['gateway' => 'tamara']);

            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 503, false);
        }

        $raw = $request->getContent();

        if (!is_string($raw) || $raw === '') {
            Log::warning('Tamara webhook rejected: empty body', ['gateway' => 'tamara']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        $body = json_decode($raw, true);

        if (!is_array($body)) {
            Log::warning('Tamara webhook rejected: invalid body', ['gateway' => 'tamara']);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 400, false);
        }

        // Both documented channels; a double presence with conflicting
        // values fails closed inside the verifier.
        $bearer = $request->header('Authorization', '');

        if (is_string($bearer) && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $bearer, $bearerMatch)) {
            $bearer = $bearerMatch[1];
        } else {
            $bearer = '';
        }

        if (!$this->tamaraVerifier->verify($request->query('tamaraToken'), $bearer !== '' ? $bearer : null)) {
            Log::warning('Tamara webhook rejected: tamaraToken JWT missing or invalid', [
                'gateway' => 'tamara',
                'result' => 'unverified',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_RESPONSE, 401, false);
        }

        $orderId = is_string($body['order_id'] ?? null) ? trim((string) $body['order_id']) : '';
        $eventType = is_string($body['event_type'] ?? null) ? trim((string) $body['event_type']) : '';

        if ($orderId === '' || strlen($orderId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $orderId)) {
            Log::warning('Tamara webhook rejected: missing order reference', ['gateway' => 'tamara']);

            return $this->apiResponse(INVALID_PAYMENT_ID, 400, false);
        }

        $attemptRef = is_string($body['order_reference_id'] ?? null) ? trim((string) $body['order_reference_id']) : null;

        $probe = $this->findTxnByRef($orderId);

        // PM-6 claimed-row fallback (capability-gated): the delivery body
        // carries order_reference_id — the per-attempt dispatch reference.
        if (!$probe && $attemptRef !== null
            && \App\Services\Payment\PaymentCapabilityRegistry::correlationActive('tamara')
        ) {
            $probe = $this->findTxnByRef($attemptRef);
        }

        if (!$probe) {
            Log::info('Tamara webhook for unknown transaction - retry requested', [
                'gateway' => 'tamara',
                'event' => $eventType !== '' ? $eventType : 'unknown',
                'result' => 'unknown_transaction',
            ]);

            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        if (($probe->payment_method ?? '') !== 'tamara') {
            Log::warning('Tamara webhook rejected: gateway mismatch', [
                'gateway' => 'tamara',
                'event' => $eventType !== '' ? $eventType : 'unknown',
                'result' => 'rejected',
            ]);

            return $this->apiResponse(INVALID_PAYMENT_METHOD, 400, false);
        }

        $event = strtolower(trim($eventType));
        $captureId = is_array($body['data'] ?? null)
            ? (is_string($body['data']['capture_id'] ?? null) ? trim((string) $body['data']['capture_id']) : '')
            : '';

        $dedupeId = ($eventType !== '' ? $eventType : 'tamara_event') . ':' . $orderId
            . ($captureId !== '' && preg_match('/^[A-Za-z0-9\-_]{1,64}$/', $captureId) ? ':' . $captureId : '');

        $account = (string) config('payment.gateways.tamara.account', 'default');

        // W-1 / Constraint C fallback: Tamara publishes NO stable delivery
        // identity, and REFUND deliveries can be payload-identical while
        // being logically distinct. Their financial idempotency lives in
        // the per-refund ledger (recordExternalRefund
        // "tamara:refund:{txn}:{refund_id}"), so the REFUND family gets a
        // delivery-unique key (never inbox-suppressed) and the ledger
        // dedupes money. Completion/captured families keep the fingerprint
        // dedupe; the row-level event-id list stays the replay guard there.
        $isRefundFamily = str_contains($event, 'refund');

        $eventKey = \App\Services\Payment\WebhookEventInbox::fingerprint(
                'tamara',
                $account,
                $orderId,
                $eventType !== '' ? $eventType : 'tamara_event',
                ($captureId !== '' ? $captureId : 'data') . '|' . substr(
                    hash('sha256', (string) json_encode($body['data'] ?? null)),
                    0,
                    16
                )
            )
            . ($isRefundFamily ? '|d' . uniqid('', false) : '');

        $stored = $this->inbox->store(
            'tamara',
            $eventKey,
            $account,
            $eventType !== '' ? $eventType : null,
            $orderId,
            $attemptRef,
            ['event_type' => $eventType !== '' ? $eventType : null, 'provider_ref' => $orderId, 'capture_id' => $captureId !== '' ? $captureId : null],
        );

        if ($stored === null) {
            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        try {
            $response = $this->routeTamaraEvent($event, $eventType, $orderId, $dedupeId, $captureId, $attemptRef);
        } catch (\Throwable $e) {
            $this->inbox->markFailed($stored->id, $e);

            throw $e;
        }

        $this->inbox->markProcessed($stored->id);

        return $response;
    }

    private function routeTamaraEvent(
        string $event,
        string $eventType,
        string $orderId,
        string $dedupeId,
        string $captureId,
        ?string $attemptRef,
    ): JsonResponse {
        if (str_contains($event, 'approved') || str_contains($event, 'authorised') || str_contains($event, 'authorized') || str_contains($event, 'captured')) {
            // Single server-authoritative completion path: the fresh read
            // decides everything (an old approved replay after authorisation
            // converges via idempotency; a stale decline wake-up cannot
            // touch a paid row).
            $response = $this->completeFromProviderRef('tamara', $orderId, $dedupeId, $eventType !== '' ? $eventType : 'tamara_event', $attemptRef);

            if (str_contains($event, 'captured')) {
                $this->recordTamaraCaptures($orderId, $eventType !== '' ? $eventType : 'order_captured');
            }

            return $response;
        }

        if (str_contains($event, 'refund')) {
            $this->recordTamaraRefunds($orderId, $eventType !== '' ? $eventType : 'order_refunded');

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

        if (str_contains($event, 'canceled') || str_contains($event, 'cancelled') || str_contains($event, 'declined') || str_contains($event, 'expired')) {
            // The delivery family is a wake-up ONLY: the FRESH server read
            // arbitrates BOTH ways through the same single completion path
            // as the approved/authorised families. Terminal provider truth
            // fails the PENDING row (paid/terminal rows untouched); a stale
            // negative after a landed authorisation COMPLETES from the same
            // fresh read; an unreadable read stays pending — never a
            // terminal decision on the payload's word.
            return $this->completeFromProviderRef('tamara', $orderId, $dedupeId, $eventType !== '' ? $eventType : 'tamara_event', $attemptRef);
        }

        Log::info('Tamara webhook ignored: unhandled event', [
            'gateway' => 'tamara',
            'event' => $eventType !== '' ? $eventType : 'unknown',
            'result' => 'ignored',
        ]);

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
    }

    /**
     * Provider-side capture evidence appended to the transaction's
     * `_captures` ledger — appended-only, deduped per capture_id — from a
     * FRESH server read (never from the authenticated-but-unverified
     * payload amounts). The webhook is the wake-up; the fresh read supplies
     * the recorded evidence, so a lying or stale payload cannot rewrite the
     * ledger. Companion to `_refunds` (PaymentRefundService) and the
     * webhook ids list; no schema changes.
     */
    private function recordTamaraCaptures(string $orderId, string $eventType): void
    {
        try {
            $adapter = $this->gatewayFactory->make('tamara');
        } catch (\Throwable $e) {
            Log::warning('Tamara capture trail skipped: adapter unavailable', [
                'gateway' => 'tamara',
                'event' => $eventType,
                'result' => 'skipped',
            ]);

            return;
        }

        $result = $adapter->verifyPayment($orderId);
        $raw = is_array($result->rawResponse) ? $result->rawResponse : [];
        $captures = is_array($raw['captures'] ?? null) ? $raw['captures'] : [];

        if ($captures === []) {
            Log::info('Tamara capture trail: provider reports no captures yet', [
                'gateway' => 'tamara',
                'event' => $eventType,
                'result' => 'no_captures',
            ]);

            return;
        }

        DB::transaction(function () use ($orderId, $captures, $eventType) {
            $txn = $this->findTxnByRef($orderId, true);

            if (!$txn) {
                return;
            }

            // Mirror recordTamaraRefunds: re-assert the gateway binding
            // in-lock so a checkout-side re-attempt mutating the row cannot
            // steer the capture ledger onto another gateway's row.
            if (($txn->payment_method ?? '') !== 'tamara') {
                return;
            }

            $existing = is_array($txn->gateway_response) ? $txn->gateway_response : [];
            $ledger = is_array($existing['_captures'] ?? null) ? $existing['_captures'] : [];
            $knownIds = [];

            foreach ($ledger as $entry) {
                if (is_array($entry) && is_string($entry['capture_id'] ?? null)) {
                    $knownIds[$entry['capture_id']] = true;
                }
            }

            $now = now()->toISOString();

            foreach ($captures as $capture) {
                if (!is_array($capture) || !is_string($capture['capture_id'] ?? null)) {
                    continue;
                }

                $captureId = trim((string) $capture['capture_id']);

                if ($captureId === '' || isset($knownIds[$captureId])) {
                    continue;
                }

                // Provider capture-entry amount key is UNVERIFIED (OpenAPI
                // mixes amount/refunded_amount spellings) — tolerant read:
                // numeric or {amount,currency}; recorded when present, never
                // invented.
                $captureAmount = $capture['amount'] ?? null;
                $amountRow = null;

                if (is_numeric($captureAmount)) {
                    $amountRow = ['amount' => (float) $captureAmount];
                } elseif (is_array($captureAmount) && is_numeric($captureAmount['amount'] ?? null)) {
                    $amountRow = [
                        'amount' => (float) $captureAmount['amount'],
                        'currency' => is_string($captureAmount['currency'] ?? null) ? strtoupper(trim((string) $captureAmount['currency'])) : null,
                    ];
                }

                $entryRow = array_filter([
                    'capture_id' => $captureId,
                    'amount' => $amountRow,
                    'created' => is_string($capture['created'] ?? null) ? (string) $capture['created'] : null,
                    'source' => 'webhook',
                    'event' => $eventType,
                    'recorded_at' => $now,
                ], fn ($v) => $v !== null);

                $ledger[] = $entryRow;
            }

            $existing['_captures'] = $ledger;
            $txn->update(['gateway_response' => $existing]);
        });
    }

    /**
     * Provider-initiated Tamara refunds (partner portal refunds share the
     * documented webhook contract): fresh-read the order, append each UNSEEN
     * provider refund id through PaymentRefundService::recordExternalRefund
     * — the same side effects and replay protection as manual refunds
     * (per-refund dedupe key, remaining-amount fence, refundable-state gate
     * 'paid'/'partially_refunded'). Field spellings on the fresh order read
     * are read tolerantly (amount/refunded_amount; numeric or
     * {amount,currency}); unusable evidence is skipped honestly, never
     * invented, and recordExternalRefund's cumulative fence re-asserts the
     * amounts it does accept.
     */
    private function recordTamaraRefunds(string $orderId, string $eventType): void
    {
        $txn = $this->findTxnByRef($orderId);

        if (!$txn || !in_array($txn->status, ['paid', 'partially_refunded'], true)) {
            Log::info('Tamara refunds skipped: payment row not locally refundable', [
                'gateway' => 'tamara',
                'event' => $eventType,
                'transaction_id' => $txn->id ?? null,
                'result' => 'skipped',
            ]);

            return;
        }

        try {
            $adapter = $this->gatewayFactory->make('tamara');
        } catch (\Throwable $e) {
            Log::warning('Tamara refunds skipped: adapter unavailable', [
                'gateway' => 'tamara',
                'event' => $eventType,
                'result' => 'skipped',
            ]);

            return;
        }

        $result = $adapter->verifyPayment($orderId);
        $raw = is_array($result->rawResponse) ? $result->rawResponse : [];
        $refunds = is_array($raw['refunds'] ?? null) ? $raw['refunds'] : [];
        $currency = is_string($result->currency) && trim((string) $result->currency) !== ''
            ? strtoupper(trim((string) $result->currency))
            : (is_array($raw['total_amount'] ?? null) && is_string($raw['total_amount']['currency'] ?? null)
                ? strtoupper(trim((string) $raw['total_amount']['currency']))
                : '');

        if ($refunds === []) {
            Log::info('Tamara refund trail: provider reports no refunds yet', [
                'gateway' => 'tamara',
                'event' => $eventType,
                'result' => 'no_refunds',
            ]);

            return;
        }

        foreach ($refunds as $refund) {
            if (!is_array($refund)) {
                continue;
            }

            // Provider refund-entry id + amount keys are UNVERIFIED
            // spellings — tolerant read, honest skip when unusable.
            $refundId = is_string($refund['refund_id'] ?? null) ? trim((string) $refund['refund_id']) : '';
            $amountRaw = $refund['amount'] ?? $refund['refunded_amount'] ?? null;
            $refundAmount = null;

            if (is_numeric($amountRaw)) {
                $refundAmount = (float) $amountRaw;
            } elseif (is_array($amountRaw) && is_numeric($amountRaw['amount'] ?? null)) {
                $refundAmount = (float) $amountRaw['amount'];

                if (is_string($amountRaw['currency'] ?? null) && trim($amountRaw['currency']) !== '') {
                    $currency = strtoupper(trim((string) $amountRaw['currency']));
                }
            }

            if ($refundId === '' || $refundAmount === null || $refundAmount <= 0 || $currency === '') {
                Log::warning('Tamara external refund ignored: invalid refund payload', [
                    'gateway' => 'tamara',
                    'event' => $eventType,
                    'result' => 'ignored',
                ]);

                continue;
            }

            $summary = null;

            try {
                DB::transaction(function () use ($orderId, $refundId, $refundAmount, $currency, &$summary) {
                    $locked = $this->findTxnByRef($orderId, true);

                    if (!$locked
                        || ($locked->payment_method ?? '') !== 'tamara'
                        || !in_array($locked->status, ['paid', 'partially_refunded'], true)
                    ) {
                        return;
                    }

                    $lockedOrder = $locked->order()->lockForUpdate()->first();

                    if (!$lockedOrder) {
                        return;
                    }

                    $summary = $this->refundService->recordExternalRefund(
                        $lockedOrder,
                        $locked,
                        $refundId,
                        $refundAmount,
                        $currency,
                        'tamara:refund:' . $locked->id . ':' . $refundId,
                    );
                });
            } catch (\RuntimeException $e) {
                Log::warning('Tamara external refund rejected', [
                    'gateway' => 'tamara',
                    'event' => $eventType,
                    'result' => 'rejected',
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            Log::info('Tamara external refund processed', [
                'gateway' => 'tamara',
                'event' => $eventType,
                'transaction_id' => $txn->id,
                'result' => $summary === null
                    ? 'unmatched'
                    : (!empty($summary['idempotent_replay']) ? 'duplicate' : 'refunded'),
            ]);
        }
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
        ?string $attemptRef = null,
    ): JsonResponse {
        $probe = $this->findTxnByRef($ref);

        // PM-6: the provider id is not yet persisted locally (claimed
        // attempt) — correlate via the echoed per-attempt dispatch
        // reference, gated on the capability registry (fail-closed: only
        // documented/sandbox-verified echo paths activate).
        if (!$probe && $attemptRef !== null
            && \App\Services\Payment\PaymentCapabilityRegistry::correlationActive($gateway)
        ) {
            $probe = $this->findTxnByRef($attemptRef);
        }

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

        // PM-5/PM-6: authoritative evidence comes from a STRICTLY read-only
        // probe — NEVER from verifyPayment (which captures for PayPal) and
        // never from the webhook payload alone.
        $claimed = $this->attemptColumnsAvailable() && $probe->attempt_state === 'claimed';

        $result = $adapter instanceof \App\Services\Payment\Contracts\ReadonlyPaymentProbeContract
            ? $adapter->probePaymentStatus($ref)
            : $adapter->verifyPayment($ref); // test doubles only

        $verifiedRef = is_string($result->gatewayTransactionId) && trim($result->gatewayTransactionId) !== ''
            ? trim($result->gatewayTransactionId)
            : $ref;

        if ($claimed) {
            // PM-6 guarded adoption — the order must still be payable, and
            // the provider id comes from the fresh server-side probe result,
            // never from the webhook body.
            $orderRow = Order::query()->find($probe->order_id);

            if (!$orderRow || $orderRow->status !== 'pending') {
                Log::info('Payment webhook skipped: claimed attempt order no longer payable', [
                    'transaction_id' => $txnId,
                    'order_id' => $probe->order_id,
                    'result' => 'superseded_or_cancelled',
                ]);

                return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
            }

            if ($result->success && ($result->status ?? null) === 'paid') {
                $this->lifecycle->adoptSessionCreated($probe, $verifiedRef, is_array($result->rawResponse) ? $result->rawResponse : []);
            } elseif (!$result->success && ($result->status ?? null) === 'failed') {
                // Provider-CONFIRMED terminal evidence only (probe contract).
                $this->lifecycle->adoptSessionCreated($probe, $verifiedRef, []);
                $this->lifecycle->markFailedWithProviderEvidence(
                    $probe,
                    'probe:' . $gateway,
                    (string) ($result->errorMessage ?? 'Payment not completed')
                );

                Log::info('Payment attempt failed with provider evidence', [
                    'transaction_id' => $txnId,
                    'gateway' => $gateway,
                    'event' => $eventType,
                    'result' => 'failed',
                ]);

                return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'failed']);
            } elseif ($result->success && ($result->status ?? null) === 'pending') {
                // Conclusive-OPEN session: adopt, stay pending — the PM-7
                // capture flow or a later completion event resolves it.
                $this->lifecycle->adoptSessionCreated($probe, $verifiedRef, is_array($result->rawResponse) ? $result->rawResponse : []);

                return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'session_adopted']);
            } else {
                // Inconclusive probe: deduce NOTHING; the provider retry or
                // the recovery worker resolves. Never guess, never adopt.
                Log::info('Payment webhook inconclusive probe for claimed attempt', [
                    'transaction_id' => $txnId,
                    'result' => 'unknown_kept',
                ]);

                return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
            }
        }

        if (!$result->success && ($result->status ?? null) === 'failed') {
            return $this->markFailedWithResult($txnId, $gateway, $eventId, $eventType, $result);
        }

        if (!$result->success || ($result->status ?? null) !== 'paid') {
            // T-03: a non-paid or inconclusive answer NEVER fails the row.
            // Provider retries and the recovery worker provide resolution.
            Log::info('Payment webhook non-terminal: kept unresolved', [
                'transaction_id' => $txnId,
                'gateway' => $gateway,
                'event' => $eventType,
                'result' => 'kept_unknown',
            ]);

            return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true, ['status' => 'ignored']);
        }

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
        ?string $attemptRef = null,
    ): JsonResponse {
        $probe = $this->findTxnByRef($ref);

        // PM-6 claimed-row fallback (capability-gated, see
        // completeFromProviderRef).
        if (!$probe && $attemptRef !== null
            && \App\Services\Payment\PaymentCapabilityRegistry::correlationActive($gateway)
        ) {
            $probe = $this->findTxnByRef($attemptRef);
        }

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

        // PM-6: the per-attempt dispatch reference — authenticated payloads
        // echo it back (Stripe metadata / PayPal custom_id / Tabby
        // order.reference_id / Tamara order_reference_id / Tap
        // reference.idempotent), so a webhook can resolve the exact attempt
        // BEFORE the provider id has been persisted locally.
        if ($this->attemptColumnsAvailable()) {
            $query->orWhere('dispatch_reference', $ref);
        }

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

<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\CurrencyPrecision;
use App\Services\Payment\CustomerContactResolver;
use App\Services\Payment\PaymentCurrencyResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;

/**
 * Tabby gateway adapter (https://docs.tabby.ai) — MENA Buy-Now-Pay-Later.
 *
 * API contract (official docs, re-verified 2026-10-31 against
 * docs.tabby.ai/api-reference/checkout/session-payload-model.md,
 * /api-reference/payments/*.md and /pay-in-4-custom-integration/*.md):
 *
 * - Sessions: POST {base}/checkout (Bearer secret_key) — body envelope is
 *   `{payment: {...}, lang, merchant_code, merchant_urls}` where
 *   `merchant_urls` is a TOP-LEVEL sibling of `payment` (not nested inside
 *   it). `payment` requires amount (string, ≤3dp), currency (AED|SAR enum),
 *   buyer{name,email,phone}, shipping_address{city,address,zip},
 *   order{reference_id, items[{title,quantity,unit_price,category}]},
 *   buyer_history{registered_since}. Response: session id + `payment.id`
 *   (UUID — persist this) + HPP link at
 *   configuration.available_products.installments[0].web_url (ALWAYS
 *   validate presence); session status `created`|`rejected`.
 * - Retrieve: GET {base}/payments/{id} — verification re-fetches
 *   server-side (redirect parameters are untrusted hints). SUCCESS IS
 *   EXCLUSIVELY status === 'CLOSED' WITH non-empty captures[] (AUTHORIZED
 *   = funds reserved, NOT transferred — docs: "Only captured payments are
 *   settled to you").
 * - Capture: POST {base}/payments/{id}/captures {amount, reference_id}
 *   (reference_id REQUIRED idempotency key; identical key retries never
 *   duplicate — docs.Idempotent Requests). Full capture => the payment
 *   becomes CLOSED; capture attempts outside AUTHORIZED return 400
 *   ("already closed" after a landed capture is benign — arbitrate with a
 *   fresh retrieve, never complete on the raw error).
 * - Refunds: POST {base}/payments/{id}/refunds {amount, reference_id
 *   [idempotency, same-key retries never duplicate], reason?} — CLOSED
 *   payments only, ≤ captured amount, ≤180 days, same currency.
 * - Webhooks: lowercase statuses (`authorized`) vs retrieve uppercase
 *   (`AUTHORIZED`); captures[] may be pre-populated at AUTHORIZED — a
 *   capture CONFIRMATION is NOT a capture TRIGGER (docs/webhooks edge
 *   cases); statuses only move forward.
 *
 * CAPTURE-FIRST LIFECYCLE (architectural decision D1-R, approved): local
 * completion is an irreversible operation cascade
 * (PaymentCompletionService::completeLocked). Therefore:
 *   AUTHORIZED without a confirmed capture  => capture attempted here
 *   (idempotently); if it can NOT be verified as captured, this method
 *   returns a FAIL-CLOSED NON-TERMINAL GatewayResult (success=false,
 *   status='pending') so the order/inventory/promotions are never touched
 *   before Tabby holds captured funds. Resolution continues through any
 *   later verify/webhook re-entry.
 *
 * Currency is NEVER hardcoded: every payment/refund derives its currency
 * from PaymentCurrencyResolver::forOrder($order) (order snapshot →
 * catalog currency) and validates it against the config
 * supported_currencies (default AED,SAR — Tabby's documented enum)
 * before any provider call. Amounts are rounded exclusively through
 * CurrencyPrecision (shared exponent table).
 *
 * Hygiene: persisted/logged provider payloads pass through allowlists
 * (no buyer PII, no raw provider internals); error messages are truncated
 * and sk_ redacted; TLS verification is always enabled (no
 * withoutVerifying); logs carry ids/statuses only — never raw bodies.
 */
class TabbyGateway implements PaymentGatewayContract
{
    /**
     * Verification payment allowlist: technical fields only. The payment
     * object carries buyer PII (name/email/phone/dob) and full order echo —
     * intentionally stripped at the source, never persisted or logged.
     */
    private const PAYMENT_ALLOWLIST = [
        'id',
        'status',
        'amount',
        'currency',
    ];

    /**
     * Tabby statuses that mean "never took / will never take money" ON A
     * SUCCESSFUL SERVER-READ. CLOSED only qualifies when captures[] is
     * empty (an authority that was closed synchronously before capture).
     */
    private const TERMINAL_FAILURE_STATUSES = ['REJECTED', 'EXPIRED'];

    public function __construct(
        private PaymentCurrencyResolver $currencyResolver,
        private CustomerContactResolver $customerContactResolver,
    ) {}

    public function createInvoice(
        Order $order,
        float $amount,
        string $callbackUrl,
        string $errorUrl,
        array $metadata = []
    ): GatewayResult {
        $orderCurrency = $this->currencyResolver->forOrder($order);

        if (!$this->supportsCurrency($orderCurrency)) {
            return new GatewayResult(
                success: false,
                errorMessage: __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $orderCurrency]),
            );
        }

        if ($amount <= 0) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid payment amount',
            );
        }

        // Amount as a decimal STRING at ≤2 decimals (AI response schema
        // PaymentAmount: "Total payment amount... up to 2 decimals for AED
        // and SAR"). CurrencyPrecision stays the rounding authority.
        $paymentAmount = CurrencyPrecision::formatForGateway($amount, $orderCurrency);

        $payload = $this->sessionPayload($order, $orderCurrency, $paymentAmount, $callbackUrl);

        $response = $this->request('post', '/checkout', $payload);

        if (!$response instanceof \Illuminate\Http\Client\Response) {
            return $response; // fail-closed GatewayResult from request()
        }

        if (!$response->successful()) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->errorMessageFrom($response, 'Session request failed'),
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
            );
        }

        $sessionId = $this->stringOrNull($data['id'] ?? null);
        $sessionStatus = $this->stringOrNull($data['status'] ?? null);
        $paymentId = $this->stringOrNull(data_get($data, 'payment.id') ?? null);
        // The Hosted Payment Page link lives in installment product #0 and
        // MUST be present and non-empty before we call this a success
        // (docs: "Always validate web_url is present before redirecting").
        $webUrl = $this->stringOrNull(
            data_get($data, 'configuration.available_products.installments.0.web_url')
        );

        if ($paymentId === null || $webUrl === null) {
            return new GatewayResult(
                success: false,
                errorMessage: $sessionStatus === 'rejected'
                    ? __('message.ERROR.PAYMENT_PROVIDER_DECLINED')
                    : 'Invalid gateway response',
                rawResponse: $this->sanitizeSession($data, $sessionId, $sessionStatus),
            );
        }

        return new GatewayResult(
            success: true,
            redirectUrl: $webUrl,
            gatewayTransactionId: $paymentId,
            amount: is_numeric(data_get($data, 'payment.amount')) ? (float) data_get($data, 'payment.amount') : $amount,
            currency: $orderCurrency,
            status: 'pending',
            rawResponse: $this->sanitizeSession($data, $sessionId, $sessionStatus),
        );
    }

    public function verifyPayment(string $gatewayTransactionId): GatewayResult
    {
        $id = trim($gatewayTransactionId);

        if ($id === '' || strlen($id) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $id)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway transaction id',
            );
        }

        $data = $this->fetchPayment($id);

        if (!is_array($data)) {
            // Ambiguous read (transport failure / provider error): deduce
            // NOTHING. Fail-closed NON-TERMINAL so no irreversible local
            // business state may be decided on an unread truth.
            return new GatewayResult(
                success: false,
                status: 'pending',
                gatewayTransactionId: $id,
                errorMessage: $data instanceof GatewayResult ? $data->errorMessage : 'Payment gateway error',
            );
        }

        return $this->mapPayment($data, $id, captureRequested: false);
    }

    public function refund(
        Order $order,
        float $amount,
        ?string $reason = null
    ): GatewayResult {
        $orderCurrency = $this->currencyResolver->forOrder($order);

        if (!$this->supportsCurrency($orderCurrency)) {
            return new GatewayResult(
                success: false,
                errorMessage: __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $orderCurrency]),
            );
        }

        if ($amount <= 0) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid payment amount',
            );
        }

        $transaction = $order->transactions()
            ->whereNotNull('gateway_transaction_id')
            ->latest()
            ->first();

        if (!$transaction || !$transaction->gateway_transaction_id) {
            return new GatewayResult(
                success: false,
                errorMessage: 'No paid transaction found for this order',
            );
        }

        $paymentId = trim((string) $transaction->gateway_transaction_id);

        if ($paymentId === '' || strlen($paymentId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $paymentId)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway transaction id',
            );
        }

        $refundAmount = CurrencyPrecision::formatForGateway($amount, $orderCurrency);

        // Idempotency key must be DERIVED FROM THE ORDER, not from the
        // clock (docs.tabby.ai refund endpoint: reference_id is the
        // idempotency key, "retries with the same reference_id never
        // create a second refund"). Constant per (order, amount): a
        // network-then-
        // retry admin flow can never double-refund — Tabby collapses the
        // duplicate attempt onto the original refund. Trade-off: two
        // INTENTIONAL same-amount partial refunds on one order deduplicate
        // to a single provider refund; they surface as replays and the
        // admin adjusts the split amounts (documented decision, revisit if
        // genuine equal-amount splits become a business need).
        $referenceId = 'refund-' . $order->id . '-' . $refundAmount;

        $payload = [
            'amount' => $refundAmount,
            'reference_id' => $referenceId,
        ];

        if ($reason !== null && trim($reason) !== '') {
            // Free-text reason is optional per the refund schema; trimmed,
            // bounded — no assumption about provider-side enum values.
            $payload['reason'] = mb_substr(strip_tags(trim($reason)), 0, 200);
        }

        $response = $this->request('post', '/payments/' . urlencode($paymentId) . '/refunds', $payload);

        if (!$response instanceof \Illuminate\Http\Client\Response) {
            return $response; // fail-closed GatewayResult from request()
        }

        if (!$response->successful()) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->errorMessageFrom($response, 'Refund request failed'),
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
            );
        }

        $refundId = $this->stringOrNull($data['id'] ?? null);

        // Fail-closed: a refund counts as successful ONLY when Tabby
        // confirms it with a refund id. Ambiguous outcomes surface as
        // failures so operations can re-check the merchant dashboard; the
        // idempotent reference_id makes a cautious manual retry safe.
        if ($refundId === null) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Refund not confirmed by gateway',
            );
        }

        return new GatewayResult(
            success: true,
            gatewayTransactionId: $refundId,
            amount: is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : $amount,
            currency: $orderCurrency,
            status: 'succeeded',
            rawResponse: [
                'id' => $refundId,
                'amount' => $refundAmount,
                'reference_id' => $referenceId,
            ],
        );
    }

    public function name(): string
    {
        return 'tabby';
    }

    public function code(): string
    {
        return 'tabby';
    }

    public function isConfigured(): bool
    {
        $key = config('payment.gateways.tabby.secret_key');

        return is_string($key) ? trim($key) !== '' : !empty($key);
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        $supported = (array) (config('payment.gateways.tabby.supported_currencies') ?? []);

        return in_array(strtoupper($currencyCode), array_map('strtoupper', $supported), true);
    }

    // -----------------------------------------------------------------
    // Retrieval + capture-first arbitration
    // -----------------------------------------------------------------

    /**
     * Server-authoritative truth: GET /payments/{id}. Returns the decoded
     * array, or a fail-closed GatewayResult for transport/config failures
     * (the caller maps every unread truth to NON-TERMINAL pending).
     *
     * @return array<string, mixed>|GatewayResult
     */
    private function fetchPayment(string $id): mixed
    {
        $response = $this->request('get', '/payments/' . urlencode($id));

        if (!$response instanceof \Illuminate\Http\Client\Response) {
            return $response; // fail-closed GatewayResult from request()
        }

        if (!$response->successful()) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->errorMessageFrom($response, 'Payment verification failed'),
            );
        }

        return $response->json();
    }

    /**
     * Fresh-read status map — the SINGLE source of local outcome truth.
     *
     * @param array<string, mixed> $data payment object from GET /payments/{id}
     * @param bool $captureRequested whether a capture POST was issued during
     *        this verification attempt (attempt-trail bookkeeping only).
     *
     * SUCCESS IS EXCLUSIVELY CLOSED + non-empty captures[] on the fresh
     * read. CREATED/read-ambiguity/unconfirmed-capture => NON-TERMINAL
     * pending; REJECTED/EXPIRED/CLOSED-without-captures => terminal failed.
     */
    private function mapPayment(array $data, string $id, bool $captureRequested): GatewayResult
    {
        $status = strtoupper(trim((string) ($data['status'] ?? '')));
        $amount = is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null;
        // Provider-verified amount/currency are echoed as-is: the canonical
        // completion authority (assertNoMismatch) compares them against the
        // order snapshot — never trust the local amount here.
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        $captures = is_array($data['captures'] ?? null) ? $data['captures'] : [];
        $hasCaptures = $captures !== [];
        $raw = $this->sanitizePayment($data) + [
            '_capture' => [
                'reference_id' => 'capture-' . $id,
                'requested' => $captureRequested,
            ],
        ];

        $paymentResult = function (bool $success, string $status, ?string $message = null) use ($id, $amount, $currency, $raw): GatewayResult {
            return new GatewayResult(
                success: $success,
                gatewayTransactionId: $id,
                amount: $amount,
                currency: $currency !== '' ? $currency : null,
                status: $status,
                errorMessage: $message,
                rawResponse: $raw,
            );
        };

        if ($status === 'CLOSED') {
            // Capture-confirmed truth (docs: a full capture closes the
            // payment). CLOSED WITHOUT captures = the authority was closed
            // synchronously before capture — terminal, nothing to complete.
            return $hasCaptures
                ? $paymentResult(true, 'paid')
                : $paymentResult(false, 'failed', __('message.ERROR.PAYMENT_PROVIDER_DECLINED'));
        }

        if (in_array($status, self::TERMINAL_FAILURE_STATUSES, true)) {
            return $paymentResult(false, 'failed', __('message.ERROR.PAYMENT_PROVIDER_DECLINED'));
        }

        if ($status === 'AUTHORIZED') {
            if (!$hasCaptures) {
                return $this->captureFirst($id, $amount, $currency, $raw);
            }

            // A capture CONFIRMATION is not a capture TRIGGER (docs/webhooks
            // edge case): captures already exist while the retrieval still
            // says AUTHORIZED — the closure may lag. Re-arbitrate once from
            // a fresh read without sending a duplicate capture.
            $fresh = $this->fetchPayment($id);

            if (!is_array($fresh)) {
                return $paymentResult(false, 'pending', 'Payment gateway error');
            }

            $freshStatus = strtoupper(trim((string) ($fresh['status'] ?? '')));

            if ($freshStatus === 'CLOSED' && (is_array($fresh['captures'] ?? null) && $fresh['captures'] !== [])) {
                return $this->resultFromPayment($fresh, $id, true);
            }

            // Still AUTHORIZED with partial captures: not fully paid. Pending
            // — either Tabby closes it momentarily or it needs the remainder
            // captured/closed by operations; no irreversible local behavior.
            return $paymentResult(false, 'pending', 'Payment capture not confirmed yet');
        }

        if ($status === 'CREATED') {
            // Checkout not finished (docs payment-processing table): no
            // capture attempt (captures on CREATED return 400), non-terminal.
            return $paymentResult(false, 'pending', 'Payment not completed yet');
        }

        // Unrecognized status: never a terminal decision on ambiguity.
        return $paymentResult(false, 'pending', 'Payment not completed yet');
    }

    /**
     * The capture attempt: ONLY on a fresh AUTHORIZED + empty captures[]
     * read. Bounded same-reference_id retries on 409 "payment is locked" /
     * 5xx / transport; ambiguous or refused outcomes arbitrate from a
     * fresh retrieve instead of retrying blindly (docs: "if you get any
     * other response, investigate before retrying"). Unresolved outcome =>
     * NON-TERMINAL pending (success=false, status='pending') — never a
     * completion, never a permanent local failure.
     */
    private function captureFirst(
        string $id,
        ?float $amount,
        string $currency,
        array $raw,
    ): GatewayResult {
        if ($amount === null || $currency === '') {
            return new GatewayResult(
                success: false,
                status: 'pending',
                gatewayTransactionId: $id,
                errorMessage: 'Gateway payment payload incomplete',
                rawResponse: $raw,
            );
        }

        $referenceId = 'capture-' . $id;
        $path = '/payments/' . urlencode($id) . '/captures';
        // Full-amount capture (docs: "best-practice flow is a full capture
        // right after verification"; Tabby validates the capture amount
        // against the payment amount). Decimal string ≤2 decimals.
        $payload = [
            'amount' => CurrencyPrecision::formatForGateway($amount, $currency),
            'reference_id' => $referenceId,
        ];

        // Bounded budget so a buyer callback/webhook never hangs: retries
        // share THE SAME reference_id (docs: "A retry with the same
        // reference_id will not create a second capture").
        $retries = max(0, min(5, (int) (config('payment.gateways.tabby.capture_attempts', 2))));
        $delayMs = max(0, min(30000, (int) (config('payment.gateways.tabby.capture_retry_delay_ms', 2000))));

        for ($attempt = 1; $attempt <= $retries + 1; $attempt++) {
            if ($attempt > 1) {
                usleep($delayMs * 1000);
            }

            $response = $this->request('post', $path, $payload);

            if (!$response instanceof \Illuminate\Http\Client\Response) {
                continue; // transport failure — retry the same idempotent key
            }

            if ($response->status() === 409 || $response->serverError()) {
                continue; // "payment is locked" / provider hiccup — same-key retry
            }

            if ($response->successful()) {
                // Arbitrate from a FRESH retrieve before believing the 2xx
                // (docs: a full capture closes the payment; anything short
                // of CLOSED+captures on the fresh read stays unresolved).
                $fresh = $this->fetchPayment($id);

                if (is_array($fresh)) {
                    $freshStatus = strtoupper(trim((string) ($fresh['status'] ?? '')));

                    if ($freshStatus === 'CLOSED' && (is_array($fresh['captures'] ?? null) && $fresh['captures'] !== [])) {
                        return $this->withCaptureTrail(
                            $this->resultFromPayment($fresh, $id, true),
                            ['reference_id' => $referenceId, 'attempts' => $attempt, 'outcome' => 'captured'],
                        );
                    }
                }

                // Captured-in-flight but not confirmable right now:
                // ambiguous, resolved by the next verify/webhook re-entry —
                // never terminal here.
                return $this->withCaptureTrail(
                    new GatewayResult(
                        success: false,
                        status: 'pending',
                        gatewayTransactionId: $id,
                        amount: $amount,
                        currency: $currency,
                        errorMessage: 'Payment capture not confirmed yet',
                    ),
                    ['reference_id' => $referenceId, 'attempts' => $attempt, 'outcome' => 'unconfirmed'],
                );
            }

            // Non-2xx, non-409 refusal: take NOTHING from the error body —
            // the fresh authoritative read decides (docs: investigate, don't
            // blind-retry).
            $fresh = $this->fetchPayment($id);

            if (!is_array($fresh)) {
                continue; // truth unreadable — retry within budget
            }

            $freshStatus = strtoupper(trim((string) ($fresh['status'] ?? '')));

            if ($freshStatus === 'CLOSED' && (is_array($fresh['captures'] ?? null) && $fresh['captures'] !== [])) {
                // Benign dedupe: a prior attempt (or a concurrent code path
                // with the SAME reference_id) landed before ours.
                return $this->withCaptureTrail(
                    $this->resultFromPayment($fresh, $id, true),
                    ['reference_id' => $referenceId, 'attempts' => $attempt, 'outcome' => 'captured'],
                );
            }

            if ($freshStatus === 'AUTHORIZED') {
                continue; // still authorized — same-key retry within budget
            }

            // The authority moved out of AUTHORIZED between the read and
            // the capture (REJECTED/EXPIRED/CLOSED-empty/CREATED). The
            // fresh-read status map decides — never the raw 400.
            return $this->withCaptureTrail(
                $this->mapPayment($fresh, $id, true),
                ['reference_id' => $referenceId, 'attempts' => $attempt, 'outcome' => 'not_capturable'],
            );
        }

        // One final reconciliation read AFTER the budget: the last 409/5xx
        // may have been racing a capture that landed microseconds later
        // (docs: statuses only move forward — read them again before
        // declaring the mailbox stuck).
        $final = $this->fetchPayment($id);

        if (is_array($final)) {
            $finalStatus = strtoupper(trim((string) ($final['status'] ?? '')));
            $finalCaptures = is_array($final['captures'] ?? null) ? $final['captures'] : [];

            if ($finalStatus === 'CLOSED' && $finalCaptures !== []) {
                return $this->withCaptureTrail(
                    $this->resultFromPayment($final, $id, true),
                    ['reference_id' => $referenceId, 'attempts' => $retries + 1, 'outcome' => 'captured'],
                );
            }

            if (in_array($finalStatus, self::TERMINAL_FAILURE_STATUSES, true)
                || ($finalStatus === 'CLOSED' && $finalCaptures === [])
            ) {
                // Terminal-only branch: CLOSED-empty / REJECTED / EXPIRED map
                // safely through mapPayment. Its AUTHORIZED branch can never
                // re-enter captureFirst here — AUTHORIZED/CREATED failed the
                // guards above and fall through to the resumable pending
                // below instead of a second capture behind a dead budget.
                return $this->withCaptureTrail(
                    $this->mapPayment($final, $id, true),
                    ['reference_id' => $referenceId, 'attempts' => $retries + 1, 'outcome' => 'not_capturable'],
                );
            }
        }

        // Budget exhausted without a confirmed capture: NON-TERMINAL,
        // resumable pending. Any later entry point (close/capture webhook,
        // buyer callback, subsequent verification) safely re-runs capture
        // with the SAME reference_id; Tabby may also auto-capture the
        // remainder after 21 days (docs missing-capture policy).
        return $this->withCaptureTrail(
            new GatewayResult(
                success: false,
                status: 'pending',
                gatewayTransactionId: $id,
                amount: $amount,
                currency: $currency,
                errorMessage: __('message.ERROR.PAYMENT_PROVIDER_DECLINED'),
            ),
            ['reference_id' => $referenceId, 'attempts' => $retries + 1, 'outcome' => 'unresolved'],
        );
    }

    /**
     * Immutable capture-trail re-stamp for a GatewayResult built by the
     * status map (rawResponse could not know the attempt details yet).
     */
    private function withCaptureTrail(GatewayResult $result, array $trail): GatewayResult
    {
        $raw = is_array($result->rawResponse) ? $result->rawResponse : [];
        $raw['_capture'] = $trail;

        return new GatewayResult(
            success: $result->success,
            redirectUrl: $result->redirectUrl,
            gatewayTransactionId: $result->gatewayTransactionId,
            amount: $result->amount,
            currency: $result->currency,
            status: $result->status,
            errorMessage: $result->errorMessage,
            rawResponse: $raw,
        );
    }

    /**
     * GatewayResult built exclusively from a payment array that the caller
     * has ALREADY validated (CLOSED + captures).
     *
     * @param array<string, mixed> $data
     */
    private function resultFromPayment(array $data, string $id, bool $captured): GatewayResult
    {
        $status = strtoupper(trim((string) ($data['status'] ?? '')));
        $hasCaptures = is_array($data['captures'] ?? null) && $data['captures'] !== [];
        $paid = $captured && $status === 'CLOSED' && $hasCaptures;

        return new GatewayResult(
            success: $paid,
            gatewayTransactionId: $id,
            amount: is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null,
            currency: strtoupper(trim((string) ($data['currency'] ?? ''))) ?: null,
            status: $paid ? 'paid' : 'pending',
            errorMessage: $paid ? null : 'Payment capture not confirmed yet',
            rawResponse: $this->sanitizePayment($data),
        );
    }

    // -----------------------------------------------------------------
    // Payload builders
    // -----------------------------------------------------------------

    /**
     * Session payload per docs.session-payload-model: payment envelope
     * (amount/currency/buyer/shipping/order/buyer_history required),
     * lang + merchant_code + merchant_urls as TOP-LEVEL siblings.
     *
     * Tabby receives only data the buyer already supplied at checkout
     * (name, phone, email, address); the resolver supplies a
     * deterministic unroutable fallback email for guest orders (never
     * persisted as a user address).
     *
     * @return array<string, mixed>
     */
    private function sessionPayload(Order $order, string $currency, string $paymentAmount, string $callbackUrl): array
    {
        $address = is_array($order->address) ? $order->address : [];

        $buyer = [
            'name' => $this->customerName($order),
            'email' => $this->customerContactResolver->emailForGateway($order),
        ];

        $phone = trim((string) ($order->user_phone ?? ''));

        if ($phone !== '') {
            $buyer['phone'] = mb_substr($phone, 0, 32);
        }

        $payload = [
            'payment' => [
                'amount' => $paymentAmount,
                'currency' => $currency,
                'buyer' => $buyer,
                'shipping_address' => [
                    // keys mirror the documented shape (city/address/zip);
                    // the order snapshot is the address authority.
                    'city' => mb_substr(trim((string) ($address['city'] ?? '')), 0, 100),
                    'address' => mb_substr(trim((string) ($address['street'] ?? '')), 0, 255),
                    'zip' => mb_substr(trim((string) ($address['zip'] ?? '')), 0, 20),
                ],
                'order' => [
                    'reference_id' => (string) $order->id,
                    // Single synthetic line item: itemizing the cart would
                    // add price-decomposition assumptions the capture flow
                    // never relies on; full payment amount stays the
                    // authoritative value (docs payment-processing).
                    'items' => [
                        [
                            'title' => mb_substr('Order #' . (string) ($order->order_number ?? $order->id), 0, 100),
                            'quantity' => 1,
                            'unit_price' => $paymentAmount,
                            'category' => 'Other', // free-form per the item schema
                        ],
                    ],
                ],
                'buyer_history' => [
                    'registered_since' => $this->registeredSince($order),
                ],
                'meta' => [
                    'order_id' => (string) $order->id,
                ],
            ],
            // REQUIRED session field (docs): language used to launch the
            // HPP; enum ar|en.
            'lang' => in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'en',
        ];

        $merchantCode = trim((string) (config('payment.gateways.tabby.merchant_code') ?? ''));

        if ($merchantCode !== '') {
            $payload['merchant_code'] = mb_substr($merchantCode, 0, 100);
        }

        // All three roads lead to the canonical server-decided callback
        // (D2): the redirect is an untrusted hint.
        $payload['merchant_urls'] = [
            'success' => $callbackUrl,
            'cancel' => $callbackUrl,
            'failure' => $callbackUrl,
        ];

        return $payload;
    }

    private function customerName(Order $order): string
    {
        $name = trim((string) ($order->name ?? ''));

        return $name !== '' ? mb_substr($name, 0, 100) : 'Customer';
    }

    /**
     * REQUIRED buyer_history field (docs session-payload-model): the
     * customer timeline Tabby uses for pre-scoring. Primary value is the
     * real account-creation timestamp; the fallback keeps the value
     * deterministic per order so retries submit the same payload.
     */
    private function registeredSince(Order $order): string
    {
        $registeredAt = $order->user?->created_at ?? $order->created_at ?? now();

        return $registeredAt->toIso8601String();
    }

    // -----------------------------------------------------------------
    // HTTP + sanitization helpers
    // -----------------------------------------------------------------

    /**
     * Single HTTP seam: Bearer secret, JSON accept, 30s timeout, TLS
     * verification always on. Returns the Response, or a fail-closed
     * GatewayResult for transport failures (timeout/network) so callers
     * never see an exception escape (ambiguous outcomes must never decide
     * local money state — a retried create creates the same session body).
     *
     * @return \Illuminate\Http\Client\Response|GatewayResult
     */
    private function request(string $method, string $path, ?array $payload = null): mixed
    {
        $secret = trim((string) config('payment.gateways.tabby.secret_key', ''));
        $baseUrl = rtrim((string) config('payment.gateways.tabby.base_url', 'https://api.tabby.ai/api/v2'), '/');

        if ($secret === '' || $baseUrl === '') {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway is not configured',
            );
        }

        try {
            $http = Http::withToken($secret)
                ->acceptJson()
                ->timeout(30);

            return $method === 'post'
                ? $http->post($baseUrl . $path, $payload ?? [])
                : $http->get($baseUrl . $path);
        } catch (\Throwable $e) {
            // Network/timeout: fail closed, no provider detail echoed.
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway error',
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeSession(array $data, ?string $sessionId, ?string $sessionStatus): ?array
    {
        $sanitized = [
            'id' => $sessionId,
            'status' => $sessionStatus,
            'payment_id' => $this->stringOrNull(data_get($data, 'payment.id') ?? null),
            'amount' => data_get($data, 'payment.amount'),
            'currency' => data_get($data, 'payment.currency'),
            'rejection_reason' => data_get($data, 'configuration.products.installments.rejection_reason'),
        ];

        $sanitized = array_filter($sanitized, fn ($v) => $v !== null && $v !== '');

        return $sanitized === [] ? null : $sanitized;
    }

    /**
     * Payment allowlist for verification responses: technical fields only —
     * buyer PII (name/email/phone), order item payload echo, and provider
     * internals are stripped at the source (preserve customers/refunds ids
     * solely as reconciliation evidence).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sanitizePayment(array $data): array
    {
        $sanitized = [];

        foreach (self::PAYMENT_ALLOWLIST as $key) {
            if (array_key_exists($key, $data)) {
                $sanitized[$key] = $data[$key];
            }
        }

        $orderReference = data_get($data, 'order.reference_id');

        if (is_string($orderReference) && $orderReference !== '') {
            $sanitized['order_reference_id'] = $orderReference;
        }

        foreach (['captures', 'refunds'] as $listKey) {
            if (is_array($data[$listKey] ?? null)) {
                $sanitized[$listKey] = array_map(
                    fn (mixed $entry): array => array_intersect_key(
                        (array) $entry,
                        array_flip(['id', 'amount', 'created_at', 'reference_id']),
                    ),
                    array_values(array_filter($data[$listKey], fn (mixed $entry): bool => is_array($entry))),
                );
            }
        }

        return $sanitized;
    }

    /**
     * Bounded error message: a single allowlisted provider error field (or
     * a generic fallback with the HTTP status). Never echoes the raw body
     * and redacts embedded sk_ key material, mirroring TapGateway.
     */
    private function errorMessageFrom(\Illuminate\Http\Client\Response $response, string $fallback): string
    {
        $json = $response->json();
        $message = null;

        if (is_array($json)) {
            if (is_string($json['message'] ?? null) && trim((string) $json['message']) !== '') {
                $message = (string) $json['message'];
            } elseif (is_string($json['error'] ?? null) && trim((string) $json['error']) !== '') {
                // Tabby error envelope: {errorType, error|errors[]} (docs
                // response-codes) — `error` can also be an object; a scalar
                // string is the only shape worth echoing.
                $message = (string) $json['error'];
            } elseif (is_array($json['errors'] ?? null) && $json['errors'] !== []) {
                $first = is_string($json['errors'][0] ?? null) ? $json['errors'][0] : null;

                if ($first !== null) {
                    $message = $first;
                }
            }
        }

        $redacted = preg_replace('/sk_(live|test)_[A-Za-z0-9]+/', '[redacted]', $message ?? ($fallback . ' (HTTP ' . $response->status() . ')'));

        if (!is_string($redacted) || trim($redacted) === '') {
            return $fallback;
        }

        return Str::limit(strip_tags($redacted), 300, '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

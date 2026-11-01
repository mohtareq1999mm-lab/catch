<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\AuthorisationGatewayContract;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\CurrencyPrecision;
use App\Services\Payment\CustomerContactResolver;
use App\Services\Payment\PaymentCurrencyResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;

/**
 * Tamara gateway adapter (https://docs.tamara.co) — MENA Buy-Now-Pay-Later,
 * DIRECT integration (not the Amazon Payment Services hosted variant).
 *
 * API contract (official docs, re-verified 2026-10-31 against
 * docs.tamara.co/reference/createcheckoutsession, /reference/getorderdetails,
 * /reference/authoriseorder, /reference/captureorder,
 * /reference/simplifiedrefund, /reference/cancelorder,
 * /docs/pre-checkout-eligibility, /docs/webhook-subscription,
 * /docs/online-order-status-flow, /docs/online-go-live-testing-checklist):
 *
 * - Base: sandbox = https://api-sandbox.tamara.co, production =
 *   https://api.tamara.co. Selected by config `environment`; an explicit
 *   TAMARA_API_URL overrides both. Unknown environments fail CLOSED (never
 *   guess an unintended production host).
 * - Auth: every API call = Authorization: Bearer {API_TOKEN}. Webhooks are a
 *   SEPARATE credential (Notification Token, HS256 JWT — see
 *   TamaraWebhookVerifier). The two tokens are never mixed and never logged.
 * - POST /checkout requires: total_amount{amount,currency}, description,
 *   country_code (SA|AE|BH|KW|OM), payment_type, items[], consumer
 *   {first_name,last_name,phone_number,email?}, shipping_address
 *   {first_name,last_name,line1,city,country_code}, tax_amount,
 *   shipping_amount, merchant_url{success,failure,cancel}, instalments,
 *   risk_assessment (is_premium_customer, account_creation_date,
 *   total_order_count, date_first_paid, date_last_paid per the schema's
 *   required list). Optional: order_number, discount{name,amount}, platform,
 *   is_mobile, locale. Response: order_id, checkout_id, status, checkout_url.
 *   Currency enum: SAR|AED|BHD|KWD|OMR (merchant ACCOUNT may enable a
 *   subset — the supported_currencies list stays env-configurable).
 * - LIFECYCLE (docs online-order-status-flow): new → approved → authorised →
 *   partially/fully_captured → partially/fully_refunded; negative exits
 *   declined / expired / canceled; "updated" = partial-cancel remainder.
 *   Checkout session not completed ≈ 30 min ⇒ expired; approved-not-
 *   authorised within 72h ⇒ expired; un-captured authorised orders are
 *   auto-captured by Tamara after 21 days; auto-authorisation merchants may
 *   move approved → fully_captured without an explicit authorise call.
 * - POST /orders/{order_id}/authorise: merchant confirms the approved order
 *   (docs: "once the authorised stage is reached, you can consider the order
 *   as paid"). NO documented idempotency key — dedupe is local: this
 *   adapter only authorises when a FRESH read says `approved`, then
 *   arbitrates from the fresh GET that follows (the POST payload alone
 *   never decides money state).
 * - POST /payments/capture: shipment-linked full/partial capture
 *   {order_id, total_amount, shipping_info{shipped_at, shipping_company,
 *   tracking_number?, tracking_url?}, items[]}. NOT captured = NOT settled
 *   (docs). No documented idempotency key ⇒ an ambiguous capture (transport
 *   failure / 5xx) is NEVER blindly resent; it is resolved by a fresh read /
 *   webhook / reconciliation instead, and only a provably-not-captured
 *   state earns a bounded resend.
 * - POST /payments/simplified-refund/{order_id}: refund of CAPTURED money
 *   only ({total_amount, comment REQUIRED}). Response: refund_id,
 *   capture_id, order_id, status (fully_refunded|partially_refunded),
 *   refunded_amount. merchant_refund_id is NOT sent: the refund contract
 *   (PaymentGatewayContract::refund) carries no local ledger id and the
 *   provider refund_id is recorded by PaymentRefundService's ledger instead.
 * - POST /orders/{order_id}/cancel: valid ONLY in `authorised` (409
 *   transition_not_allowed from approved/captured — doc'd example). Used as
 *   the VOID path for a full-amount refund request against an authorised-
 *   uncaptured order, and by cancelAuthorised().
 * - POST /pre-checkout/v1/eligibility: {order:{amount,currency},
 *   customer:{phone_number,email?}} → {is_eligible}. DOCUMENTED 200ms
 *   budget, fail-open (timeout/error ⇒ show Tamara); missing phone ⇒
 *   eligible; eligibility ≠ approval.
 * - Amount semantics: the OpenAPI type annotations vary (number|string)
 *   while every official example serializes amounts as numeric JSON values —
 *   this adapter serializes amounts as numbers carrying the exact currency
 *   exponent (CurrencyPrecision::formatForGateway output cast to float:
 *   exact round-trip for ≤3-decimal values). COMPARISONS never use float
 *   arithmetic — toMinorUnits() integer math. KWD/BHD/OMR carry 3 decimals.
 *
 * Money-moment mapping (local completion):
 *   a fresh read of `authorised` (and its captured/refunded extensions)
 *   ⇒ paid — the canonical completion service runs EXACTLY ONCE at
 *   authorisation; captures/refunds afterwards are ledger bookkeeping on
 *   the transaction row. Every actionable state arbitrates through a FRESH
 *   server read; webhook/callback payloads are wake-ups only (Tabby M3
 *   lesson applied from day 1) — no competing state machine, no second
 *   completion authority.
 *
 * ORDER COMPOSITION (checkout payload):
 *   The payload uses Tamara's documented composition semantics:
 *       Σ items.total_amount + tax_amount + shipping_amount − discount.amount
 *       == total_amount
 *   Mirroring the ALREADY-ACCEPTED Tabby precedent, the payload carries ONE
 *   synthetic item line (the whole order): itemizing per product would
 *   require line-level tax/discount decomposition semantics this codebase
 *   does not expose consistently (order-level columns carry promotion/coupon
 *   and tax aggregates). The line is the exact gateway amount, so the
 *   composition holds by construction — never a rounded guess. Captures use
 *   the same shape with the ACTUAL shipped amount (fulfillment-scoped).
 *   Revisit trigger: finance needs per-product settlement reporting.
 *
 * Hygiene (mirrors TabbyGateway): persisted/logged provider payloads pass
 * through allowlists (no buyer PII, no raw provider internals); error
 * messages are truncated and token-material redacted; TLS verification is
 * always on; logs carry ids/statuses only — never tokens or raw bodies.
 */
class TamaraGateway implements PaymentGatewayContract, AuthorisationGatewayContract
{
    /**
     * Fresh-read allowlist: technical fields only. The order object carries
     * consumer PII (consumer/addresses/shipping_address) — stripped at the
     * source; capture/refund id blocks stay solely as reconciliation
     * evidence.
     */
    private const ORDER_ALLOWLIST = [
        'order_id',
        'checkout_id',
        'status',
        'order_reference_id',
        'order_number',
        'total_amount',
        'shipping_amount',
        'tax_amount',
        'discount',
        'amounts',
        'payment_type',
        'instalments',
    ];

    /**
     * Fresh-read statuses that mean "never took / will never take money" ON
     * A SUCCESSFUL SERVER READ (doc'd negative exits). `updated` (partial
     * cancel remainder) deliberately stays pending.
     */
    private const TERMINAL_FAILURE_STATUSES = ['declined', 'expired', 'canceled'];

    /**
     * Fresh-read statuses where money is provably captured ≥ partially.
     */
    private const CAPTURED_STATUSES = ['partially_captured', 'fully_captured'];

    /**
     * Fresh-read statuses where captured money has begun to be returned.
     */
    private const REFUNDED_STATUSES = ['partially_refunded', 'fully_refunded'];

    public function __construct(
        private PaymentCurrencyResolver $currencyResolver,
        private CustomerContactResolver $customerContactResolver,
    ) {}

    // -----------------------------------------------------------------
    // PaymentGatewayContract
    // -----------------------------------------------------------------

    public function name(): string
    {
        return 'Tamara';
    }

    public function code(): string
    {
        return 'tamara';
    }

    public function isConfigured(): bool
    {
        return $this->apiToken() !== ''
            && $this->notificationToken() !== ''
            && $this->baseUrl() !== '';
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        $currency = strtoupper(trim($currencyCode));

        // Doc'd enum is closed: an out-of-enum currency can never be sent.
        if (!in_array($currency, ['SAR', 'AED', 'BHD', 'KWD', 'OMR'], true)) {
            return false;
        }

        return in_array($currency, $this->supportedCurrencies(), true);
    }

    /**
     * Checkout session creation (POST /checkout).
     *
     * The redirect URLs are the canonical server-decided callback (same
     * decision as the Tabby adapter): success = failure = cancel = the
     * checkout callback route. The browser redirect is an untrusted hint;
     * every outcome is decided by a server-side re-verification.
     *
     * order_reference_id is a FRESH UUID per session: the go-live checklist
     * explicitly flags order_reference_id reuse as a mapping/reconciliation
     * hazard ("Is the merchant order_reference_id being reused in any
     * case?"), and checkout retries would otherwise reuse it. The mapping
     * is persisted in the transaction's allowlisted gateway_response; the
     * provider-side lookup key is Tamara's order_id (gateway_transaction_id).
     */
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

        $orderReferenceId = (string) Str::uuid();
        $payload = $this->checkoutPayload($order, $orderCurrency, $amount, $callbackUrl, $orderReferenceId);

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

        $orderId = $this->stringOrNull($data['order_id'] ?? null);
        $checkoutUrl = $this->stringOrNull($data['checkout_url'] ?? null);
        $sessionStatus = $this->stringOrNull($data['status'] ?? null);

        // Tamara's docs: store order_id and redirect to checkout_url. Without
        // BOTH there is no attributable payment and no customer entry point —
        // fail closed (never fabricate a redirect).
        if ($orderId === null || $checkoutUrl === null) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
                rawResponse: $this->sanitizeSession($data, $orderId, $checkoutUrl, $sessionStatus, $orderReferenceId),
            );
        }

        return new GatewayResult(
            success: true,
            redirectUrl: $checkoutUrl,
            gatewayTransactionId: $orderId,
            amount: $amount,
            currency: $orderCurrency,
            status: 'pending',
            rawResponse: $this->sanitizeSession($data, $orderId, $checkoutUrl, $sessionStatus, $orderReferenceId),
        );
    }

    /**
     * Fresh-read verifier/arbitrator — the single server-authoritative path
     * for callbacks, webhooks and reconciliation.
     *
     * `approved` triggers ONE authorise attempt (guarded by the fresh read
     * itself), then arbitrates from the fresh GET that follows — whatever
     * the authorise POST answered.
     */
    public function verifyPayment(string $gatewayTransactionId): GatewayResult
    {
        $orderId = trim($gatewayTransactionId);

        if ($orderId === '' || strlen($orderId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $orderId)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway transaction id',
            );
        }

        $data = $this->fetchOrder($orderId);

        if (!is_array($data)) {
            // Ambiguous read (transport failure / provider error): deduce
            // NOTHING. Fail-closed NON-TERMINAL so no irreversible local
            // business state may be decided on an unread truth.
            return new GatewayResult(
                success: false,
                status: 'pending',
                gatewayTransactionId: $orderId,
                errorMessage: $data instanceof GatewayResult ? $data->errorMessage : 'Payment gateway error',
            );
        }

        return $this->mapOrder($data, $orderId);
    }

    /**
     * Canonical admin refund path (called by PaymentRefundService — untouched
     * in this task — after its per-order replay ledger + remaining-amount
     * guards).
     *
     * FENCE (fresh read first, always):
     * - `authorised` + nothing captured/refunded:
     *     request == the full provider total ⇒ POST cancel (provider VOID —
     *     recorded as the honest provider state, never labelled a refund);
     *     any other amount ⇒ refused (simplified-refund is valid only for
     *     captured orders — doc constraint).
     * - captured family (incl. partial captures/refunds): remaining =
     *     captured − refunded (minor units); over ⇒ refuse; already
     *     exhausted ⇒ refuse; else ⇒ POST simplified-refund and record
     *     {refund_id, capture_id, status, refunded_amount}.
     * - UNREADABLE PROVIDER TRUTH ⇒ pending non-terminal (never a secrecy
     *     decision on unread money state).
     */
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
            // Only money the local system has seen settled-in is refund
            // material: paid or partially-refunded rows. A later pending/
            // failed attempt carrying a gateway id must never shadow the
            // refundable row against the fresh provider read below.
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->whereNotNull('gateway_transaction_id')
            ->latest()
            ->first();

        if (!$transaction || !$transaction->gateway_transaction_id) {
            return new GatewayResult(
                success: false,
                errorMessage: 'No refundable transaction found for this order',
            );
        }

        $orderId = trim((string) $transaction->gateway_transaction_id);

        if ($orderId === '' || strlen($orderId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $orderId)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway transaction id',
            );
        }

        $fresh = $this->fetchOrder($orderId);

        if (!is_array($fresh)) {
            return new GatewayResult(
                success: false,
                status: 'pending',
                gatewayTransactionId: $orderId,
                errorMessage: 'Payment gateway error',
            );
        }

        $providerStatus = strtolower(trim((string) ($fresh['status'] ?? '')));
        $currency = $this->orderCurrency($fresh, $orderCurrency);

        if ($currency !== $orderCurrency) {
            // Provider-side currency is the truth; a mismatch must never be
            // reconciled over.
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $orderId,
                errorMessage: 'Payment amount or currency mismatch',
                rawResponse: $this->sanitizeOrder($fresh),
            );
        }

        $capturedMinor = CurrencyPrecision::toMinorUnits($this->amountAmount($fresh, 'amounts.captured_amount'), $orderCurrency);
        $refundedMinor = CurrencyPrecision::toMinorUnits($this->amountAmount($fresh, 'amounts.refunded_amount'), $orderCurrency);
        $totalMinor = CurrencyPrecision::toMinorUnits($this->amountAmount($fresh, 'total_amount'), $orderCurrency);
        $requestedMinor = CurrencyPrecision::toMinorUnits($amount, $orderCurrency);

        if ($providerStatus === 'authorised' && $capturedMinor === 0 && $refundedMinor === 0) {
            if ($requestedMinor !== $totalMinor) {
                // Doc constraint: cancel operates on the whole authorised
                // amount; partial refunds exist only post-capture.
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    status: 'pending',
                    errorMessage: 'Partial refund of an authorised (uncaptured) Tamara order is not supported; captured-money refunds only',
                    rawResponse: $this->sanitizeOrder($fresh),
                );
            }

            $cancelResponse = $this->request('post', '/orders/' . urlencode($orderId) . '/cancel', [
                'total_amount' => $this->amountObject($totalMinor, $orderCurrency),
            ]);

            if (!$cancelResponse instanceof \Illuminate\Http\Client\Response) {
                return $cancelResponse; // fail-closed from request()
            }

            if (!$cancelResponse->successful()) {
                // 409 transition_not_allowed (captured meanwhile / already
                // canceled) ⇒ arbitrate from the fresh read, never the body.
                $after = $this->fetchOrder($orderId);

                if (is_array($after)) {
                    $afterStatus = strtolower(trim((string) ($after['status'] ?? '')));

                    if ($afterStatus === 'canceled') {
                        return new GatewayResult(
                            success: true,
                            gatewayTransactionId: $orderId,
                            status: 'cancelled',
                            amount: $amount,
                            currency: $orderCurrency,
                            rawResponse: $this->sanitizeOrder($after) + ['_outcome' => 'cancel_confirmed'],
                        );
                    }

                    if (in_array($afterStatus, self::CAPTURED_STATUSES, true) || in_array($afterStatus, self::REFUNDED_STATUSES, true)) {
                        return new GatewayResult(
                            success: false,
                            gatewayTransactionId: $orderId,
                            status: 'pending',
                            errorMessage: 'Order was captured before cancellation; refund via captured money instead',
                            rawResponse: $this->sanitizeOrder($after),
                        );
                    }
                }

                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    status: 'pending',
                    errorMessage: $this->errorMessageFrom($cancelResponse, 'Cancellation request failed'),
                );
            }

            return new GatewayResult(
                success: true,
                gatewayTransactionId: $orderId,
                status: 'cancelled',
                amount: $amount,
                currency: $orderCurrency,
                rawResponse: $this->sanitizeOperationResponse((array) $cancelResponse->json()) + ['_outcome' => 'cancel_confirmed'],
            );
        }

        if (in_array($providerStatus, self::CAPTURED_STATUSES, true) || in_array($providerStatus, self::REFUNDED_STATUSES, true)) {
            $remainingMinor = max(0, $capturedMinor - $refundedMinor);

            if ($remainingMinor === 0) {
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    errorMessage: 'Nothing left to refund on this Tamara order',
                    rawResponse: $this->sanitizeOrder($fresh),
                );
            }

            if ($requestedMinor > $remainingMinor) {
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    errorMessage: 'Refund exceeds the remaining captured amount',
                    rawResponse: $this->sanitizeOrder($fresh),
                );
            }

            $payload = [
                'total_amount' => $this->amountObject($requestedMinor, $orderCurrency),
                // REQUIRED by the endpoint schema — sanitized free text with
                // a plain fallback.
                'comment' => mb_substr(strip_tags(trim((string) ($reason ?? ''))) ?: 'Refund', 0, 200),
            ];

            $response = $this->request('post', '/payments/simplified-refund/' . urlencode($orderId), $payload);

            if (!$response instanceof \Illuminate\Http\Client\Response) {
                return $response; // fail-closed from request()
            }

            if (!$response->successful()) {
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    errorMessage: $this->errorMessageFrom($response, 'Refund request failed'),
                );
            }

            $data = $response->json();

            if (!is_array($data) || $this->stringOrNull($data['refund_id'] ?? null) === null) {
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    errorMessage: 'Invalid gateway response',
                    rawResponse: is_array($data) ? $this->sanitizeOperationResponse($data) : null,
                );
            }

            return new GatewayResult(
                success: true,
                gatewayTransactionId: $orderId,
                status: $this->stringOrNull($data['status'] ?? null) ?? 'refunded',
                amount: $amount,
                currency: $orderCurrency,
                rawResponse: $this->sanitizeOperationResponse($data) + ['_outcome' => 'refund_recorded'],
            );
        }

        // Not refundable in the current provider state: record honestly,
        // never pretend. (new/approved/updated/unrecognized)
        return new GatewayResult(
            success: false,
            gatewayTransactionId: $orderId,
            status: 'pending',
            errorMessage: $providerStatus !== ''
                ? 'Tamara order not refundable in state: ' . $providerStatus
                : 'Tamara order state unreadable',
            rawResponse: $this->sanitizeOrder($fresh),
        );
    }

    // -----------------------------------------------------------------
    // AuthorisationGatewayContract
    // -----------------------------------------------------------------

    /**
     * Explicit authorise entry point (capability contract): the fresh-read
     * arbiter in verifyPayment IS the authorisation — `approved` ⇒ one
     * authorise attempt ⇒ fresh read decides.
     */
    public function authorise(string $gatewayTransactionId): GatewayResult
    {
        return $this->verifyPayment($gatewayTransactionId);
    }

    /**
     * Shipment-linked capture (POST /payments/capture).
     *
     * $shipment = [
     *   'fulfillment_id'   => ?int
     *   'shipped_at'       => string (iso8601)
     *   'shipping_company' => string
     *   'tracking_number'  => ?string
     *   'tracking_url'     => ?string
     *   'items' => [
     *      ['reference_id' => string, 'sku' => string, 'name' => string,
     *       'quantity' => int|float, 'unit_price' => float,
     *       'tax_amount' => float, 'discount_amount' => ?float,
     *       'total_amount' => float], ...
     *   ]
     * ]
     *
     * Amount integrity: capture total = Σ item total_amount, guarded in
     * minor units ≤ authorised − already-captured. Duplicate protection is
     * LAYERED: (1) the caller dedupes by fulfillment_id/capture_id under
     * the transaction lock, (2) this method fences against a fresh read
     * BEFORE sending (an already-captured provider state turns the request
     * into a recorded no-op), (3) an ambiguous outcome (transport error /
     * 5xx) is NEVER blindly resent (no provider idempotency key) — a bounded
     * resend happens ONLY after a fresh read proving the order is still
     * `authorised` with nothing captured.
     */
    public function captureFromShipment(Order $order, string $gatewayTransactionId, array $shipment): GatewayResult
    {
        $orderCurrency = $this->currencyResolver->forOrder($order);

        if (!$this->supportsCurrency($orderCurrency)) {
            return new GatewayResult(
                success: false,
                errorMessage: __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $orderCurrency]),
            );
        }

        $orderId = trim($gatewayTransactionId);

        if ($orderId === '' || strlen($orderId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $orderId)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway transaction id',
            );
        }

        $items = $shipment['items'] ?? [];

        if (!is_array($items) || $items === []) {
            return new GatewayResult(
                success: false,
                errorMessage: 'No shipped items provided for capture',
            );
        }

        $totalMinor = 0;
        $decimals = CurrencyPrecision::decimalsFor($orderCurrency);

        foreach ($items as $item) {
            if (!is_array($item)) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'Invalid shipment item payload',
                );
            }

            $itemTotalMinor = CurrencyPrecision::toMinorUnits(
                is_numeric($item['total_amount'] ?? null) ? (float) $item['total_amount'] : 0.0,
                $orderCurrency,
            );

            if ($itemTotalMinor <= 0) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'Invalid shipment item amount',
                );
            }

            $totalMinor += $itemTotalMinor;
        }

        $payload = [
            'order_id' => $orderId,
            'total_amount' => $this->amountObject($totalMinor, $orderCurrency),
            'shipping_info' => [
                'shipped_at' => $this->boundedText($shipment['shipped_at'] ?? null, 40) ?: now()->toIso8601String(),
                'shipping_company' => $this->boundedText($shipment['shipping_company'] ?? null, 100) ?: 'Standard',
            ],
            'items' => $this->captureItemsPayload($items, $orderCurrency),
        ];

        $trackingNumber = $this->stringOrNull($shipment['tracking_number'] ?? null);

        if ($trackingNumber !== null) {
            $payload['shipping_info']['tracking_number'] = mb_substr($trackingNumber, 0, 100);
        }

        $trackingUrl = $this->stringOrNull($shipment['tracking_url'] ?? null);

        if ($trackingUrl !== null) {
            $payload['shipping_info']['tracking_url'] = mb_substr($trackingUrl, 0, 255);
        }

        // Pre-send fence (fresh read): never send a capture while the
        // provider cannot provably accept one. An UNREADABLE state is also
        // fail-closed — Tamara has no capture idempotency key and partial
        // captures are a feature, so a blind send on unseen money state
        // could double-capture. The pending answer resurfaces on the next
        // wake-up, which re-reads first.
        $pre = $this->fetchOrder($orderId);

        if (!is_array($pre)) {
            return $this->pendingCapture($orderId, $totalMinor, $decimals, $orderCurrency, 'Tamara order not capturable: provider state unreadable', null);
        }

        $preStatus = strtolower(trim((string) ($pre['status'] ?? '')));
        $preCurrency = $this->orderCurrency($pre, $orderCurrency);

        if ($preCurrency !== $orderCurrency) {
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $orderId,
                errorMessage: 'Payment amount or currency mismatch',
                rawResponse: $this->sanitizeOrder($pre),
            );
        }

        $preCaptured = CurrencyPrecision::toMinorUnits($this->amountAmount($pre, 'amounts.captured_amount'), $orderCurrency);

        if (in_array($preStatus, self::CAPTURED_STATUSES, true) || in_array($preStatus, self::REFUNDED_STATUSES, true)) {
            // Something is already captured provider-side; the CALLER's
            // ledger decides per-fulfillment bookkeeping. This adapter never
            // adds money on an order with money already captured.
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $orderId,
                status: 'pending',
                errorMessage: 'Capture already exists on the provider order (' . $preStatus . ')',
                rawResponse: $this->sanitizeOrder($pre),
            );
        }

        if ($preStatus !== 'authorised') {
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $orderId,
                status: 'pending',
                errorMessage: $preStatus !== ''
                    ? 'Tamara order not capturable in state: ' . $preStatus
                    : 'Tamara order state unreadable',
                rawResponse: $this->sanitizeOrder($pre),
            );
        }

        $authorisedMinor = CurrencyPrecision::toMinorUnits($this->amountAmount($pre, 'total_amount'), $orderCurrency);

        if ($preCaptured + $totalMinor > $authorisedMinor) {
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $orderId,
                errorMessage: 'Capture would exceed the authorised amount',
                rawResponse: $this->sanitizeOrder($pre),
            );
        }

        // One authoritative send. Retries happen ONLY on the fresh-read
        // proven scenario (authorised + zero captured), because the provider
        // exposes no capture idempotency key.
        $response = $this->request('post', '/payments/capture', $payload);

        if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
            $data = $response->json();
            $isDataArray = is_array($data);
            $captureId = $isDataArray ? $this->stringOrNull($data['capture_id'] ?? null) : null;

            if ($captureId !== null) {
                // 2xx + capture_id = provider-accepted capture.
                return new GatewayResult(
                    success: true,
                    gatewayTransactionId: $orderId,
                    status: 'capture_recorded',
                    amount: $totalMinor / (10 ** $decimals),
                    currency: $orderCurrency,
                    rawResponse: ($isDataArray ? $this->sanitizeOperationResponse($data) : []) + [
                        '_outcome' => 'captured',
                        '_capture_id' => $captureId,
                    ],
                );
            }

            // 2xx without a capture id is ambiguous by itself: arbitrate on
            // the fresh read and record the honest outcome.
            $fresh = $this->fetchOrder($orderId);

            if (is_array($fresh) && in_array(strtolower(trim((string) ($fresh['status'] ?? ''))), self::CAPTURED_STATUSES, true)) {
                return new GatewayResult(
                    success: true,
                    gatewayTransactionId: $orderId,
                    status: 'capture_recorded',
                    amount: $totalMinor / (10 ** $decimals),
                    currency: $orderCurrency,
                    rawResponse: $this->sanitizeOrder($fresh) + ['_outcome' => 'captured_fresh_confirmed'],
                );
            }

            return $this->pendingCapture($orderId, $totalMinor, $decimals, $orderCurrency, 'Payment capture not confirmed yet', is_array($fresh) ? $this->sanitizeOrder($fresh) : null);
        }

        if ($response instanceof \Illuminate\Http\Client\Response) {
            // Non-2xx: the fresh read decides (captured ⇒ benign dedupe,
            // moved-out-of-authorised ⇒ honest refusal, provably-still-
            // authorised-uncaptured ⇒ ONE bounded resend).
            return $this->arbitrateCaptureFailure($orderId, $items, $shipment, $orderCurrency, $totalMinor, $response);
        }

        // Transport failure on the POST: unresolvable safely here (the
        // provider may or may not have accepted) — pending, never terminal,
        // never blind-resend. The next fresh read (webhook / reconciliation /
        // caller re-entry) must re-read FIRST and only then decide.
        return $this->pendingCapture($orderId, $totalMinor, $decimals, $orderCurrency, 'Payment capture not confirmed yet', null);
    }

    /**
     * Provider cancellation of an authorised (never captured) order.
     *
     * 409 (captured meanwhile / already canceled) ⇒ fresh-read arbitration;
     * an already-canceled read is a benign dedupe; an already-captured
     * order is a HARD refusal (cancellation is not a refund). Local order
     * state is NEVER touched here (OrderService owns it).
     */
    public function cancelAuthorised(Order $order, string $gatewayTransactionId): GatewayResult
    {
        $orderCurrency = $this->currencyResolver->forOrder($order);

        if (!$this->supportsCurrency($orderCurrency)) {
            return new GatewayResult(
                success: false,
                errorMessage: __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $orderCurrency]),
            );
        }

        $orderId = trim($gatewayTransactionId);

        if ($orderId === '' || strlen($orderId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $orderId)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway transaction id',
            );
        }

        $fresh = $this->fetchOrder($orderId);

        if (!is_array($fresh)) {
            return new GatewayResult(
                success: false,
                status: 'pending',
                gatewayTransactionId: $orderId,
                errorMessage: 'Payment gateway error',
            );
        }

        $providerStatus = strtolower(trim((string) ($fresh['status'] ?? '')));

        if ($providerStatus === 'authorised') {
            $capturedMinor = CurrencyPrecision::toMinorUnits($this->amountAmount($fresh, 'amounts.captured_amount'), $orderCurrency);

            if ($capturedMinor > 0) {
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    errorMessage: 'Order was captured before cancellation; refund via captured money instead',
                    rawResponse: $this->sanitizeOrder($fresh),
                );
            }

            $response = $this->request('post', '/orders/' . urlencode($orderId) . '/cancel', [
                'total_amount' => $this->amountObject(
                    CurrencyPrecision::toMinorUnits($this->amountAmount($fresh, 'total_amount'), $orderCurrency),
                    $orderCurrency,
                ),
            ]);

            if (!$response instanceof \Illuminate\Http\Client\Response) {
                return $response; // fail-closed from request()
            }

            if (!$response->successful()) {
                $after = $this->fetchOrder($orderId);

                if (is_array($after)) {
                    $afterStatus = strtolower(trim((string) ($after['status'] ?? '')));

                    if ($afterStatus === 'canceled') {
                        return new GatewayResult(
                            success: true,
                            gatewayTransactionId: $orderId,
                            status: 'cancelled',
                            rawResponse: $this->sanitizeOrder($after) + ['_outcome' => 'cancel_confirmed'],
                        );
                    }

                    if (in_array($afterStatus, self::CAPTURED_STATUSES, true)) {
                        return new GatewayResult(
                            success: false,
                            gatewayTransactionId: $orderId,
                            status: 'pending',
                            errorMessage: 'Order was captured before cancellation; refund via captured money instead',
                            rawResponse: $this->sanitizeOrder($after),
                        );
                    }
                }

                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    status: 'pending',
                    errorMessage: $this->errorMessageFrom($response, 'Cancellation request failed'),
                );
            }

            return new GatewayResult(
                success: true,
                gatewayTransactionId: $orderId,
                status: 'cancelled',
                rawResponse: $this->sanitizeOperationResponse((array) $response->json()) + ['_outcome' => 'cancel_requested'],
            );
        }

        if ($providerStatus === 'canceled') {
            return new GatewayResult(
                success: true,
                gatewayTransactionId: $orderId,
                status: 'cancelled',
                rawResponse: $this->sanitizeOrder($fresh) + ['_outcome' => 'cancel_confirmed'],
            );
        }

        if (in_array($providerStatus, self::CAPTURED_STATUSES, true) || in_array($providerStatus, self::REFUNDED_STATUSES, true)) {
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $orderId,
                errorMessage: 'Capture already exists; cancellation is not a refund',
                rawResponse: $this->sanitizeOrder($fresh),
            );
        }

        return new GatewayResult(
            success: false,
            gatewayTransactionId: $orderId,
            status: 'pending',
            errorMessage: 'Tamara order not cancellable in state: ' . ($providerStatus !== '' ? $providerStatus : 'unreadable'),
            rawResponse: $this->sanitizeOrder($fresh),
        );
    }

    /**
     * Tamara pre-checkout eligibility (POST /pre-checkout/v1/eligibility).
     *
     * DOCUMENTED contract: 200ms timeout budget; fail-open (timeout, error,
     * malformed ⇒ eligible) because the check must never block checkout;
     * missing phone ⇒ eligible per docs; eligibility ≠ approval.
     */
    public function isEligible(float $amount, string $currency, ?string $phone, ?string $email): bool
    {
        $currency = strtoupper(trim($currency));

        if (!$this->isConfigured() || !$this->supportsCurrency($currency)) {
            // Structurally not offerable — fail-open: availability is the
            // registry's (canInitiate) decision, not this feature check's.
            return true;
        }

        try {
            // The DOCUMENTED budget is sub-second (200ms). Laravel's
            // ->timeout() is int-only, so the budget is enforced through
            // Guzzle options, which accept float seconds.
            $timeoutMs = max(1, (int) config('payment.gateways.tamara.eligibility_timeout_ms', 200));

            $response = Http::withToken($this->apiToken())
                ->acceptJson()
                ->withOptions([
                    'timeout' => $timeoutMs / 1000.0,
                    'connect_timeout' => $timeoutMs / 1000.0,
                ])
                ->post($this->baseUrl() . '/pre-checkout/v1/eligibility', [
                    'order' => [
                        'amount' => (float) CurrencyPrecision::formatForGateway($amount, $currency),
                        'currency' => $currency,
                    ],
                    'customer' => [
                        'phone_number' => $this->boundedText($phone, 32) ?? '',
                        'email' => $this->boundedText($email, 191) ?? '',
                    ],
                ]);

            if (!$response->successful()) {
                return true; // fail-open (budget breach / 4xx / 5xx)
            }

            $body = $response->json();

            if (!is_array($body) || !array_key_exists('is_eligible', $body)) {
                return true; // fail-open
            }

            return (bool) $body['is_eligible'];
        } catch (\Throwable) {
            return true; // fail-open
        }
    }

    // -----------------------------------------------------------------
    // Order mapping internals (fresh-read authority)
    // -----------------------------------------------------------------

    /**
     * Fresh-order-read mapping — the ONLY money-state authority.
     *
     * money family  : authorised / *_captured / *_refunded ⇒ paid
     *                 (completion runs once at authorisation; captures and
     *                 refunds are ledger bookkeeping the ledgers record)
     * approved      : one guarded authorise attempt, then fresh arbitration
     * negative      : declined / expired / canceled ⇒ failed
     * updated       : pending (partial-cancel remainder; never auto-decide)
     * new/unknown   : pending
     *
     * $authoriseAttempted bounds the authorise POST to ONE per verifyPayment
     * invocation: a provider stuck replying `approved` after an accepted
     * authorise can never spin this into an unbounded loop — the recursion
     * depth is capped and the pending answer surfaces to the caller (the
     * next wake-up re-enters with a fresh budget).
     */
    private function mapOrder(array $data, string $orderId, bool $authoriseAttempted = false): GatewayResult
    {
        $status = strtolower(trim((string) ($data['status'] ?? '')));
        $currency = $this->orderCurrency($data, null);
        $amount = is_numeric($data['total_amount']['amount'] ?? null) ? (float) $data['total_amount']['amount'] : null;
        $raw = $this->sanitizeOrder($data);

        $paymentResult = function (bool $success, string $status, ?string $message = null) use ($orderId, $amount, $currency, $raw): GatewayResult {
            return new GatewayResult(
                success: $success,
                gatewayTransactionId: $orderId,
                amount: $amount,
                currency: $currency !== '' ? $currency : null,
                status: $status,
                errorMessage: $message,
                rawResponse: $raw,
            );
        };

        if ($status === 'authorised' || in_array($status, self::CAPTURED_STATUSES, true) || in_array($status, self::REFUNDED_STATUSES, true)) {
            return $paymentResult(true, 'paid');
        }

        if ($status === 'approved' && !$authoriseAttempted) {
            // Merchant-initiated authorisation per the doc'd flow. At MOST
            // one authorise POST per fresh arbiter entry; whichever way the
            // POST answers, the NEXT fresh read decides.
            $this->request('post', '/orders/' . urlencode($orderId) . '/authorise');

            $fresh = $this->fetchOrder($orderId);

            if (!is_array($fresh)) {
                return $paymentResult(false, 'pending', 'Payment gateway error');
            }

            return $this->mapOrder($fresh, $orderId, authoriseAttempted: true);
        }

        if (in_array($status, self::TERMINAL_FAILURE_STATUSES, true)) {
            return $paymentResult(false, 'failed', __('message.ERROR.PAYMENT_PROVIDER_DECLINED'));
        }

        if ($status === 'updated') {
            return $paymentResult(false, 'pending', 'Remaining amounts pending after order modification');
        }

        if ($status === 'new') {
            return $paymentResult(false, 'pending', 'Customer did not complete checkout yet');
        }

        // Unrecognized status (provider may add new values): never a
        // terminal decision on ambiguity.
        return $paymentResult(false, 'pending', 'Payment not completed yet');
    }

    // -----------------------------------------------------------------
    // Capture arbitration helpers
    // -----------------------------------------------------------------

    /**
     * Non-2xx capture outcome arbitration (fresh read decides, never the
     * error body). A bounded resend is attempted ONLY when the fresh read
     * proves the order is still `authorised` with zero captured — the only
     * state where a second capture POST cannot double-capture. Anything
     * ambiguous becomes pending.
     */
    private function arbitrateCaptureFailure(
        string $orderId,
        array $items,
        array $shipment,
        string $orderCurrency,
        int $totalMinor,
        \Illuminate\Http\Client\Response $response,
    ): GatewayResult {
        $decimals = CurrencyPrecision::decimalsFor($orderCurrency);
        $fresh = $this->fetchOrder($orderId);

        if (is_array($fresh)) {
            $freshStatus = strtolower(trim((string) ($fresh['status'] ?? '')));

            if (in_array($freshStatus, self::CAPTURED_STATUSES, true) || in_array($freshStatus, self::REFUNDED_STATUSES, true)) {
                // Captured meanwhile (concurrent capture/race): this attempt
                // must not add money twice — pending so the caller records
                // the provider trail and never re-posts this delta blindly.
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    status: 'pending',
                    errorMessage: 'Capture already exists on the provider order (' . $freshStatus . ')',
                    rawResponse: $this->sanitizeOrder($fresh),
                );
            }

            if ($freshStatus !== 'authorised') {
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $orderId,
                    status: 'pending',
                    errorMessage: $freshStatus !== ''
                        ? 'Tamara order not capturable in state: ' . $freshStatus
                        : 'Tamara order state unreadable',
                    rawResponse: $this->sanitizeOrder($fresh),
                );
            }

            $captureAttempts = max(1, min(5, (int) config('payment.gateways.tamara.capture_attempts', 2)));

            if ($captureAttempts <= 1) {
                return $this->pendingCapture($orderId, $totalMinor, $decimals, $orderCurrency, 'Payment capture not confirmed yet', $this->sanitizeOrder($fresh));
            }

            // Provably still authorised and uncaptured: the bounded resend
            // is safe (fresh read proves nothing was captured).
            $payload = $this->capturePayloadFromShipment($orderId, $items, $shipment, $orderCurrency);
            $retry = $this->request('post', '/payments/capture', $payload);

            if ($retry instanceof \Illuminate\Http\Client\Response && $retry->successful()) {
                $retryData = $retry->json();
                $retryCaptureId = is_array($retryData) ? $this->stringOrNull($retryData['capture_id'] ?? null) : null;

                if ($retryCaptureId !== null) {
                    return new GatewayResult(
                        success: true,
                        gatewayTransactionId: $orderId,
                        status: 'capture_recorded',
                        amount: $totalMinor / (10 ** $decimals),
                        currency: $orderCurrency,
                        rawResponse: $this->sanitizeOperationResponse($retryData) + [
                            '_outcome' => 'captured',
                            '_capture_id' => $retryCaptureId,
                            '_attempts' => 2,
                        ],
                    );
                }
            }

            return $this->pendingCapture(
                $orderId,
                $totalMinor,
                $decimals,
                $orderCurrency,
                'Payment capture not confirmed yet',
                $retry instanceof \Illuminate\Http\Client\Response && is_array($retry->json())
                    ? $this->sanitizeOperationResponse($retry->json())
                    : null,
            );
        }

        // Provider truth unreadable after a refused POST: ambiguous —
        // pending (never a fabricated refusal and never a blind resend).
        return $this->pendingCapture(
            $orderId,
            $totalMinor,
            $decimals,
            $orderCurrency,
            'Payment capture not confirmed yet',
            is_array($response->json()) ? $this->sanitizeOperationResponse($response->json()) : null,
        );
    }

    private function capturePayloadFromShipment(string $orderId, array $items, array $shipment, string $currency): array
    {
        $payload = [
            'order_id' => $orderId,
            'total_amount' => $this->amountObject(
                (int) array_sum(array_map(
                    fn (mixed $item): int|float => is_array($item) && is_numeric($item['total_amount'] ?? null)
                        ? CurrencyPrecision::toMinorUnits((float) $item['total_amount'], $currency)
                        : 0,
                    $items,
                )),
                $currency,
            ),
            'shipping_info' => [
                'shipped_at' => $this->boundedText($shipment['shipped_at'] ?? null, 40) ?: now()->toIso8601String(),
                'shipping_company' => $this->boundedText($shipment['shipping_company'] ?? null, 100) ?: 'Standard',
            ],
            'items' => $this->captureItemsPayload($items, $currency),
        ];

        $trackingNumber = $this->stringOrNull($shipment['tracking_number'] ?? null);

        if ($trackingNumber !== null) {
            $payload['shipping_info']['tracking_number'] = mb_substr($trackingNumber, 0, 100);
        }

        $trackingUrl = $this->stringOrNull($shipment['tracking_url'] ?? null);

        if ($trackingUrl !== null) {
            $payload['shipping_info']['tracking_url'] = mb_substr($trackingUrl, 0, 255);
        }

        return $payload;
    }

    private function pendingCapture(string $orderId, int|float $amountMinor, int $decimals, string $currency, string $message, ?array $raw): GatewayResult
    {
        return new GatewayResult(
            success: false,
            status: 'pending',
            gatewayTransactionId: $orderId,
            amount: $amountMinor > 0 ? $amountMinor / (10 ** $decimals) : null,
            currency: $currency,
            errorMessage: $message,
            rawResponse: $raw,
        );
    }

    // -----------------------------------------------------------------
    // Checkout payload
    // -----------------------------------------------------------------

    /**
     * Checkout payload per the doc'd required fields (§3 inventory:
     * total/consumer/shipping_address/items/country_code/risk_assessment —
     * the shipping_address block uses order-identity names + the SAME
     * saved-address snapshot keys as the Tabby precedent). The SINGLE
     * synthetic item line keeps the documented composition exact by
     * construction:
     *     Σ items (the line = gateway amount) + tax_amount + shipping_amount
     *     − discount == total_amount
     * (order-level totals come from orderLevelTotals() — the verified
     * OrderCreationService composition).
     */
    private function checkoutPayload(Order $order, string $currency, float $amount, string $callbackUrl, string $orderReferenceId): array
    {
        $totalMinor = CurrencyPrecision::toMinorUnits($amount, $currency);
        [$taxMinor, $shippingMinor, $discountMinor] = $this->orderLevelTotals($order, $currency);

        $itemsMinor = max(0, $totalMinor - $taxMinor - $shippingMinor + $discountMinor);

        $payload = [
            'order_reference_id' => $orderReferenceId,
            'order_number' => mb_substr(trim((string) ($order->order_number ?? $order->id)), 0, 50),
            'total_amount' => $this->amountObject($totalMinor, $currency),
            'shipping_amount' => $this->amountObject($shippingMinor, $currency),
            'tax_amount' => $this->amountObject($taxMinor, $currency),
            'items' => [
                $this->syntheticOrderItem($order, $currency, $itemsMinor),
            ],
            'consumer' => [
                'email' => $this->customerContactResolver->emailForGateway($order),
                'first_name' => mb_substr(trim($this->firstName($order)), 0, 100) ?: 'Customer',
                'last_name' => mb_substr(trim($this->lastName($order)), 0, 100) ?: 'Meem',
                'phone_number' => $this->boundedText($order->user_phone ?? null, 32) ?? '',
            ],
            'shipping_address' => $this->shippingAddress($order),
            'country_code' => $this->tamaraCountryCode($order),
            'description' => mb_substr('Order ' . trim((string) ($order->order_number ?? $order->id)), 0, 250),
            'merchant_url' => [
                'success' => $callbackUrl,
                'cancel' => $callbackUrl,
                'failure' => $callbackUrl,
            ],
            // Tamara plan selection: PAY_BY_INSTALMENTS is the value Tamara's
            // own get-order response examples carry for installe orders;
            // both knobs are config-overridable for merchant variability
            // (env-resolvable, documented UNVERIFIED against a live sandbox
            // re-validation of the exact enum casing).
            'payment_type' => mb_substr(trim((string) config('payment.gateways.tamara.payment_type', 'PAY_BY_INSTALMENTS')), 0, 40),
            'instalments' => (int) config('payment.gateways.tamara.instalments', 3),
            'platform' => mb_substr(trim((string) config('payment.gateways.tamara.platform_label', 'Meem')), 0, 100),
            'locale' => in_array(app()->getLocale(), ['ar', 'en'], true)
                ? (app()->getLocale() === 'ar' ? 'ar_SA' : 'en_US')
                : 'en_US',
            'risk_assessment' => $this->riskAssessment($order),
        ];

        if ($discountMinor > 0) {
            // Site-wide commerce discount (coupon) — docs: the discount
            // object is for customer-specific discounts/vouchers, not for
            // site-wide discounts; name = the coupon code when present.
            $payload['discount'] = [
                'name' => mb_substr(strip_tags(trim((string) ($order->coupon ?? $order->promotion_code ?? 'Discount'))), 0, 100),
                'amount' => $this->amountObject($discountMinor, $currency),
            ];
        }

        return $payload;
    }

    /**
     * Order-level tax/shipping/discount decomposition from the ORDER
     * SNAPSHOT columns (OrderCreationService formula):
     *     total_price = finalTotal + product_tax + order_tax + shipping
     *     (+ fast_shipping_fee) ; finalTotal = subtotal − promotion − coupon
     *
     * Tax = product_tax_amount + order_tax_amount (both components of the
     * authorised tamara total), shipping = shipping_price, discount =
     * coupon_discount + promotion_discount. The items line absorbs the
     * remainder so Σ stays EXACT (never a rounded guess): items = total −
     * tax − shipping + discount at minor-unit level. A snapshot whose
     * columns violate its own total (> 2dp drift with rounding tolerance)
     * fails closed at the composition check below.
     */
    private function orderLevelTotals(Order $order, string $currency): array
    {
        $toMinor = fn (mixed $value): int => CurrencyPrecision::toMinorUnits(
            is_numeric((float) $value) ? (float) $value : 0.0,
            $currency,
        );

        $totalMinor = $toMinor($order->total_price);
        $taxMinor = $toMinor($order->product_tax_amount) + $toMinor($order->order_tax_amount);
        $shippingMinor = $toMinor($order->shipping_price) + $toMinor($order->fast_shipping_fee);
        $discountMinor = $toMinor($order->coupon_discount) + $toMinor($order->promotion_discount);

        // Composition guard: total must be ≥ the raw sums after the discount
        // deduction; a provably-broken snapshot must NOT reach Tamara (they
        // validate the composition — a wrong payload is a wrong product).
        if ($totalMinor < $taxMinor + $shippingMinor - $discountMinor) {
            throw new \RuntimeException(
                'Order money composition does not match total_price; refusing Tamara checkout for order ' . (string) $order->id,
            );
        }

        return [$taxMinor, $shippingMinor, $discountMinor];
    }

    /**
     * The single synthetic item line (Tabby-precedent): name/sku/reference
     * carry the merchant order identity; the amount carries the failure-
     * exact remainder (see orderLevelTotals). The schema'd per-item fields
     * ride the SAME decomposition primitives: unit_price == the line total
     * (quantity is 1), and per-item tax/discount stay ZERO — the order's
     * tax lives in the ORDER-level tax_amount and the discount in the
     * order-level discount{} object, so no component is counted twice.
     */
    private function syntheticOrderItem(Order $order, string $currency, int $itemsMinor): array
    {
        $reference = mb_substr(trim((string) ($order->order_number ?? $order->id)), 0, 50);

        return [
            'reference_id' => $reference,
            'sku' => $reference,
            'name' => mb_substr(strip_tags('Order ' . $reference), 0, 255),
            'type' => 'Physical',
            'quantity' => 1,
            'unit_price' => $this->amountObject($itemsMinor, $currency),
            'tax_amount' => $this->amountObject(0, $currency),
            'discount_amount' => $this->amountObject(0, $currency),
            'total_amount' => $this->amountObject($itemsMinor, $currency),
        ];
    }

    /**
     * shipping_address per the create-checkout REQUIRED schema
     * {first_name, last_name, line1, city, country_code}. Identity mirrors
     * the consumer block (the order's own name); line1/city read the SAME
     * saved-address snapshot keys as the Tabby precedent (street/city),
     * and the city falls back to the order's canonical governorate name —
     * the delivery region the AREA_IN flow persists on every order. A
     * degenerate string snapshot contributes its text as line1 and an
     * EMPTY city: address truth is the order snapshot — nothing is
     * fabricated; unreadable cities fail visibly at the provider instead.
     */
    private function shippingAddress(Order $order): array
    {
        $address = is_array($order->address) ? $order->address : [];

        $street = is_string($address['street'] ?? null) ? trim((string) $address['street']) : '';

        if ($street === '' && !is_array($order->address) && $order->address !== null) {
            $street = trim((string) $order->address);
        }

        $city = is_string($address['city'] ?? null) ? trim((string) $address['city']) : '';

        if ($city === '' && $order->governorate) {
            $city = trim((string) $order->governorate->name);
        }

        return [
            'first_name' => mb_substr(trim($this->firstName($order)), 0, 100) ?: 'Customer',
            'last_name' => mb_substr(trim($this->lastName($order)), 0, 100) ?: 'Meem',
            'line1' => mb_substr($street, 0, 255),
            'city' => mb_substr($city, 0, 100),
            'country_code' => $this->tamaraCountryCode($order),
        ];
    }

    /**
     * Capture item lines: fulfillment-scoped single line (the ACTUAL shipped
     * amount per the shipment payload provided by the caller; never a blind
     * resend of the original total). See §"ORDER COMPOSITION" in the header.
     */
    private function captureItemsPayload(array $items, string $currency): array
    {
        $lines = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $q = is_numeric($item['quantity'] ?? null) ? max(1, (int) round((float) $item['quantity'])) : 1;
            $unit = is_numeric($item['unit_price'] ?? null) ? (float) $item['unit_price'] : 0.0;
            $tax = is_numeric($item['tax_amount'] ?? null) ? (float) $item['tax_amount'] : 0.0;
            $discount = is_numeric($item['discount_amount'] ?? null) ? (float) $item['discount_amount'] : 0.0;
            $total = is_numeric($item['total_amount'] ?? null) ? (float) $item['total_amount'] : 0.0;

            $lines[] = [
                'reference_id' => mb_substr((string) ($item['reference_id'] ?? ''), 0, 100),
                'sku' => mb_substr((string) ($item['sku'] ?? ''), 0, 128),
                'name' => mb_substr(strip_tags((string) ($item['name'] ?? 'Item')), 0, 255),
                'type' => 'Physical',
                'quantity' => $q,
                'unit_price' => $this->amountObject(CurrencyPrecision::toMinorUnits($unit, $currency), $currency),
                'tax_amount' => $this->amountObject(CurrencyPrecision::toMinorUnits($tax, $currency), $currency),
                'discount_amount' => $this->amountObject(CurrencyPrecision::toMinorUnits($discount, $currency), $currency),
                'total_amount' => $this->amountObject(CurrencyPrecision::toMinorUnits($total, $currency), $currency),
            ];
        }

        return $lines;
    }

    private function riskAssessment(Order $order): array
    {
        // risk_assessment REQUIRED fields per the create-checkout schema —
        // conservative merchant-side defaults; enriching signals are a
        // follow-up (revisit trigger: Tamara risk review feedback).
        $created = $order->user?->created_at ?? $order->created_at;

        return [
            'is_premium_customer' => false,
            'account_creation_date' => $created?->format('d-m-Y') ?? '01-01-2001',
            'total_order_count' => 0,
            'date_first_paid' => '01-01-2001',
            'date_last_paid' => '01-01-2001',
        ];
    }

    // -----------------------------------------------------------------
    // HTTP + normalization + sanitization
    // -----------------------------------------------------------------

    private function fetchOrder(string $orderId): mixed
    {
        $response = $this->request('get', '/orders/' . urlencode($orderId));

        if (!$response instanceof \Illuminate\Http\Client\Response) {
            return $response; // fail-closed GatewayResult from request()
        }

        if (!$response->successful()) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->errorMessageFrom($response, 'Payment gateway error'),
            );
        }

        $data = $response->json();

        return is_array($data) ? $data : new GatewayResult(
            success: false,
            errorMessage: 'Invalid gateway response',
        );
    }

    /**
     * Single HTTP seam: Bearer API token, JSON accept, 30s timeout (float
     * seconds — Guzzle-native), TLS verification always on. Returns the
     * Response, or a fail-closed GatewayResult for transport failures so
     * callers never see an exception escape.
     *
     * @return \Illuminate\Http\Client\Response|GatewayResult
     */
    private function request(string $method, string $path, ?array $payload = null): mixed
    {
        $token = $this->apiToken();
        $baseUrl = $this->baseUrl();

        if ($token === '' || $baseUrl === '') {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway is not configured',
            );
        }

        try {
            $http = Http::withToken($token)
                ->acceptJson()
                ->timeout(30);

            return $method === 'post'
                ? $http->post($baseUrl . $path, $payload ?? [])
                : $http->get($baseUrl . $path);
        } catch (\Throwable) {
            // Network/timeout: fail closed, no provider detail echoed.
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway error',
            );
        }
    }

    /**
     * Session refs only: no PII echo, no checkout-URL echo, and NEVER any
     * provider token material.
     */
    private function sanitizeSession(array $data, ?string $orderId, ?string $checkoutUrl, ?string $sessionStatus, string $orderReferenceId): ?array
    {
        $sanitized = [
            'order_id' => $orderId,
            'checkout_id' => $this->stringOrNull($data['checkout_id'] ?? null),
            'status' => $sessionStatus,
            'order_reference_id' => $orderReferenceId,
        ];

        $sanitized = array_filter($sanitized, fn ($v) => $v !== null && $v !== '');

        return $sanitized === [] ? null : $sanitized;
    }

    /**
     * Authorise/capture/cancel/refund response allowlist: ids + statuses
     * only (no echoed PII/payload blobs, no token material).
     */
    private function sanitizeOperationResponse(array $data): array
    {
        return array_filter([
            'order_id' => $this->stringOrNull($data['order_id'] ?? null),
            'capture_id' => $this->stringOrNull($data['capture_id'] ?? null),
            'refund_id' => $this->stringOrNull($data['refund_id'] ?? null),
            'status' => $this->stringOrNull($data['status'] ?? null),
            'checkout_id' => $this->stringOrNull($data['checkout_id'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Order allowlist + captures/refunds evidence blocks (reconciliation).
     */
    private function sanitizeOrder(array $data): array
    {
        $sanitized = [];

        foreach (self::ORDER_ALLOWLIST as $key) {
            if (array_key_exists($key, $data)) {
                $sanitized[$key] = $data[$key];
            }
        }

        if (is_array($data['consumer'] ?? null)) {
            // consumer PII never persists; only the gateway-contact hash the
            // providers round-trip is irrelevant here (nothing allowlisted).
            unset($sanitized['consumer']);
        }

        $transactions = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];

        foreach (['captures', 'refunds', 'cancels'] as $listKey) {
            if (is_array($transactions[$listKey] ?? null)) {
                $allowed = $listKey === 'captures'
                    ? ['capture_id', 'amount', 'created']
                    : ($listKey === 'refunds' ? ['refund_id', 'amount', 'created'] : ['canceled_id', 'amount', 'created']);

                $sanitized[$listKey] = array_map(
                    fn (mixed $entry): array => array_intersect_key(
                        (array) $entry,
                        array_flip($allowed),
                    ),
                    array_values(array_filter($transactions[$listKey], fn (mixed $entry): bool => is_array($entry))),
                );
            }
        }

        return $sanitized;
    }

    /**
     * Bounded error message: a single allowlisted provider error field (or
     * a generic fallback with the HTTP status). Never echoes the raw body
     * and redacts embedded token material, mirroring TabbyGateway.
     */
    private function errorMessageFrom(\Illuminate\Http\Client\Response $response, string $fallback): string
    {
        $json = $response->json();
        $message = null;

        if (is_array($json)) {
            if (is_string($json['message'] ?? null) && trim((string) $json['message']) !== '') {
                $message = (string) $json['message'];
            } elseif (is_string($json['error'] ?? null) && trim((string) $json['error']) !== '') {
                $message = (string) $json['error'];
            } elseif (is_array($json['errors'] ?? null) && $json['errors'] !== []) {
                $first = $json['errors'][0] ?? null;

                if (is_string($first) && trim($first) !== '') {
                    $message = $first;
                } elseif (is_array($first)) {
                    // The doc'd Tamara transition error shape:
                    // {error_code + data{old_state,new_state}} — the error
                    // code is safe to surface; data internals are not.
                    $code = is_string($first['error_code'] ?? null) ? $first['error_code'] : null;

                    if ($code !== null && trim($code) !== '') {
                        $message = $code;
                    }
                }
            }
        }

        $redacted = preg_replace(
            '/[A-Za-z0-9\-_]{40,}/',
            '[redacted]',
            $message ?? ($fallback . ' (HTTP ' . $response->status() . ')'),
        );

        if (!is_string($redacted) || trim($redacted) === '') {
            return $fallback;
        }

        return Str::limit(strip_tags($redacted), 300, '');
    }

    // -----------------------------------------------------------------
    // Money + config helpers
    // -----------------------------------------------------------------

    /**
     * Amount object per the doc'd schema shape at the EXACT currency
     * exponent (never a rounded guess; decimal precision governs).
     */
    private function amountObject(int $minor, string $currency): array
    {
        $decimals = CurrencyPrecision::decimalsFor($currency);

        return [
            'amount' => round($minor / (10 ** $decimals), $decimals),
            'currency' => $currency,
        ];
    }

    /**
     * Provider `amounts` block: {captured_amount, refunded_amount,
     * canceled_amount, paid_amount} objects {amount, currency}. A missing
     * number reads 0 (never null-arithmetic).
     */
    private function amountAmount(array $order, string $path): float
    {
        $segment = data_get($order, $path);

        if (is_array($segment) && is_numeric($segment['amount'] ?? null)) {
            return (float) $segment['amount'];
        }

        return 0.0;
    }

    /**
     * Provider-side currency from the amounts/total blocks; falls back to
     * the caller's expected currency only when the provider omits it.
     */
    private function orderCurrency(array $order, ?string $fallback): string
    {
        $candidates = [
            'total_amount.currency',
            'amounts.captured_amount.currency',
            'amounts.refunded_amount.currency',
        ];

        foreach ($candidates as $path) {
            $value = data_get($order, $path);

            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }
        }

        return strtoupper(trim((string) ($fallback ?? '')));
    }

    private function tamaraCountryCode(Order $order): string
    {
        // Order snapshot address first; Meem's home-market default (KWD
        // catalog ⇒ KW) keeps valid payloads when the snapshot omits the
        // country.
        $raw = is_array($order->address) ? ($order->address['country_code'] ?? null) : null;
        $code = strtoupper(trim(is_string($raw) ? $raw : ''));

        if (strlen($code) === 2 && in_array($code, ['SA', 'AE', 'BH', 'KW', 'OM'], true)) {
            return $code;
        }

        return 'KW';
    }

    private function firstName(Order $order): string
    {
        $name = trim((string) ($order->name ?? ''));

        return $name !== '' ? (string) explode(' ', $name)[0] : '';
    }

    private function lastName(Order $order): string
    {
        $name = trim((string) ($order->name ?? ''));

        if ($name === '') {
            return '';
        }

        return implode(' ', array_slice(explode(' ', $name), 1));
    }

    private function supportedCurrencies(): array
    {
        $currencies = config('payment.gateways.tamara.supported_currencies', []);

        if (!is_array($currencies)) {
            return [];
        }

        return array_values(array_map(
            fn ($code): string => strtoupper(trim((string) $code)),
            array_filter($currencies, fn ($code): bool => is_string($code) || is_numeric($code)),
        ));
    }

    private function apiToken(): string
    {
        return trim((string) config('payment.gateways.tamara.api_token', ''));
    }

    private function notificationToken(): string
    {
        return trim((string) config('payment.gateways.tamara.notification_token', ''));
    }

    /**
     * Base URL resolution: explicit TAMARA_API_URL wins; otherwise the
     * documented sandbox/production hosts by TAMARA_ENVIRONMENT — fail
     * closed on an unknown environment (never guess a production host).
     */
    private function baseUrl(): string
    {
        $override = trim((string) config('payment.gateways.tamara.api_url', ''));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return match (strtolower(trim((string) config('payment.gateways.tamara.environment', 'sandbox')))) {
            'production', 'live' => 'https://api.tamara.co',
            'sandbox' => 'https://api-sandbox.tamara.co',
            default => '',
        };
    }

    private function boundedText(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

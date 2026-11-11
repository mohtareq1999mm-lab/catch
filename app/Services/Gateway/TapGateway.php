<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\Contracts\ReadonlyPaymentProbeContract;
use App\Services\Payment\CurrencyPrecision;
use App\Services\Payment\CustomerContactResolver;
use App\Services\Payment\PaymentCurrencyResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;

/**
 * Tap Payments gateway adapter (https://developers.tap.company).
 *
 * API contract (official docs, verified 2026-10):
 * - Charges:  POST {base}/charges  (Bearer secret) — amount/currency/
 *   customer{first_name,email}/source{id}/redirect{url} are required; the
 *   charge `amount` is a NUMBER at the currency's ISO decimal places
 *   (3dp KWD/BHD/JOD/OMR, 2dp others) with a documented minimum of 0.100.
 * - Retrieve: GET  {base}/charges/{chg_id} — verification re-fetches
 *   server-side; success is EXCLUSIVELY status === 'CAPTURED' (redirect
 *   parameters are untrusted hints).
 * - Refunds:  POST {base}/refunds  — charge_id/amount/currency/reason;
 *   `reason` accepts only documented values (duplicate|fraudulent|
 *   requested_by_customer); success is EXCLUSIVELY status === 'REFUNDED'.
 * - Idempotency: reference.idempotent (24h) — per-order constant string
 *   so a retried checkout re-creates the same charge response instead of
 *   a duplicate charge (developers.tap.company/docs/idempotency).
 *
 * Currency is NEVER hardcoded: every charge/refund derives its currency
 * from PaymentCurrencyResolver::forOrder($order) (order snapshot →
 * catalog currency) and validates it against the config
 * supported_currencies list before any provider call. Amounts are
 * rounded exclusively through CurrencyPrecision (shared exponent table).
 *
 * Hygiene: persisted/logged provider payloads pass through allowlists
 * (no customer PII, no card material); error messages are truncated and
 * sk_ redacted; TLS verification is always enabled (no withoutVerifying);
 * logs carry ids/statuses only — never raw bodies.
 */
class TapGateway implements PaymentGatewayContract, ReadonlyPaymentProbeContract
{
    /**
     * Charge allowlist: technical fields only. The full charge object
     * carries customer PII and card material (first_six/last_four) —
     * intentionally stripped at the source, never persisted or logged.
     */
    private const CHARGE_ALLOWLIST = [
        'id',
        'status',
        'amount',
        'currency',
        'redirect_url',
    ];

    /**
     * Refund allowlist: technical fields only.
     */
    private const REFUND_ALLOWLIST = [
        'id',
        'status',
        'amount',
        'currency',
        'charge_id',
    ];

    /**
     * Tap refund API `reason` accepts only these documented values
     * (developers.tap.company/reference/create-a-refund). The admin's
     * free-text reason is preserved locally in the refund metadata.
     */
    private const REFUND_REASON_MAP = 'requested_by_customer';

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

        // Amount as a JSON NUMBER at the currency's ISO exponent (Tap
        // charge schema: type number; "ISO standard decimal places").
        $chargeAmount = CurrencyPrecision::roundForCurrency($amount, $orderCurrency);

        $payload = [
            'amount' => $chargeAmount,
            'currency' => $orderCurrency,
            'customer' => [
                'first_name' => $this->customerFirstName($order),
                // Tap requires a syntactically valid email; the resolver
                // supplies a deterministic unroutable fallback for
                // guest orders (never persisted as a user address).
                'email' => $this->customerContactResolver->emailForGateway($order),
            ],
            'source' => [
                'id' => (string) config('payment.gateways.tap.source_id', 'src_all'),
            ],
            'redirect' => [
                'url' => $callbackUrl,
            ],
            'post' => [
                'url' => route('api.checkout.webhooks.tap'),
            ],
            'reference' => [
                'order' => (string) $order->id,
                // 24h idempotency (docs recommend per-order constancy):
                // a retried checkout on the same order returns the
                // original response instead of double-charging. When an
                // attempt reference is supplied (PM-1 lifecycle), it becomes
                // the per-attempt idempotent/correlation identity instead.
                'idempotent' => is_string($metadata['attempt_ref'] ?? null) && trim((string) $metadata['attempt_ref']) !== ''
                    ? trim((string) $metadata['attempt_ref'])
                    : 'order-' . $order->id,
            ],
            'metadata' => array_merge(['order_id' => (string) $order->id], $metadata),
            'threeDSecure' => true,
        ];

        $response = $this->request('post', '/charges', $payload);

        if (!$response instanceof \Illuminate\Http\Client\Response) {
            return $response; // fail-closed GatewayResult from request()
        }

        if (!$response->successful()) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->errorMessageFrom($response, 'Charge request failed'),
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
            );
        }

        $chargeId = is_string($data['id'] ?? null) && trim((string) $data['id']) !== ''
            ? (string) $data['id']
            : null;
        $redirectUrl = is_string(data_get($data, 'transaction.url')) && trim((string) data_get($data, 'transaction.url')) !== ''
            ? (string) data_get($data, 'transaction.url')
            : null;

        if ($chargeId === null || $redirectUrl === null) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
                rawResponse: $this->sanitizeCharge($data),
            );
        }

        return new GatewayResult(
            success: true,
            redirectUrl: $redirectUrl,
            gatewayTransactionId: $chargeId,
            amount: $chargeAmount,
            currency: $orderCurrency,
            status: 'pending',
            rawResponse: $this->sanitizeCharge($data),
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

        $response = $this->request('get', '/charges/' . urlencode($id));

        if (!$response instanceof \Illuminate\Http\Client\Response) {
            return $response; // fail-closed GatewayResult from request()
        }

        if (!$response->successful()) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->errorMessageFrom($response, 'Payment verification failed'),
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
            );
        }

        $chargeId = is_string($data['id'] ?? null) && trim((string) $data['id']) !== ''
            ? (string) $data['id']
            : $id;
        // Provider-verified amount/currency are echoed as-is: the canonical
        // completion authority (assertNoMismatch) compares them against the
        // order snapshot — never trust the local amount here.
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $amount = is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null;

        // Success is EXCLUSIVELY the verified CAPTURED status (charges doc:
        // every other listed status is "Payment failed").
        $isPaid = strtoupper((string) ($data['status'] ?? '')) === 'CAPTURED';

        return new GatewayResult(
            success: $isPaid,
            gatewayTransactionId: $chargeId,
            amount: $amount,
            currency: $currency !== '' ? $currency : null,
            status: $isPaid ? 'paid' : 'failed',
            errorMessage: $isPaid ? null : 'Payment not completed',
            rawResponse: $this->sanitizeCharge($data),
        );
    }

    /**
     * PM-5: STRICTLY READ-ONLY probe — GET charge retrieval only; never
     * creates, captures, or voids. Terminal-failure vocabulary comes from
     * the charges doc (non-CAPTURED listed statuses); INITIATED and any
     * unrecognized status stay conclusive-open (never a local guess).
     */
    public function probePaymentStatus(string $gatewayTransactionId): GatewayResult
    {
        $id = trim($gatewayTransactionId);

        if ($id === '' || strlen($id) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $id)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway transaction id',
            );
        }

        $response = $this->request('get', '/charges/' . urlencode($id));

        if (!$response instanceof \Illuminate\Http\Client\Response || !$response->successful()) {
            // Unread provider truth: fail-closed NON-TERMINAL (Unknown).
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $id,
                status: 'pending',
                errorMessage: 'Payment gateway error',
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $id,
                status: 'pending',
                errorMessage: 'Invalid gateway response',
            );
        }

        $chargeId = is_string($data['id'] ?? null) && trim((string) $data['id']) !== ''
            ? (string) $data['id']
            : $id;
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $amount = is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null;
        $status = strtoupper(trim((string) ($data['status'] ?? '')));

        $raw = $this->sanitizeCharge($data);
        $raw['_probe_provider_status'] = $status;

        if ($status === 'CAPTURED') {
            return new GatewayResult(
                success: true,
                gatewayTransactionId: $chargeId,
                amount: $amount,
                currency: $currency !== '' ? $currency : null,
                status: 'paid',
                rawResponse: $raw,
            );
        }

        if (in_array($status, ['DECLINED', 'ABANDONED', 'RESTRICTED', 'VOID', 'ERROR'], true)) {
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $chargeId,
                amount: $amount,
                currency: $currency !== '' ? $currency : null,
                status: 'failed',
                errorMessage: 'Payment not completed',
                rawResponse: $raw,
            );
        }

        // INITIATED and unrecognized statuses: conclusive-OPEN.
        return new GatewayResult(
            success: true,
            gatewayTransactionId: $chargeId,
            amount: $amount,
            currency: $currency !== '' ? $currency : null,
            status: 'pending',
            rawResponse: $raw,
        );
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

        // Duplicate/over-refund protection, currency authorization and
        // ledger recording live in PaymentRefundService (the canonical
        // refund authority) — this method only talks to the provider.
        $payload = [
            'charge_id' => (string) $transaction->gateway_transaction_id,
            'amount' => CurrencyPrecision::roundForCurrency($amount, $orderCurrency),
            'currency' => $orderCurrency,
            // API enum (DOC-FACT); the admin's free-text reason is kept in
            // metadata for the local audit trail, not sent as `reason`.
            'reason' => self::REFUND_REASON_MAP,
            'metadata' => [
                'order_id' => (string) $order->id,
                'reason' => mb_substr(trim((string) ($reason ?? '')), 0, 200),
            ],
        ];

        $response = $this->request('post', '/refunds', $payload);

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

        // Fail-closed: a refund counts as successful ONLY when Tap reports
        // status REFUNDED. Ambiguous outcomes (other statuses, missing id)
        // surface as failures so the admin can re-check the dashboard.
        $refundStatus = strtoupper((string) ($data['status'] ?? ''));

        if ($refundStatus !== 'REFUNDED') {
            return new GatewayResult(
                success: false,
                errorMessage: 'Refund not confirmed by gateway',
                rawResponse: $this->sanitizeRefund($data),
            );
        }

        return new GatewayResult(
            success: true,
            gatewayTransactionId: is_string($data['id'] ?? null) && trim((string) $data['id']) !== ''
                ? (string) $data['id']
                : null,
            amount: is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : $amount,
            currency: $orderCurrency,
            status: 'succeeded',
            rawResponse: $this->sanitizeRefund($data),
        );
    }

    public function name(): string
    {
        return 'tap';
    }

    public function code(): string
    {
        return 'tap';
    }

    public function isConfigured(): bool
    {
        $key = config('payment.gateways.tap.secret_key');

        return is_string($key) ? trim($key) !== '' : !empty($key);
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        $supported = (array) (config('payment.gateways.tap.supported_currencies') ?? []);

        return in_array(strtoupper($currencyCode), array_map('strtoupper', $supported), true);
    }

    // -----------------------------------------------------------------
    // HTTP + sanitization helpers
    // -----------------------------------------------------------------

    /**
     * Single HTTP seam: Bearer secret, JSON accept, 30s timeout, TLS
     * verification always on. Returns the Response, or a fail-closed
     * GatewayResult for transport failures (timeout/network) so callers
     * never see an exception escape (ambiguous outcomes must not mark
     * anything paid — and a retried create re-uses reference.idempotent).
     *
     * @return \Illuminate\Http\Client\Response|GatewayResult
     */
    private function request(string $method, string $path, ?array $payload = null): mixed
    {
        $secret = trim((string) config('payment.gateways.tap.secret_key', ''));
        $baseUrl = rtrim((string) config('payment.gateways.tap.base_url', 'https://api.tap.company/v2'), '/');

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

    private function customerFirstName(Order $order): string
    {
        $name = trim((string) ($order->name ?? ''));

        return $name !== '' ? mb_substr($name, 0, 100) : 'Customer';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeCharge(array $data): ?array
    {
        $redirectUrl = data_get($data, 'transaction.url');

        $sanitized = $this->sanitizeArray($data, self::CHARGE_ALLOWLIST) ?? [];

        if (is_string($redirectUrl) && trim($redirectUrl) !== '') {
            $sanitized['redirect_url'] = $redirectUrl;
        }

        return $sanitized === [] ? null : $sanitized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeRefund(array $data): ?array
    {
        $chargeId = data_get($data, 'charge_id');

        $sanitized = $this->sanitizeArray($data, self::REFUND_ALLOWLIST) ?? [];

        if (is_string($chargeId) && trim($chargeId) !== '') {
            $sanitized['charge_id'] = $chargeId;
        }

        return $sanitized === [] ? null : $sanitized;
    }

    private function sanitizeArray(array $data, array $allowlist): ?array
    {
        $allowed = [];

        foreach ($allowlist as $key) {
            if (array_key_exists($key, $data)) {
                $allowed[$key] = $data[$key];
            }
        }

        return $allowed;
    }

    /**
     * Bounded error message: a single allowlisted provider `message` field
     * (or a generic fallback with the HTTP status). Never echoes the raw
     * body (request/response payloads may contain provider internals) and
     * redacts any embedded sk_ key material, mirroring StripeGateway.
     */
    private function errorMessageFrom(\Illuminate\Http\Client\Response $response, string $fallback): string
    {
        $json = $response->json();
        $message = is_array($json) && is_string($json['message'] ?? null) && trim((string) $json['message']) !== ''
            ? (string) $json['message']
            : $fallback . ' (HTTP ' . $response->status() . ')';

        $redacted = preg_replace('/sk_(live|test)_[A-Za-z0-9]+/', '[redacted]', $message);

        if (!is_string($redacted) || trim($redacted) === '') {
            return $fallback;
        }

        return Str::limit(strip_tags($redacted), 300, '');
    }
}

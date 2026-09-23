<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCurrencyResolver;
use Marvel\Database\Models\Order;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

/**
 * PayPal gateway adapter over srmklive/paypal ^3.0 (PayPal Orders v2 API).
 *
 * SDK method map (vendor/src/Traits/PayPalAPI):
 * - Orders::createOrder(array)            → POST v2/checkout/orders
 * - Orders::showOrderDetails(string)      → GET  v2/checkout/orders/{id}
 * - Orders::capturePaymentOrder(string)   → POST v2/checkout/orders/{id}/capture
 * - PaymentCaptures::refundCapturedPayment → POST v2/payments/captures/{id}/refund
 *   (refund payload currency comes from the client's set currency).
 *
 * NOTE: packages/marvel/src/Payment/Paypal.php is a legacy/dead integration
 * and is intentionally NOT reused or modified here.
 *
 * NOTE (capture-on-return): unlike MyFatoorahGateway::verifyPayment (read-only
 * status check), verifyPayment() performs the capture for PayPal — an APPROVED
 * order is captured inside verify, because PayPal requires a capture call to
 * move funds after buyer approval.
 *
 * NOTE (idempotency): createInvoice sends PayPal-Request-Id
 * "order-{orderId}-{attempt}" (attempt from $metadata['payment_attempt'],
 * default 1, deterministic per order+attempt).
 *
 * NOTE (return URL): PayPal appends "?token=<paypalOrderId>&PayerID=..." to the
 * return_url on buyer approval. The unified callback correlates via the stored
 * gateway_transaction_id (see PaymentCheckoutHandler), so the callback URL is
 * passed through unchanged.
 *
 * NOTE (srmklive currency guard): PayPalRequest::setCurrency() validates
 * against the SDK's own allowlist, which mirrors PayPal-supported currencies
 * (no KWD/SAR/...). A config-allowed but SDK-rejected currency throws inside
 * client() and is converted to a fail-closed GatewayResult by the caller.
 *
 * Sandbox is BLOCKED in this environment (no live credentials): this adapter
 * must only ever be exercised via the injected client factory seam in tests —
 * never against the live/sandbox network.
 */
class PayPalGateway implements PaymentGatewayContract
{
    /**
     * Mirror of the srmklive/paypal SDK currency guard
     * (PayPalRequest::setCurrency allowlist): AUD, BRL, CAD, CZK, DKK, EUR,
     * HKD, HUF, ILS, INR, JPY, MYR, MXN, NOK, NZD, PHP, PLN, GBP, SGD, SEK,
     * CHF, TWD, THB, USD, RUB, CNY.
     *
     * Deliberately NOT enforced in supportsCurrency(): the config allowlist
     * stays authoritative there (fixtures exercise KWD through the injected
     * client-factory seam). With a real client, a config-allowed but
     * SDK-rejected currency throws inside client()->setCurrency() and every
     * caller converts that to a fail-closed GatewayResult (→ 422 at
     * checkout) — so unsupported currencies still fail closed, just at the
     * client-build step instead of the supportsCurrency step.
     */
    private const SDK_SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'INR',
        'JPY', 'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK',
        'CHF', 'TWD', 'THB', 'USD', 'RUB', 'CNY',
    ];

    /**
     * gateway_response allowlist: only technical provider fields are ever
     * persisted/logged. No payer PII, no secrets. Flat shape (PayPal Orders v2
     * has no Data envelope): id, status, intent + normalized amount/currency.
     */
    private const RESPONSE_ALLOWLIST = ['id', 'status', 'intent', 'amount', 'currency'];

    public function __construct(
        private PaymentCurrencyResolver $currencyResolver,
        // NOTE: `callable` cannot be a property type in PHP, so this is mixed
        // and validated with is_callable() before use. Tests inject a fake
        // client factory here; production leaves it null (real SDK client).
        private mixed $clientFactory = null,
    ) {}

    public function createInvoice(
        Order $order,
        float $amount,
        string $callbackUrl,
        string $errorUrl,
        array $metadata = []
    ): GatewayResult {
        $currency = $this->currencyResolver->forOrder($order);

        if (!$this->supportsCurrency($currency)) {
            return new GatewayResult(
                success: false,
                errorMessage: __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $currency]),
            );
        }

        if ($amount <= 0) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid payment amount',
            );
        }

        try {
            $client = $this->client($currency);

            $attempt = $metadata['payment_attempt'] ?? $metadata['attempt'] ?? 1;
            $client->setRequestHeader('PayPal-Request-Id', 'order-'.$order->id.'-'.$attempt);

            // PayPal value fields are decimal strings at the CURRENCY exponent
            // (3dp for KWD/...), never a fixed 2dp.
            $value = \App\Services\Payment\CurrencyPrecision::formatForGateway($amount, $currency);

            $response = $client->createOrder([
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => 'order-'.$order->id,
                        'invoice_id' => (string) $order->id,
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => $value,
                        ],
                    ],
                ],
                'application_context' => [
                    'return_url' => $callbackUrl,
                    'cancel_url' => $errorUrl,
                ],
            ]);

            if (!is_array($response) || isset($response['error'])) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'No response from payment gateway',
                    rawResponse: $this->sanitizeResponse(is_array($response) ? $response : null),
                );
            }

            $paypalOrderId = $response['id'] ?? null;
            $approveUrl = $this->approveUrl($response);

            if (!is_string($paypalOrderId) || $paypalOrderId === '' || $approveUrl === null) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'Invalid gateway response',
                    rawResponse: $this->sanitizeResponse($response),
                );
            }

            return new GatewayResult(
                success: true,
                redirectUrl: $approveUrl,
                gatewayTransactionId: $paypalOrderId,
                amount: $amount,
                currency: $currency,
                status: 'pending',
                rawResponse: $this->sanitizeResponse($response),
            );
        } catch (\Throwable $e) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway request failed',
            );
        }
    }

    public function verifyPayment(string $gatewayTransactionId): GatewayResult
    {
        if (trim($gatewayTransactionId) === '') {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
            );
        }

        try {
            $client = $this->client();

            $order = $client->showOrderDetails($gatewayTransactionId);

            if (!is_array($order) || isset($order['error'])) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'No response from payment gateway',
                    rawResponse: $this->sanitizeResponse(is_array($order) ? $order : null),
                );
            }

            $status = strtoupper((string) ($order['status'] ?? ''));

            if ($status === 'APPROVED') {
                // Idempotent capture: the same key makes a retried/double
                // verify collapse into ONE provider-side capture.
                $client->setRequestHeader('PayPal-Request-Id', 'capture-'.$gatewayTransactionId);
                $captured = $client->capturePaymentOrder($gatewayTransactionId);

                if (!is_array($captured) || isset($captured['error'])) {
                    // Already-captured race (concurrent verify won first):
                    // re-fetch; COMPLETED means the funds moved → success.
                    if ($this->isAlreadyCapturedError($captured)) {
                        $refreshed = $client->showOrderDetails($gatewayTransactionId);

                        if (is_array($refreshed) && !isset($refreshed['error'])
                            && strtoupper((string) ($refreshed['status'] ?? '')) === 'COMPLETED'
                        ) {
                            return $this->completedResult($gatewayTransactionId, $refreshed);
                        }
                    }

                    return new GatewayResult(
                        success: false,
                        errorMessage: 'No response from payment gateway',
                        rawResponse: $this->sanitizeResponse(is_array($captured) ? $captured : null),
                    );
                }

                return $this->completedResult($gatewayTransactionId, $captured);
            }

            if ($status === 'COMPLETED') {
                return $this->completedResult($gatewayTransactionId, $order);
            }

            return new GatewayResult(
                success: false,
                gatewayTransactionId: $gatewayTransactionId,
                status: 'failed',
                errorMessage: 'Payment not completed',
                rawResponse: $this->sanitizeResponse($order),
            );
        } catch (\Throwable $e) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway request failed',
            );
        }
    }

    public function refund(
        Order $order,
        float $amount,
        ?string $reason = null
    ): GatewayResult {
        $currency = $this->currencyResolver->forOrder($order);

        if (!$this->supportsCurrency($currency)) {
            return new GatewayResult(
                success: false,
                errorMessage: __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $currency]),
            );
        }

        if ($amount <= 0) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid refund amount',
            );
        }

        try {
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

            $paypalOrderId = (string) $transaction->gateway_transaction_id;
            $client = $this->client($currency);

            $details = $client->showOrderDetails($paypalOrderId);

            if (!is_array($details) || isset($details['error'])) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'No response from payment gateway',
                    rawResponse: $this->sanitizeResponse(is_array($details) ? $details : null),
                );
            }

            $captures = $this->flattenCaptures($details);

            if (count($captures) === 0) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'No captured payment found for this order',
                    rawResponse: $this->sanitizeResponse($details),
                );
            }

            if (count($captures) > 1) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'Ambiguous captured payments for this order',
                    rawResponse: $this->sanitizeResponse($details),
                );
            }

            $capture = $captures[0];

            if (empty($capture['id']) || strtoupper((string) ($capture['status'] ?? '')) !== 'COMPLETED') {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'Captured payment is not refundable',
                    rawResponse: $this->sanitizeResponse($details),
                );
            }

            $refund = $client->refundCapturedPayment(
                (string) $capture['id'],
                'order-'.$order->id,
                \App\Services\Payment\CurrencyPrecision::roundForCurrency($amount, $currency),
                $reason ?? 'Refund for order #'.$order->id,
            );

            if (!is_array($refund) || isset($refund['error'])) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'No response from payment gateway',
                    rawResponse: $this->sanitizeResponse(is_array($refund) ? $refund : null),
                );
            }

            // Fail-closed: success ONLY on explicit COMPLETED
            // (PayPal Payments v2 refund status; PENDING/FAILED/... are failures).
            if (strtoupper(trim((string) ($refund['status'] ?? ''))) !== 'COMPLETED') {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'Refund not confirmed by gateway',
                    rawResponse: $this->sanitizeResponse($refund),
                );
            }

            $refundId = isset($refund['id']) && is_string($refund['id']) && $refund['id'] !== ''
                ? $refund['id']
                : null;

            return new GatewayResult(
                success: true,
                gatewayTransactionId: $refundId,
                amount: \App\Services\Payment\CurrencyPrecision::roundForCurrency($amount, $currency),
                currency: $currency,
                status: 'refunded',
                rawResponse: $this->sanitizeResponse($refund),
            );
        } catch (\Throwable $e) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway request failed',
            );
        }
    }

    public function name(): string
    {
        return 'paypal';
    }

    public function code(): string
    {
        return 'paypal';
    }

    public function isConfigured(): bool
    {
        $clientId = config('payment.gateways.paypal.client_id');
        $clientSecret = config('payment.gateways.paypal.client_secret');

        return is_string($clientId) && trim($clientId) !== ''
            && is_string($clientSecret) && trim($clientSecret) !== '';
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        $supported = (array) (config('payment.gateways.paypal.supported_currencies') ?? []);

        return in_array(strtoupper($currencyCode), array_map('strtoupper', $supported), true);
    }

    /**
     * Build a configured SDK client for the given mode. Reads ONLY the
     * config/payment.php `paypal` block keys: class, enabled, mode, client_id,
     * client_secret, webhook_id, supported_currencies, methods (class/enabled/
     * methods are consumed by the registry; the client needs mode + credentials).
     */
    protected function client(string $currency = 'USD'): object
    {
        if (is_callable($this->clientFactory)) {
            return ($this->clientFactory)($currency);
        }

        $mode = strtolower(trim((string) config('payment.gateways.paypal.mode', 'sandbox')));
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            $mode = 'sandbox';
        }

        $provider = new PayPalClient();
        $provider->setApiCredentials([
            'mode' => $mode,
            'sandbox' => [
                'client_id' => (string) config('payment.gateways.paypal.client_id', ''),
                'client_secret' => (string) config('payment.gateways.paypal.client_secret', ''),
                'app_id' => '',
            ],
            'live' => [
                'client_id' => (string) config('payment.gateways.paypal.client_id', ''),
                'client_secret' => (string) config('payment.gateways.paypal.client_secret', ''),
                'app_id' => '',
            ],
            'payment_action' => 'Sale',
            'currency' => strtoupper($currency),
            'notify_url' => '',
            'locale' => 'en_US',
            'validate_ssl' => true,
        ]);
        $provider->getAccessToken();

        return $provider;
    }

    /**
     * Detect the PayPal ORDER_ALREADY_CAPTURED race. srmklive wraps provider
     * HTTP failures as ['error' => <body>], and the body shape varies
     * (name/details array vs raw string), so the whole payload is scanned for
     * the marker instead of assuming one exact nesting.
     */
    private function isAlreadyCapturedError(mixed $captured): bool
    {
        if (!is_array($captured)) {
            return false;
        }

        $haystack = json_encode($captured);

        return is_string($haystack) && str_contains($haystack, 'ORDER_ALREADY_CAPTURED');
    }

    /**
     * Paid verdict for an order/capture payload: COMPLETED captures whose
     * summed amount/currency exactly match the summed purchase units
     * (mismatch → success:false). Returns major-unit amount + uppercase currency.
     */
    private function completedResult(string $paypalOrderId, array $payload): GatewayResult
    {
        $totals = $this->capturedTotals($payload);

        if ($totals === null) {
            return new GatewayResult(
                success: false,
                gatewayTransactionId: $payload['id'] ?? $paypalOrderId,
                status: 'failed',
                errorMessage: 'Payment amount mismatch',
                rawResponse: $this->sanitizeResponse($payload),
            );
        }

        return new GatewayResult(
            success: true,
            gatewayTransactionId: isset($payload['id']) && is_string($payload['id']) && $payload['id'] !== ''
                ? $payload['id']
                : $paypalOrderId,
            amount: $totals['amount'],
            currency: $totals['currency'],
            status: 'paid',
            rawResponse: $this->sanitizeResponse($payload),
        );
    }

    /**
     * @return array{amount: float, currency: string}|null null on any anomaly
     * (missing units/captures, non-COMPLETED capture, currency split, or
     * capture total != unit total). Fail-closed by design.
     */
    private function capturedTotals(array $payload): ?array
    {
        $units = $payload['purchase_units'] ?? null;

        if (!is_array($units) || $units === []) {
            return null;
        }

        $currency = null;
        $unitsTotal = 0.0;
        $capturesTotal = 0.0;
        $captureCount = 0;

        foreach ($units as $unit) {
            if (!is_array($unit)) {
                return null;
            }

            $unitAmount = $unit['amount'] ?? null;
            if (!is_array($unitAmount) || !isset($unitAmount['value'], $unitAmount['currency_code'])) {
                return null;
            }

            $unitCurrency = strtoupper(trim((string) $unitAmount['currency_code']));
            if ($unitCurrency === '') {
                return null;
            }

            $currency ??= $unitCurrency;
            if ($unitCurrency !== $currency) {
                return null;
            }

            $unitsTotal += (float) $unitAmount['value'];

            $captures = $unit['payments']['captures'] ?? null;
            if (!is_array($captures)) {
                continue;
            }

            foreach ($captures as $capture) {
                if (!is_array($capture)) {
                    return null;
                }

                if (strtoupper((string) ($capture['status'] ?? '')) !== 'COMPLETED') {
                    return null;
                }

                $captureAmount = $capture['amount'] ?? null;
                if (!is_array($captureAmount) || !isset($captureAmount['value'], $captureAmount['currency_code'])) {
                    return null;
                }

                if (strtoupper(trim((string) $captureAmount['currency_code'])) !== $currency) {
                    return null;
                }

                $capturesTotal += (float) $captureAmount['value'];
                $captureCount++;
            }
        }

        if ($captureCount === 0) {
            return null;
        }

        // Compare at the CURRENCY exponent (PayPal values are decimal strings;
        // a fixed 2dp format would split 3dp totals into false mismatches).
        $decimals = \App\Services\Payment\CurrencyPrecision::decimalsFor($currency);

        if (number_format($capturesTotal, $decimals, '.', '') !== number_format($unitsTotal, $decimals, '.', '')) {
            return null;
        }

        return [
            'amount' => \App\Services\Payment\CurrencyPrecision::roundForCurrency($capturesTotal, $currency),
            'currency' => $currency,
        ];
    }

    /**
     * @return list<array> all payment captures across all purchase units.
     */
    private function flattenCaptures(array $payload): array
    {
        $captures = [];
        $units = $payload['purchase_units'] ?? null;

        if (!is_array($units)) {
            return [];
        }

        foreach ($units as $unit) {
            if (!is_array($unit)) {
                continue;
            }

            $unitCaptures = $unit['payments']['captures'] ?? null;

            if (!is_array($unitCaptures)) {
                continue;
            }

            foreach ($unitCaptures as $capture) {
                if (is_array($capture)) {
                    $captures[] = $capture;
                }
            }
        }

        return $captures;
    }

    private function approveUrl(array $response): ?string
    {
        $links = $response['links'] ?? null;

        if (!is_array($links)) {
            return null;
        }

        foreach ($links as $link) {
            if (is_array($link)
                && strtolower((string) ($link['rel'] ?? '')) === 'approve'
                && isset($link['href']) && is_string($link['href']) && $link['href'] !== ''
            ) {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * Strip a provider payload down to the technical allowlist before it is
     * stored in gateway_response or logs. Unknown shapes collapse to the
     * allowlisted keys only (fail-closed, never PII/secrets).
     */
    private function sanitizeResponse(?array $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        $allowed = [];
        foreach (['id', 'status', 'intent'] as $key) {
            if (array_key_exists($key, $response)) {
                $allowed[$key] = $response[$key];
            }
        }

        $amount = $response['amount'] ?? $response['purchase_units'][0]['amount'] ?? null;
        if (is_array($amount)) {
            if (array_key_exists('value', $amount)) {
                $allowed['amount'] = $amount['value'];
            }
            if (array_key_exists('currency_code', $amount)) {
                $allowed['currency'] = $amount['currency_code'];
            }
        }

        return array_intersect_key($allowed, array_flip(self::RESPONSE_ALLOWLIST));
    }
}

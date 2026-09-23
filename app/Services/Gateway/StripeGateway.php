<?php

namespace App\Services\Gateway;

use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCurrencyResolver;
use Marvel\Database\Models\Order;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeGateway implements PaymentGatewayContract
{
    /**
     * Minor-unit conversion rule.
     *
     * All Stripe API amounts are integers in the currency's minor unit
     * (https://docs.stripe.com/currencies and https://stripe.com/docs/currencies):
     * "All API requests expect amount values in the currency's minor unit. For
     * example, set amount as follows: 1000 to charge 10 USD (or any other
     * two-decimal currency). 10 to charge 10 JPY (or any other zero-decimal
     * currency)."
     *
     * - Zero-decimal currencies (exponent 0): amount is sent as-is
     *   (BIF, CLP, DJF, GNF, JPY, KMF, KRW, MGA, PYG, RWF, UGX, VND, VUV,
     *   XAF, XOF, XPF per the Stripe zero-decimal table).
     * - Three-decimal currencies (ISO 4217 exponent 3): amount x 1000
     *   (BHD, JOD, KWD, OMR, TND). E.g. 13.25 KWD -> 13250.
     * - Everything else (two-decimal): amount x 100.
     *
     * Note: ISK/HUF/TWD carry Stripe-specific legacy notes (ISK is charged as
     * a two-decimal value with 00 decimals; HUF/TWD charge two-decimal
     * amounts) so they intentionally stay on the default x100 path.
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    // NOTE: the three-decimal list (BHD/JOD/KWD/OMR/TND) lives in
    // CurrencyPrecision::decimalsFor — toMinorUnits/fromMinorUnits delegate
    // there so snapshots and gateway math share one exponent table.

    /**
     * Checkout Session allowlist: only technical provider fields are ever
     * persisted/logged. No customer PII passes through here.
     */
    private const SESSION_ALLOWLIST = [
        'id',
        'url',
        'status',
        'payment_status',
        'amount_total',
        'currency',
    ];

    /**
     * Refund allowlist: technical fields only.
     */
    private const REFUND_ALLOWLIST = [
        'id',
        'status',
        'amount',
        'currency',
        'payment_intent',
    ];

    public function __construct(
        private PaymentCurrencyResolver $currencyResolver,
        private ?StripeClient $client = null,
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

        $stripeCurrency = strtolower($orderCurrency);
        $minorUnits = self::toMinorUnits($amount, $orderCurrency);

        // Stripe substitutes {CHECKOUT_SESSION_ID} in the success_url with the
        // created Session id; the handler looks the transaction up by that id.
        $successUrl = $this->appendQueryParam($callbackUrl, 'paymentId={CHECKOUT_SESSION_ID}');
        $cancelUrl = $this->appendQueryParam($errorUrl, 'paymentId={CHECKOUT_SESSION_ID}');

        try {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => $stripeCurrency,
                            'unit_amount' => $minorUnits,
                            'product_data' => [
                                'name' => 'Order #' . $order->id,
                            ],
                        ],
                        'quantity' => 1,
                    ],
                ],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => array_merge(['order_id' => (string) $order->id], $metadata),
            ]);
        } catch (ApiErrorException $e) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->sanitizeErrorMessage($e->getMessage()),
            );
        } catch (\Throwable $e) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway error',
            );
        }

        $sessionId = is_object($session) ? ($session->id ?? null) : null;
        $sessionUrl = is_object($session) ? ($session->url ?? null) : null;

        if (!is_string($sessionId) || $sessionId === '' || !is_string($sessionUrl) || $sessionUrl === '') {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
                rawResponse: $this->sanitizeSession($session),
            );
        }

        return new GatewayResult(
            success: true,
            redirectUrl: $sessionUrl,
            gatewayTransactionId: $sessionId,
            amount: $amount,
            currency: $orderCurrency,
            status: 'pending',
            rawResponse: $this->sanitizeSession($session),
        );
    }

    public function verifyPayment(string $gatewayTransactionId): GatewayResult
    {
        if (trim($gatewayTransactionId) === '') {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway transaction id',
            );
        }

        try {
            $session = $this->client()->checkout->sessions->retrieve($gatewayTransactionId);
            $data = is_object($session) && method_exists($session, 'toArray')
                ? $session->toArray()
                : [];
        } catch (ApiErrorException $e) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->sanitizeErrorMessage($e->getMessage()),
            );
        } catch (\Throwable $e) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway error',
            );
        }

        $sessionId = is_string($data['id'] ?? null) ? $data['id'] : $gatewayTransactionId;
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $amountTotal = isset($data['amount_total']) ? (int) $data['amount_total'] : 0;
        $isPaid = ($data['payment_status'] ?? null) === 'paid';

        // Cross-check against the underlying PaymentIntent when the session
        // references one: a currency/amount mismatch is a failure even when
        // the session itself reports paid (fail-closed).
        $paymentIntentId = $this->extractPaymentIntentId($data['payment_intent'] ?? null);

        if ($isPaid && $paymentIntentId !== null) {
            try {
                $intent = $this->client()->paymentIntents->retrieve($paymentIntentId);
                $intentData = is_object($intent) && method_exists($intent, 'toArray')
                    ? $intent->toArray()
                    : [];
            } catch (ApiErrorException $e) {
                return new GatewayResult(
                    success: false,
                    errorMessage: $this->sanitizeErrorMessage($e->getMessage()),
                    rawResponse: $this->sanitizeArray($data, self::SESSION_ALLOWLIST),
                );
            } catch (\Throwable $e) {
                return new GatewayResult(
                    success: false,
                    errorMessage: 'Payment gateway error',
                    rawResponse: $this->sanitizeArray($data, self::SESSION_ALLOWLIST),
                );
            }

            $intentCurrency = strtoupper((string) ($intentData['currency'] ?? ''));
            $intentAmount = isset($intentData['amount']) ? (int) $intentData['amount'] : null;

            if ($intentCurrency !== $currency || $intentAmount === null || $intentAmount !== $amountTotal) {
                return new GatewayResult(
                    success: false,
                    gatewayTransactionId: $sessionId,
                    amount: self::fromMinorUnits($amountTotal, $currency !== '' ? $currency : 'USD'),
                    currency: $currency !== '' ? $currency : null,
                    status: 'failed',
                    errorMessage: 'Payment amount or currency mismatch',
                    rawResponse: $this->sanitizeSessionWithIntent($data, $paymentIntentId),
                );
            }
        }

        return new GatewayResult(
            success: $isPaid,
            gatewayTransactionId: $sessionId,
            amount: self::fromMinorUnits($amountTotal, $currency !== '' ? $currency : 'USD'),
            currency: $currency !== '' ? $currency : null,
            status: $isPaid ? 'paid' : 'failed',
            errorMessage: $isPaid ? null : 'Payment not completed',
            rawResponse: $this->sanitizeSessionWithIntent($data, $paymentIntentId),
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

        try {
            $session = $this->client()->checkout->sessions->retrieve(
                (string) $transaction->gateway_transaction_id
            );
            $data = is_object($session) && method_exists($session, 'toArray')
                ? $session->toArray()
                : [];
        } catch (ApiErrorException $e) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->sanitizeErrorMessage($e->getMessage()),
            );
        } catch (\Throwable $e) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway error',
            );
        }

        $paymentIntentId = $this->extractPaymentIntentId($data['payment_intent'] ?? null);

        if ($paymentIntentId === null) {
            return new GatewayResult(
                success: false,
                errorMessage: 'No refundable payment reference found for this order',
                rawResponse: $this->sanitizeArray($data, self::SESSION_ALLOWLIST),
            );
        }

        try {
            $refund = $this->client()->refunds->create([
                'payment_intent' => $paymentIntentId,
                'amount' => self::toMinorUnits($amount, $orderCurrency),
                'metadata' => ['order_id' => (string) $order->id, 'reason' => (string) ($reason ?? '')],
            ]);
            $refundData = is_object($refund) && method_exists($refund, 'toArray')
                ? $refund->toArray()
                : [];
        } catch (ApiErrorException $e) {
            return new GatewayResult(
                success: false,
                errorMessage: $this->sanitizeErrorMessage($e->getMessage()),
            );
        } catch (\Throwable $e) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Payment gateway error',
            );
        }

        // Fail-closed: a refund counts as successful ONLY when Stripe reports
        // status "succeeded". Refund statuses per
        // https://docs.stripe.com/api/refunds/object are pending,
        // requires_action, succeeded, failed, or canceled.
        $refundStatus = $refundData['status'] ?? null;

        if ($refundStatus !== 'succeeded') {
            return new GatewayResult(
                success: false,
                errorMessage: 'Refund not confirmed by gateway',
                rawResponse: $this->sanitizeArray($refundData, self::REFUND_ALLOWLIST),
            );
        }

        return new GatewayResult(
            success: true,
            gatewayTransactionId: isset($refundData['id']) ? (string) $refundData['id'] : null,
            amount: $amount,
            currency: $orderCurrency,
            status: 'succeeded',
            rawResponse: $this->sanitizeArray($refundData, self::REFUND_ALLOWLIST),
        );
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function code(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        $key = config('payment.gateways.stripe.secret_key');

        return is_string($key) ? trim($key) !== '' : !empty($key);
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        $supported = (array) (config('payment.gateways.stripe.supported_currencies') ?? []);

        return in_array(strtoupper($currencyCode), array_map('strtoupper', $supported), true);
    }

    /**
     * Convert a major-unit amount to Stripe minor units for the currency.
     *
     * Zero-decimal currencies keep the Stripe-specific override below; every
     * other exponent (3dp KWD/... vs 2dp) delegates to CurrencyPrecision so
     * gateway math and order snapshots can never disagree on the exponent.
     */
    public static function toMinorUnits(float $amount, string $currencyCode): int
    {
        $code = strtoupper(trim($currencyCode));

        if (in_array($code, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }

        return \App\Services\Payment\CurrencyPrecision::toMinorUnits($amount, $code);
    }

    /**
     * Convert Stripe minor units back to major units for the currency.
     */
    public static function fromMinorUnits(int $minorUnits, string $currencyCode): float
    {
        $code = strtoupper(trim($currencyCode));

        if (in_array($code, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (float) $minorUnits;
        }

        return \App\Services\Payment\CurrencyPrecision::fromMinorUnits($minorUnits, $code);
    }

    /**
     * Resolve the Checkout Session id that owns a PaymentIntent.
     *
     * Failure webhooks (payment_intent.payment_failed) carry only the pi_*
     * id while local transactions store the cs_* session id. Lists sessions
     * filtered by payment_intent (stripe-php v13
     * Checkout\SessionService::all supports this filter); null when the API
     * fails or nothing matches — the caller acks 200 and logs (documented).
     */
    public function findSessionIdByPaymentIntent(string $paymentIntentId): ?string
    {
        if (trim($paymentIntentId) === '') {
            return null;
        }

        try {
            $sessions = $this->client()->checkout->sessions->all([
                'payment_intent' => $paymentIntentId,
                'limit' => 1,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $data = null;
        if (is_object($sessions) && method_exists($sessions, 'toArray')) {
            $data = $sessions->toArray();
        } elseif (is_array($sessions)) {
            $data = $sessions;
        }

        $first = is_array($data) ? ($data['data'][0] ?? null) : null;
        if (is_object($first) && method_exists($first, 'toArray')) {
            $first = $first->toArray();
        }

        $id = is_array($first) ? ($first['id'] ?? null) : null;

        return is_string($id) && trim($id) !== '' ? $id : null;
    }

    private function client(): StripeClient
    {
        if ($this->client === null) {
            $this->client = new StripeClient((string) config('payment.gateways.stripe.secret_key'));
        }

        return $this->client;
    }

    private function appendQueryParam(string $url, string $pair): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $pair;
    }

    /**
     * The session's payment_intent is either a string id or an expanded
     * object/array. Anything else (missing, ambiguous shape) is treated as
     * absent so callers fail closed.
     */
    private function extractPaymentIntentId(mixed $paymentIntent): ?string
    {
        if (is_string($paymentIntent) && trim($paymentIntent) !== '') {
            return $paymentIntent;
        }

        if (is_object($paymentIntent) && method_exists($paymentIntent, 'toArray')) {
            $paymentIntent = $paymentIntent->toArray();
        }

        if (is_array($paymentIntent) && isset($paymentIntent['id']) && is_string($paymentIntent['id']) && trim($paymentIntent['id']) !== '') {
            return $paymentIntent['id'];
        }

        return null;
    }

    private function sanitizeSession(mixed $session): ?array
    {
        if (!is_object($session) || !method_exists($session, 'toArray')) {
            return null;
        }

        return $this->sanitizeArray($session->toArray(), self::SESSION_ALLOWLIST);
    }

    /**
     * Allowlisted session payload plus the `_payment_intent_id` correlation
     * key. Failure webhooks carry only pi_* while transactions store cs_*;
     * persisting the intent id here gives future failure paths a direct
     * lookup before falling back to the sessions->all() resolution.
     */
    private function sanitizeSessionWithIntent(array $data, ?string $paymentIntentId): ?array
    {
        $sanitized = $this->sanitizeArray($data, self::SESSION_ALLOWLIST) ?? [];

        if ($paymentIntentId !== null && trim($paymentIntentId) !== '') {
            $sanitized['_payment_intent_id'] = $paymentIntentId;
        }

        return $sanitized;
    }

    private function sanitizeArray(mixed $data, array $allowlist): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $allowed = [];
        foreach ($allowlist as $key) {
            if (array_key_exists($key, $data)) {
                $allowed[$key] = $data[$key];
            }
        }

        return $allowed;
    }

    /**
     * Stripe error messages never intentionally carry the secret, but redact
     * any embedded sk_live_/sk_test_ material before surfacing it.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        $redacted = preg_replace('/sk_(live|test)_[A-Za-z0-9]+/', '[redacted]', $message);

        if (!is_string($redacted) || trim($redacted) === '') {
            return 'Payment gateway error';
        }

        return $redacted;
    }
}

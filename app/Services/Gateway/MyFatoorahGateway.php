<?php

namespace App\Services\Gateway;

use App\DTOs\GatewayResult;
use App\Services\General\MyfatoraService;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use Marvel\Database\Models\Order;

class MyFatoorahGateway implements PaymentGatewayContract
{
    /**
     * gateway_response allowlist: only technical provider fields are ever
     * persisted/logged. Customer PII (CustomerName, CustomerMobile,
     * CustomerEmail, personal data) is stripped at the source. Layer-added
     * keys (_callback_type, _coupon_blocked_*) are merged afterwards by the
     * controller/handler and are unaffected.
     */
    private const RESPONSE_ALLOWLIST = [
        'InvoiceId',
        'InvoiceStatus',
        'InvoiceValue',
        'DisplayCurrencyIso',
        'InvoiceURL',
        'RefundId',
        'RefundStatus',
        'IsDirectPayment',
        'PaymentURL',
    ];

    public function __construct(
        private MyfatoraService $myfatoraService,
        private \App\Services\Payment\CustomerContactResolver $customerContactResolver,
        private \App\Services\Payment\PaymentCurrencyResolver $currencyResolver,
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

        $mobile = $order->user_phone ?? '';
        $mobile = preg_replace('/^\+?20/', '', $mobile);
        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        $mobile = substr($mobile, 0, 11);

        $data = [
            'InvoiceValue' => $amount,
            'CustomerName' => $order->name ?? 'Customer',
            'NotificationOption' => 'LNK',
            'DisplayCurrencyIso' => $orderCurrency,
            'MobileCountryCode' => '+20',
            'CustomerMobile' => $mobile,
            'CustomerEmail' => $this->customerContactResolver->emailForGateway($order),
            'language' => app()->getLocale() == 'ar' ? 'ar' : 'en',
            'CallBackUrl' => $callbackUrl,
            'ErrorUrl' => $errorUrl,
        ];

        $response = $this->myfatoraService->createInvoice($data);

        if (!is_array($response)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'No response from payment gateway',
            );
        }

        $invoiceUrl = data_get($response, 'Data.InvoiceURL');
        $invoiceId = data_get($response, 'Data.InvoiceId');

        if (!$invoiceUrl || !$invoiceId) {
            return new GatewayResult(
                success: false,
                errorMessage: data_get($response, 'Data.InvoiceError') ?? 'Invalid gateway response',
                rawResponse: $this->sanitizeResponse($response),
            );
        }

        return new GatewayResult(
            success: true,
            redirectUrl: $invoiceUrl,
            gatewayTransactionId: (string) $invoiceId,
            currency: $orderCurrency,
            status: 'pending',
            rawResponse: $this->sanitizeResponse($response),
        );
    }

    public function verifyPayment(string $gatewayTransactionId): GatewayResult
    {
        $data = [
            'Key' => $gatewayTransactionId,
            'KeyType' => 'PaymentId',
        ];

        $response = $this->myfatoraService->checkInvoice($data);

        if (!is_array($response)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'No response from payment gateway',
            );
        }

        $invoiceStatus = data_get($response, 'Data.InvoiceStatus');
        $invoiceId = data_get($response, 'Data.InvoiceId');
        $invoiceAmount = data_get($response, 'Data.InvoiceValue');
        $invoiceCurrency = data_get($response, 'Data.DisplayCurrencyIso');

        if (!$invoiceStatus) {
            return new GatewayResult(
                success: false,
                errorMessage: 'Invalid gateway response',
                rawResponse: $this->sanitizeResponse($response),
            );
        }

        $isPaid = $invoiceStatus === 'Paid';

        return new GatewayResult(
            success: $isPaid,
            gatewayTransactionId: (string) $invoiceId,
            amount: $invoiceAmount !== null ? (float) $invoiceAmount : null,
            currency: $invoiceCurrency,
            status: $isPaid ? 'paid' : 'failed',
            errorMessage: $isPaid ? null : (data_get($response, 'Data.InvoiceError') ?? 'Payment not completed'),
            rawResponse: $this->sanitizeResponse($response),
        );
    }

public function name(): string
    {
        return 'myfatoorah';
    }

    public function code(): string
    {
        return 'myfatoorah';
    }

    public function isConfigured(): bool
    {
        $key = config('payment.gateways.myfatoorah.api_key');

        return is_string($key) ? trim($key) !== '' : !empty($key);
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        $supported = (array) (config('payment.gateways.myfatoorah.supported_currencies') ?? []);

        return in_array(strtoupper($currencyCode), array_map('strtoupper', $supported), true);
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

        $data = [
            'Key' => $transaction->gateway_transaction_id,
            'KeyType' => 'PaymentId',
            'Amount' => $amount,
            'Comment' => $reason ?? 'Refund for order #' . $order->id,
        ];

        $response = $this->myfatoraService->makeRefund($data);

        if (!is_array($response)) {
            return new GatewayResult(
                success: false,
                errorMessage: 'No response from payment gateway',
            );
        }

        $refundId = data_get($response, 'Data.RefundId');
        $refundStatus = data_get($response, 'Data.RefundStatus');

        // Fail-closed: a refund counts as successful ONLY when the provider
        // positively confirms it via RefundStatus. MyFatoorah surfaces the
        // outcome there (e.g. "Refunded"); an unknown, refused, pending, or
        // absent status is a failure even though the HTTP call succeeded
        // (MyfatoraService only returns IsSuccess responses).
        if (!$this->isRefundConfirmed($refundStatus)) {
            return new GatewayResult(
                success: false,
                errorMessage: data_get($response, 'Message')
                    ?? data_get($response, 'Data.InvoiceError')
                    ?? 'Refund not confirmed by gateway',
                rawResponse: $this->sanitizeResponse($response),
            );
        }

return new GatewayResult(
            success: true,
            gatewayTransactionId: $refundId ? (string) $refundId : null,
            amount: $amount,
            currency: $orderCurrency,
            status: is_string($refundStatus) ? $refundStatus : 'refunded',
            rawResponse: $this->sanitizeResponse($response),
        );
    }

    /**
     * Positive provider confirmation for MakeRefund: the status names a
     * completed refund ("Refunded") and carries no refusal/pending marker
     * ("RefundFailed", "RefundPending", ...).
     */
    private function isRefundConfirmed(mixed $refundStatus): bool
    {
        if (!is_string($refundStatus) || trim($refundStatus) === '') {
            return false;
        }

        $normalized = strtolower($refundStatus);

        if (!str_contains($normalized, 'refund')) {
            return false;
        }

        foreach (['fail', 'error', 'reject', 'declin', 'cancel', 'void', 'pend'] as $negative) {
            if (str_contains($normalized, $negative)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strip a provider payload down to the technical allowlist before it is
     * stored in gateway_response or logs. Unknown shapes collapse to an
     * empty Data envelope (fail-closed, never PII).
     */
    private function sanitizeResponse(?array $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        $data = data_get($response, 'Data');

        if (!is_array($data)) {
            return ['Data' => []];
        }

        $allowed = [];
        foreach (self::RESPONSE_ALLOWLIST as $key) {
            if (array_key_exists($key, $data)) {
                $allowed[$key] = $data[$key];
            }
        }

        return ['Data' => $allowed];
    }
}

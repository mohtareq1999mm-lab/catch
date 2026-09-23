<?php

namespace App\Services\Payment;

use App\Services\General\CartInventoryService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Coupon\CouponReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\Coupon;
use Marvel\Enums\ShippingMethod;
use Marvel\Traits\ApiResponse;

class PaymentCheckoutHandler
{
    use ApiResponse;

    public function __construct(
        private PaymentGatewayFactory $paymentGatewayFactory,
        private PaymentGatewayRegistry $gatewayRegistry,
        private CartInventoryService $cartInventoryService,
        private CouponReservationService $couponReservationService,
        private PaymentCurrencyResolver $currencyResolver,
    ) {}

    public function handleOnlinePayment(
        Request $request,
        Order $order,
        float $amount,
        string $gateway,
        ?string $callbackUrl = null,
        ?string $errorUrl = null,
    ): JsonResponse {
        $orderCurrencyForGate = $this->currencyResolver->forOrder($order);
        $gate = $this->gatewayRegistry->canInitiate($gateway, 'online', $orderCurrencyForGate);

        if (!$gate['ok']) {
            if (($gate['reason'] ?? '') === 'currency_unsupported') {
                return $this->apiResponse(
                    __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $orderCurrencyForGate]),
                    422,
                    false
                );
            }

            return $this->apiResponse(
                __('message.ERROR.PAYMENT_GATEWAY_UNAVAILABLE'),
                422,
                false
            );
        }

        try {
            $gatewayInstance = $this->paymentGatewayFactory->make($gateway);
        } catch (\App\Exceptions\UnsupportedGatewayException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        $callbackUrl ??= route('api.checkout.callback');
        $errorUrl ??= route('api.checkout.errorCallback');

        $orderCurrency = $this->currencyResolver->forOrder($order);

        // Double supportsCurrency check (deliberate, not redundant):
        // canInitiate() above gates on the REGISTRY definition (merged
        // config+settings, mockable), while this gates on the live ADAPTER
        // instance the factory just built. A drift between the two (stale
        // settings row, adapter reading different config keys) fails closed
        // here instead of reaching the provider.
        if (!$gatewayInstance->supportsCurrency($orderCurrency)) {
            return $this->apiResponse(
                __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $orderCurrency]),
                422,
                false
            );
        }

        // Reserve coupon BEFORE creating gateway invoice (Rule 9)
        if ($order->coupon) {
            try {
                // CP-09: canonical lookup (case-insensitive, trimmed).
                $coupon = Coupon::byCode($order->coupon)->first();
                if ($coupon) {
                    $this->couponReservationService->reserve($order, $coupon);
                }
            } catch (\RuntimeException $e) {
                return $this->apiResponse($e->getMessage(), 422, false);
            }
        }

        $result = null;

        try {
            $result = $gatewayInstance->createInvoice(
                $order,
                $amount,
                $callbackUrl,
                $errorUrl,
            );
        } catch (\Throwable $e) {
            // Failure injection (§48): a provider timeout/exception must fail
            // closed with NO transaction row, the order still pending, and the
            // coupon reservation released so a retry can re-reserve.
            report($e);
            $this->releaseCouponReservation($order);

            return $this->apiResponse(ERROR_CREATING_INVOICE, 500, false);
        }

        if (!$result->success) {
            // Same release: no invoice exists, so nothing can complete — the
            // reservation must not linger until TTL and block a retry.
            $this->releaseCouponReservation($order);

            return $this->apiResponse($result->errorMessage ?? ERROR_CREATING_INVOICE, 500, false);
        }

        $rawResponse = is_array($result->rawResponse) ? $result->rawResponse : [];
        $rawResponse['_callback_type'] = $request->type ?? 'web';

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $request->user()->id,
            'invoice_id' => $result->gatewayTransactionId,
            'payment_method' => $gateway,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => $this->currencyResolver->forOrder($order),
            'gateway_transaction_id' => $result->gatewayTransactionId,
            'gateway_response' => $rawResponse,
        ]);

        if (!$transaction) {
            return $this->apiResponse(ERROR_CREATING_TRANSACTION, 500, false);
        }

        return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, ['url' => $result->redirectUrl]);
    }

    public function handleCodPayment(Request $request, Order $order, string $shippingMethod = ShippingMethod::SCHEDULED): JsonResponse
    {
        // Reserve coupon for COD payment (Rule 9)
        if ($order->coupon) {
            try {
                // CP-09: canonical lookup (case-insensitive, trimmed).
                $coupon = Coupon::byCode($order->coupon)->first();
                if ($coupon) {
                    $this->couponReservationService->reserve($order, $coupon);
                }
            } catch (\RuntimeException $e) {
                return $this->apiResponse($e->getMessage(), 422, false);
            }
        }

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $request->user()->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => $order->total_price,
            'currency' => $this->currencyResolver->forOrder($order),
        ]);

        if (!$transaction) {
            return $this->apiResponse(ERROR_CREATING_TRANSACTION, 500, false);
        }

        return $this->apiResponse(__('checkout.cod_success'), 200, true, [
            'order_id' => $order->id,
        ]);
    }

    public function handleCashierQrPayment(Request $request, Order $order, string $shippingMethod = ShippingMethod::SCHEDULED): JsonResponse
    {
        // Reserve coupon for cashier payment (Rule 9)
        if ($order->coupon) {
            try {
                // CP-09: canonical lookup (case-insensitive, trimmed).
                $coupon = Coupon::byCode($order->coupon)->first();
                if ($coupon) {
                    $this->couponReservationService->reserve($order, $coupon);
                }
            } catch (\RuntimeException $e) {
                return $this->apiResponse($e->getMessage(), 422, false);
            }
        }

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $request->user()->id,
            'payment_method' => 'pay_at_cashier',
            'status' => 'pending',
            'amount' => $order->total_price,
            'currency' => $this->currencyResolver->forOrder($order),
        ]);

        if (!$transaction) {
            return $this->apiResponse(ERROR_CREATING_TRANSACTION, 500, false);
        }

        return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
            'order_id' => $order->id,
        ]);
    }

    /**
     * Release this order's coupon reservation after a failed initiation.
     * No invoice exists, so nothing downstream can complete — holding the
     * reservation until TTL would only block a retry. Deleting a missing
     * row is a no-op; failures here must never mask the gateway error.
     */
    private function releaseCouponReservation(Order $order): void
    {
        if (!$order->coupon) {
            return;
        }

        try {
            $this->couponReservationService->release($order);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
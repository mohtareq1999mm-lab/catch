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
        private CartInventoryService $cartInventoryService,
        private CouponReservationService $couponReservationService,
    ) {}

    public function handleOnlinePayment(
        Request $request,
        Order $order,
        float $amount,
        string $gateway,
        ?string $callbackUrl = null,
        ?string $errorUrl = null,
    ): JsonResponse {
        try {
            $gatewayInstance = $this->paymentGatewayFactory->make($gateway);
        } catch (\App\Exceptions\UnsupportedGatewayException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        $callbackUrl ??= route('api.checkout.callback');
        $errorUrl ??= route('api.checkout.errorCallback');

        $orderCurrency = $order->currency_code ?? $order->base_currency_code ?? config('payment.default_currency', 'EGP');

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
                $coupon = Coupon::where('code', $order->coupon)->first();
                if ($coupon) {
                    $this->couponReservationService->reserve($order, $coupon);
                }
            } catch (\RuntimeException $e) {
                return $this->apiResponse($e->getMessage(), 422, false);
            }
        }

        $result = $gatewayInstance->createInvoice(
            $order,
            $amount,
            $callbackUrl,
            $errorUrl,
        );

        if (!$result->success) {
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
            'currency' => $order->currency_code ?? $order->base_currency_code ?? config('payment.default_currency', 'EGP'),
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
                $coupon = Coupon::where('code', $order->coupon)->first();
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
            'currency' => $order->currency_code ?? $order->base_currency_code ?? config('payment.default_currency', 'EGP'),
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
                $coupon = Coupon::where('code', $order->coupon)->first();
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
            'currency' => $order->currency_code ?? $order->base_currency_code ?? config('payment.default_currency', 'EGP'),
        ]);

        if (!$transaction) {
            return $this->apiResponse(ERROR_CREATING_TRANSACTION, 500, false);
        }

        return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
            'order_id' => $order->id,
        ]);
    }
}
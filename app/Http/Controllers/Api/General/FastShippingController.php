<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Services\General\FastShippingService;
use App\Services\General\CartInventoryService;
use App\Services\Payment\PaymentCheckoutHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Enums\ShippingMethod;
use Marvel\Http\Requests\FastCheckoutRequest;
use Marvel\Traits\ApiResponse;

class FastShippingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FastShippingService $fastShippingService,
        private CartInventoryService $cartInventoryService,
        private PaymentCheckoutHandler $paymentCheckoutHandler,
    ) {}

    public function status(): JsonResponse
    {
        $payload = $this->fastShippingService->getStatus();

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $payload);
    }

    public function products(Request $request): JsonResponse
    {
        $products = $this->fastShippingService->getFastShippingProducts($request);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $products);
    }

    public function checkout(FastCheckoutRequest $request): JsonResponse
    {
        $requestData = $request->validated();
        $requestData['user_id'] = $request->user()->id;

        $cart = $this->cartInventoryService->getActiveCartForUser($request->user());
        if (!$cart) {
            return $this->apiResponse(__('message.ERROR.CART_NOT_FOUND'), 400, false);
        }

        try {
            $order = $this->fastShippingService->createFastOrder($request);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        } catch (\Throwable $e) {
            report($e);
            return $this->apiResponse(FILED_TO_CREATE_ORDER_TRY_AGAIN, 500, false);
        }

        $paymentMethod = $request->input('payment_method', 'online');
        $gateway = $request->input('gateway', config('payment.default_gateway', 'myfatoorah'));
        $fulfillmentType = $request->input('fulfillment_type', 'delivery');

        if ($paymentMethod === 'cod' && $fulfillmentType === 'pickup') {
            return $this->apiResponse(__('message.ERROR.COD_NOT_AVAILABLE_FOR_PICKUP'), 422, false);
        }

        if ($paymentMethod === 'online') {
            return $this->paymentCheckoutHandler->handleOnlinePayment($request, $order, $order->total_price, $gateway);
        }

        if ($paymentMethod === 'cod') {
            return $this->paymentCheckoutHandler->handleCodPayment($request, $order, ShippingMethod::FAST);
        }

        if ($paymentMethod === 'pay_at_cashier') {
            return $this->paymentCheckoutHandler->handleCashierQrPayment($request, $order, ShippingMethod::FAST);
        }

        return $this->apiResponse(__('message.ERROR.INVALID_PAYMENT_METHOD'), 422, false);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = $this->fastShippingService->paginateFastOrders($request);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $orders);
    }
}

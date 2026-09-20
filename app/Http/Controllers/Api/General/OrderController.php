<?php

namespace App\Http\Controllers\Api\General;

use App\DTOs\GatewayResult;
use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Invoice\CustomerInvoiceResource;
use App\Http\Resources\Order\OrderCollection;
use App\Http\Resources\Order\OrderResource;
use App\Models\Invoice;
use App\Services\General\CartInventoryService;
use App\Services\General\OrderService;
use App\Services\Inventory\OrderReservationService;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentGatewayFactory;
use App\Events\OrderCancelled;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Enums\PaymentStatus;
use Marvel\Http\Requests\OrderCreateRequest;
use Marvel\Traits\ApiResponse;

class OrderController extends Controller
{
    use ApiResponse , HasCache;
    protected $orderService;
    protected $cartInventoryService;

    public function __construct(
        OrderService $orderService,
        CartInventoryService $cartInventoryService,
        private OrderReservationService $orderReservationService,
        private PaymentGatewayFactory $paymentGatewayFactory,
        private PaymentCheckoutHandler $paymentCheckoutHandler,
    ) {
        $this->orderService = $orderService;
        $this->cartInventoryService = $cartInventoryService;
    }

    public function index(Request $request): JsonResponse
    {
        $orders = $this->orderService->paginateForUser($request);
        $ordersCache = $this->remember(FrontendResource::ORDERS->value, md5($request->fullUrl()), $orders);

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            new OrderCollection($orders)
        );
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $order = $this->orderService->getOrderForUser($request, $orderId);

        if (!$order) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            OrderResource::make($order)
        );
    }

    public function eligiblePromotions(): JsonResponse
    {
        $payload = $this->orderService->eligiblePromotionsForUser();

        if (!$payload) {
            return $this->apiResponse(CART_NOT_FOUND, 400, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $payload);
    }

    public function checkout(OrderCreateRequest $request)
    {
        $orderDataUser = $request->validated();
        $orderDataUser['user_id'] = $request->user()->id;

        $cart = $this->cartInventoryService->getActiveCartForUser($request->user());
        if (!$cart) {
            return $this->apiResponse(CART_NOT_FOUND, 400, false);
        }

        $paymentMethod = $request->input('payment_method', 'online');
        $gateway = $request->input('gateway', config('payment.default_gateway', 'myfatoorah'));
        $fulfillmentType = $request->input('fulfillment_type', 'delivery');

        if ($paymentMethod === 'cod' && $fulfillmentType === 'pickup') {
            return $this->apiResponse(COD_NOT_AVAILABLE_FOR_PICKUP, 422, false);
        }

        $request->merge([
            'fulfillment_type' => $fulfillmentType,
            'payment_method' => $paymentMethod,
            'payment_gateway' => $paymentMethod === 'online' ? $gateway : null,
        ]);

        try {
            $order = $this->orderService->addItemsInOrder($request);
        } catch (\App\Exceptions\CartEmptyException $e) {
            // Concurrent/previous checkout already consumed this cart.
            return $this->apiResponse(CART_NOT_FOUND, 400, false);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        if (!$order) {
            return $this->apiResponse(ERROR_ADDING_ITEMS_TO_ORDER, 500, false);
        }

        if ($paymentMethod === 'online') {
            $orderPrice = round((float) $order->total_price, 2);
            if ($orderPrice <= 0) {
                return $this->apiResponse(FILED_TO_CREATE_ORDER_TRY_AGAIN, 500, false);
            }
            return $this->paymentCheckoutHandler->handleOnlinePayment($request, $order, $orderPrice, $gateway);
        }

        if ($paymentMethod === 'cod') {
            return $this->paymentCheckoutHandler->handleCodPayment($request, $order);
        }

        if ($paymentMethod === 'pay_at_cashier') {
            return $this->paymentCheckoutHandler->handleCashierQrPayment($request, $order);
        }

        return $this->apiResponse(INVALID_PAYMENT_METHOD, 422, false);
    }

    public function markCodAsPaid(int $orderId, Request $request): JsonResponse
    {
        $order = Order::query()->findOrFail($orderId);

        try {
            $this->orderService->markCodAsPaid($order);
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true);
    }

    public function markCashierPaid(int $orderId, Request $request): JsonResponse
    {
        $order = Order::query()->findOrFail($orderId);

        try {
            $this->orderService->markCashierPaid($order);
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true);
    }

    public function checkoutCallback(Request $request)
    {
        $paymentId = $request->query('paymentId', $request->input('paymentId'));
        if (!$paymentId) {
            return $this->apiResponse(MISSING_PAYMENT_ID, 400, false);
        }
        if (!is_string($paymentId) || strlen($paymentId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $paymentId)) {
            return $this->apiResponse(MISSING_PAYMENT_ID, 400, false);
        }
        $callbackTypeInput = $request->input('type', $request->query('type'));
        if ($callbackTypeInput !== null && !in_array($callbackTypeInput, ['web', 'mobile'], true)) {
            return $this->apiResponse(INVALID_PAYMENT_METHOD, 400, false);
        }

        $gatewayName = 'myfatoorah';

        $transaction = Transaction::where('gateway_transaction_id', $paymentId)
            ->orWhere('invoice_id', $paymentId)
            ->first();

        $gatewayName = $transaction?->payment_method ?? $gatewayName;

        try {
            $gateway = $this->paymentGatewayFactory->make($gatewayName);
        } catch (\App\Exceptions\UnsupportedGatewayException $e) {
            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 500, false);
        }

        $result = $gateway->verifyPayment($paymentId);

        $verifiedInvoiceId = $result->gatewayTransactionId;

        if (!$transaction) {
            $transaction = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)
                ->orWhere('invoice_id', $verifiedInvoiceId)
                ->first();
        }

        $order = $transaction?->order;

        // P2-5: Structured logging for payment verification
        try {
            if ($order) {
                $verifyResult = $result->success ? 'success' : 'failed';
                \App\Services\Logging\OrderTrackingLogger::logPaymentVerification($order, $verifyResult, is_array($result->rawResponse) ? $result->rawResponse : []);
                \App\Services\Metrics\OrderTrackingMetrics::incrementPaymentVerification($verifyResult);
            }
        } catch (\Throwable $e) {}

        $callbackType = $this->getCallbackType($transaction, $request);

        if (!$result->success) {
            if ($transaction) {
                $sanitizedFail = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? '')), 500, '');
                DB::transaction(function () use ($transaction, $paymentId, $verifiedInvoiceId, $result, $sanitizedFail) {
                    $lt = Transaction::where('gateway_transaction_id', $paymentId)->orWhere('invoice_id', $paymentId)->lockForUpdate()->first();
                    if (!$lt) $lt = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)->orWhere('invoice_id', $verifiedInvoiceId)->lockForUpdate()->first();
                    if (!$lt || $lt->status !== 'pending') return;
                    $existingResponse = is_array($lt->gateway_response) ? $lt->gateway_response : [];
                    $callbackType = $existingResponse['_callback_type'] ?? null;
                    $mergedResponse = is_array($result->rawResponse) ? $result->rawResponse : [];
                    if ($callbackType) $mergedResponse['_callback_type'] = $callbackType;
                    $lt->update([
                        'status' => $result->status ?? 'failed',
                        'gateway_response' => $mergedResponse,
                        'error_message' => $sanitizedFail,
                    ]);
                });
            }

            try {
                if ($order) {
                    event(new PaymentFailed($order));
                }
            } catch (\Throwable $e) {
                report($e);
            }

            $rawError = $result->errorMessage ?? __(PAYMENT_FAILED);
            $errorMessage = \Illuminate\Support\Str::limit(strip_tags((string) $rawError), 500, '');

            if ($callbackType === 'mobile') {
                return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
                    'status' => 'failed',
                    'message' => $errorMessage,
                    'payment_id' => $paymentId,
                ]);
            }

            return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query([
                'status' => 'failed',
                'message' => $errorMessage,
                'payment_id' => $paymentId,
            ]));
        }

        if (!$order) {
            if ($callbackType === 'mobile') {
                return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
                    'status' => 'success',
                    'message' => __(PAYMENT_SUCCESSFUL),
                    'payment_id' => $paymentId,
                ]);
            }

            return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/success?' . http_build_query([
                'status' => 'success',
                'message' => __(PAYMENT_SUCCESSFUL),
                'payment_id' => $paymentId,
            ]));
        }

        $isTestGateway = str_contains(config('services.myfatoorah.base_url', ''), 'apitest');
        $isProduction = app()->environment('production');

        // Transactionally safe mismatch handling + success path under same lock.
        // Uses integer cents to avoid floating-point authority errors.
        $processed = false;
        $mismatchHandled = false;

        DB::transaction(function () use ($order, $transaction, $paymentId, $verifiedInvoiceId, $result, $isTestGateway, $isProduction, &$processed, &$mismatchHandled) {
            $lockedTransaction = Transaction::where('gateway_transaction_id', $paymentId)
                ->orWhere('invoice_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (!$lockedTransaction) {
                $lockedTransaction = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)
                    ->orWhere('invoice_id', $verifiedInvoiceId)
                    ->lockForUpdate()
                    ->first();
            }

            if (!$lockedTransaction) {
                return;
            }

            $lockedOrder = $lockedTransaction->order()->lockForUpdate()->first();

            if (!$lockedOrder) {
                return;
            }

            if ($lockedOrder->status !== 'pending') {
                return;
            }

            // Re-evaluate amount/currency inside the lock (fail-closed on null). Use 1000 factor for 3-decimal currencies (KWD/BHD).
            $hasMismatch = false;
            $expectedCents = (int) round((float) $lockedOrder->total_price * 1000);
            $receivedCents = $result->amount !== null ? (int) round((float) $result->amount * 1000) : null;
            $receivedCurrency = $result->currency !== null ? strtoupper(trim((string) $result->currency)) : null;
            $expectedCurrencyFresh = $lockedOrder->currency_code ?? $lockedOrder->base_currency_code ?? config('payment.default_currency', 'EGP');
            $expectedCurrencyNorm = strtoupper(trim((string) $expectedCurrencyFresh));

            if ($receivedCents === null) {
                $hasMismatch = true;
                \Log::warning('Payment amount missing - blocking order', [
                    'order_id' => $lockedOrder->id,
                    'expected_cents' => $expectedCents,
                    'received' => $result->amount,
                ]);
            } elseif ($receivedCents !== $expectedCents) {
                if ($isTestGateway && !$isProduction) {
                    \Log::info('Payment amount mismatch ignored (test gateway, non-production)', [
                        'order_id' => $lockedOrder->id,
                        'expected_cents' => $expectedCents,
                        'received_cents' => $receivedCents,
                        'expected' => (float) $lockedOrder->total_price,
                        'received' => $result->amount,
                    ]);
                } else {
                    $hasMismatch = true;
                    \Log::warning('Payment amount mismatch - blocking order', [
                        'order_id' => $lockedOrder->id,
                        'expected_cents' => $expectedCents,
                        'received_cents' => $receivedCents,
                        'currency' => $result->currency,
                    ]);
                }
            }

            if (!$hasMismatch) {
                if ($receivedCurrency === null) {
                    $hasMismatch = true;
                    \Log::warning('Payment currency missing - blocking order', [
                        'order_id' => $lockedOrder->id,
                        'expected' => $expectedCurrencyNorm,
                        'received' => $result->currency,
                    ]);
                } elseif ($receivedCurrency !== $expectedCurrencyNorm) {
                    if ($isTestGateway && !$isProduction) {
                        \Log::info('Payment currency mismatch ignored (test gateway, non-production)', [
                            'order_id' => $lockedOrder->id,
                            'expected' => $expectedCurrencyNorm,
                            'received' => $receivedCurrency,
                        ]);
                    } else {
                        $hasMismatch = true;
                        \Log::warning('Payment currency mismatch - blocking order', [
                            'order_id' => $lockedOrder->id,
                            'expected' => $expectedCurrencyNorm,
                            'received' => $receivedCurrency,
                        ]);
                    }
                }
            }

            if ($hasMismatch) {
                $existingResponse = is_array($lockedTransaction->gateway_response) ? $lockedTransaction->gateway_response : [];
                $mergedResponse = is_array($result->rawResponse) ? $result->rawResponse : [];
                // Preserve callback type if present
                if (isset($existingResponse['_callback_type'])) {
                    $mergedResponse['_callback_type'] = $existingResponse['_callback_type'];
                }
                $sanitizedMismatch = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? 'Amount or currency mismatch')), 500, '');
                $lockedTransaction->update([
                    'status' => 'failed',
                    'gateway_response' => $mergedResponse,
                    'error_message' => $sanitizedMismatch,
                ]);
                $mismatchHandled = true;
                return;
            }

            $existingResponse = is_array($lockedTransaction->gateway_response) ? $lockedTransaction->gateway_response : [];
            $callbackType = $existingResponse['_callback_type'] ?? null;
            $mergedResponse = is_array($result->rawResponse) ? $result->rawResponse : [];
            if ($callbackType) {
                $mergedResponse['_callback_type'] = $callbackType;
            }
            $sanitizedPaid = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? '')), 500, '');
            $lockedTransaction->update([
                'status' => 'paid',
                'gateway_response' => $mergedResponse,
                'error_message' => $sanitizedPaid ?: null,
                'paid_at' => now(),
            ]);

            $orderUpdateData = [];
            if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_status')) {
                $orderUpdateData['payment_status'] = \Marvel\Enums\PaymentStatus::SUCCESS;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'paid_at')) {
                $orderUpdateData['paid_at'] = now();
            }
            if (!empty($orderUpdateData)) {
                $lockedOrder->update($orderUpdateData);
            }

            // Commit THIS order's reservation. The order snapshot is the only
            // inventory source — the current cart is never read here.
            $this->orderReservationService->commit($lockedOrder);

            $this->orderService->finalizePromotionUsageAfterPayment($lockedOrder);

            // emitPaymentSuccess = false: this callback owns the PaymentSucceeded
            // dispatch and fires it once after the transaction commits.
            $this->orderService->changeOrderStatus($lockedTransaction->invoice_id, 'completed', null, false);

            $processed = true;
        });

        if ($mismatchHandled) {
            try {
                event(new PaymentFailed($order));
            } catch (\Throwable $e) {
                report($e);
            }
            $rawError = $result->errorMessage ?? __(PAYMENT_FAILED);
            $errorMessage = \Illuminate\Support\Str::limit(strip_tags((string) $rawError), 500, '');
            if ($callbackType === 'mobile') {
                return $this->apiResponse(PAYMENT_FAILED, 400, false, [
                    'status' => 'failed',
                    'message' => $errorMessage,
                    'payment_id' => $paymentId,
                ]);
            }
            return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query([
                'status' => 'failed',
                'message' => $errorMessage,
                'payment_id' => $paymentId,
            ]));
        }

        if ($processed) {
            try {
                event(new PaymentSucceeded($order->fresh()));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($this->getCallbackType($transaction, $request) === 'mobile') {
            return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
                'status' => 'success',
                'message' => __(PAYMENT_SUCCESSFUL),
                'payment_id' => $paymentId,
                'order_id' => $order->id,
            ]);
        }

        return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/success?' . http_build_query([
            'status' => 'success',
            'message' => __(PAYMENT_SUCCESSFUL),
            'payment_id' => $paymentId,
            'order_id' => $order->id,
        ]));

    }

    public function checkoutErrorCallback(Request $request)
    {
        $paymentId = $request->query('paymentId', $request->input('paymentId'));
        if (!$paymentId) {
            return $this->apiResponse(MISSING_PAYMENT_ID, 400, false);
        }
        if (!is_string($paymentId) || strlen($paymentId) > 191 || !preg_match('/^[A-Za-z0-9\-_]+$/', $paymentId)) {
            return $this->apiResponse(MISSING_PAYMENT_ID, 400, false);
        }
        $callbackTypeInput = $request->input('type', $request->query('type'));
        if ($callbackTypeInput !== null && !in_array($callbackTypeInput, ['web', 'mobile'], true)) {
            return $this->apiResponse(INVALID_PAYMENT_METHOD, 400, false);
        }

        $gatewayName = 'myfatoorah';

        $transaction = Transaction::where('gateway_transaction_id', $paymentId)
            ->orWhere('invoice_id', $paymentId)
            ->first();

        $gatewayName = $transaction?->payment_method ?? $gatewayName;

        try {
            $gateway = $this->paymentGatewayFactory->make($gatewayName);
        } catch (\App\Exceptions\UnsupportedGatewayException $e) {
            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 500, false);
        }

        $result = $gateway->verifyPayment($paymentId);

        $verifiedInvoiceId = $result->gatewayTransactionId;

        if (!$transaction) {
            $transaction = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)
                ->orWhere('invoice_id', $verifiedInvoiceId)
                ->first();
        }

        $order = $transaction?->order;

        // P2-5: Structured logging for error callback verification
        try {
            if ($order) {
                $verifyResult = $result->success ? 'success' : 'failed';
                \App\Services\Logging\OrderTrackingLogger::logPaymentVerification($order, $verifyResult, is_array($result->rawResponse) ? $result->rawResponse : []);
                \App\Services\Metrics\OrderTrackingMetrics::incrementPaymentVerification($verifyResult);
            }
        } catch (\Throwable $e) {}

        $errorCallbackType = $this->getCallbackType($transaction, $request);

        if ($result->success) {
            $isTestGatewayErr = str_contains(config('services.myfatoorah.base_url', ''), 'apitest');
            $isProductionErr = app()->environment('production');
            $mismatchInError = false;
            $processedErrorSuccess = false;
            DB::transaction(function () use ($paymentId, $verifiedInvoiceId, $result, $isTestGatewayErr, $isProductionErr, &$mismatchInError, &$processedErrorSuccess) {
                $lt = Transaction::where('gateway_transaction_id', $paymentId)->orWhere('invoice_id', $paymentId)->lockForUpdate()->first();
                if (!$lt) $lt = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)->orWhere('invoice_id', $verifiedInvoiceId)->lockForUpdate()->first();
                if (!$lt) return;
                $lockedOrder = $lt->order()->lockForUpdate()->first();
                if (!$lockedOrder) return;
                if ($lockedOrder->status !== 'pending') return;
                $expectedCurrencyErr = $lockedOrder->currency_code ?? $lockedOrder->base_currency_code ?? config('payment.default_currency', 'EGP');
                $expectedCentsErr = (int) round((float) $lockedOrder->total_price * 1000);
                $receivedCentsErr = $result->amount !== null ? (int) round((float) $result->amount * 1000) : null;
                $receivedCurrencyErr = $result->currency !== null ? strtoupper(trim((string) $result->currency)) : null;
                $expectedCurrencyNormErr = strtoupper(trim((string) $expectedCurrencyErr));
                $hasMismatch = false;
                if ($receivedCentsErr === null) $hasMismatch = true;
                elseif ($receivedCentsErr !== $expectedCentsErr) {
                    if (!($isTestGatewayErr && !$isProductionErr)) $hasMismatch = true;
                }
                if (!$hasMismatch) {
                    if ($receivedCurrencyErr === null) $hasMismatch = true;
                    elseif ($receivedCurrencyErr !== $expectedCurrencyNormErr) {
                        if (!($isTestGatewayErr && !$isProductionErr)) $hasMismatch = true;
                    }
                }
                if ($hasMismatch) {
                    $sanitized = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? 'Amount or currency mismatch')), 500, '');
                    $merged = is_array($result->rawResponse) ? $result->rawResponse : [];
                    if (isset($lt->gateway_response['_callback_type'])) $merged['_callback_type'] = $lt->gateway_response['_callback_type'];
                    $lt->update(['status'=>'failed','gateway_response'=>$merged,'error_message'=>$sanitized]);
                    $mismatchInError = true;
                    return;
                }
                // Success with no mismatch via error-callback: complete payment atomically (avoid orphan pending)
                $existingResponse = is_array($lt->gateway_response) ? $lt->gateway_response : [];
                $cbType = $existingResponse['_callback_type'] ?? null;
                $mergedResponse = is_array($result->rawResponse) ? $result->rawResponse : [];
                if ($cbType) $mergedResponse['_callback_type'] = $cbType;
                $sanitizedOk = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? '')), 500, '');
                $lt->update(['status'=>'paid','gateway_response'=>$mergedResponse,'error_message'=>$sanitizedOk ?: null,'paid_at'=>now()]);
                $orderUpdateData = [];
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_status')) $orderUpdateData['payment_status'] = \Marvel\Enums\PaymentStatus::SUCCESS;
                if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'paid_at')) $orderUpdateData['paid_at'] = now();
                if (!empty($orderUpdateData)) $lockedOrder->update($orderUpdateData);
                app(\App\Services\Inventory\OrderReservationService::class)->commit($lockedOrder);
                app(\App\Services\General\OrderService::class)->finalizePromotionUsageAfterPayment($lockedOrder);
                app(\App\Services\General\OrderService::class)->changeOrderStatus($lt->invoice_id, 'completed', null, false);
                $processedErrorSuccess = true;
            });
            if ($mismatchInError) {
                $sanitized = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? 'Amount or currency mismatch')), 500, '');
                try { if ($order) event(new PaymentFailed($order)); } catch (\Throwable $e) { report($e); }
                if ($errorCallbackType === 'mobile') {
                    return $this->apiResponse(PAYMENT_FAILED, 400, false, ['status'=>'failed','message'=>$sanitized,'payment_id'=>$paymentId]);
                }
                return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query(['status'=>'failed','message'=>$sanitized,'payment_id'=>$paymentId]));
            }
            if ($processedErrorSuccess) {
                try { event(new \App\Events\PaymentSucceeded($order ? $order->fresh() : null)); } catch (\Throwable $e) { report($e); }
            }
            if ($errorCallbackType === 'mobile') {
                return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
                    'status' => 'success',
                    'message' => __(PAYMENT_SUCCESSFUL),
                    'payment_id' => $paymentId,
                ]);
            }
            return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/success?' . http_build_query([
                'status' => 'success',
                'message' => __(PAYMENT_SUCCESSFUL),
                'payment_id' => $paymentId,
            ]));
        }

        $rawError = $result->errorMessage ?? __(PAYMENT_FAILED);
        $errorMessage = \Illuminate\Support\Str::limit(strip_tags((string) $rawError), 500, '');

        DB::transaction(function () use ($transaction, $paymentId, $verifiedInvoiceId, $result, $errorMessage) {
            $lockedTransaction = Transaction::where('gateway_transaction_id', $paymentId)
                ->orWhere('invoice_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (!$lockedTransaction) {
                $lockedTransaction = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)
                    ->orWhere('invoice_id', $verifiedInvoiceId)
                    ->lockForUpdate()
                    ->first();
            }

            if (!$lockedTransaction) {
                return;
            }
            if ($lockedTransaction->status !== 'pending') {
                return;
            }

            $existingResponse = is_array($lockedTransaction->gateway_response) ? $lockedTransaction->gateway_response : [];
            $callbackType = $existingResponse['_callback_type'] ?? null;
            $mergedResponse = is_array($result->rawResponse) ? $result->rawResponse : [];
            if ($callbackType) {
                $mergedResponse['_callback_type'] = $callbackType;
            }

            $lockedTransaction->update([
                'status' => 'failed',
                'gateway_response' => $mergedResponse,
                'error_message' => $errorMessage,
            ]);
        });

        try {
            if ($order) {
                event(new PaymentFailed($order));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        if ($errorCallbackType === 'mobile') {
            return $this->apiResponse(PAYMENT_FAILED, 400, false, [
                'status' => 'failed',
                'error' => $errorMessage,
                'payment_id' => $paymentId,
            ]);
        }

        return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query([
            'status' => 'failed',
            'error' => $errorMessage,
            'payment_id' => $paymentId,
        ]));
    }

    /**
     * Canonical Order-ID based invoice lookup for the customer.
     *
     * Resolves the order's latest Invoice (the same relation the customer
     * resource exposes as `invoice_id`). Ownership is enforced inside the
     * query so a foreign or missing order yields the same clean 404 without
     * leaking existence. A pending order has no invoice yet → 404.
     */
    public function invoiceByOrderId(Request $request, int $orderId): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->findOrFail($orderId);

        $invoice = $order->latestInvoice()->first();

        if (!$invoice) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            CustomerInvoiceResource::make($invoice)
        );
    }

    private function getCallbackType(?Transaction $transaction, Request $request): string
    {
        if ($transaction && is_array($transaction->gateway_response)) {
            $storedType = $transaction->gateway_response['_callback_type'] ?? null;
            if ($storedType && in_array($storedType, ['web', 'mobile'], true)) {
                return $storedType;
            }
        }
        $requested = $request->input('type', $request->query('type'));
        if (in_array($requested, ['web', 'mobile'], true)) {
            return $requested;
        }
        return 'web';
    }
}
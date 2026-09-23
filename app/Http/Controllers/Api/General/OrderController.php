<?php

namespace App\Http\Controllers\Api\General;

use App\DTOs\GatewayResult;
use App\Enums\FrontendResource;
use App\Exceptions\PaymentMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Invoice\CustomerInvoiceResource;
use App\Http\Resources\Order\OrderCollection;
use App\Http\Resources\Order\OrderResource;
use App\Models\Invoice;
use App\Services\General\CartInventoryService;
use App\Services\General\OrderService;
use App\Services\Inventory\OrderReservationService;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentCompletionOutcome;
use App\Services\Payment\PaymentCompletionService;
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
        private PaymentCompletionService $paymentCompletionService,
        private \App\Services\Payment\PaymentCurrencyResolver $currencyResolver,
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
            // Round to the order currency exponent so 3dp totals (KWD/...) reach
            // the gateway intact instead of being truncated to 2dp here.
            $orderPrice = \App\Services\Payment\CurrencyPrecision::roundForCurrency(
                (float) $order->total_price,
                $this->currencyResolver->forOrder($order)
            );
            if ($orderPrice <= 0) {
                // D-05: zero-value online orders complete WITHOUT the gateway.
                // No invoice, no redirect, no provider call — a paid zero-amount
                // transaction plus the canonical completed transition.
                return $this->completeZeroValueOnlineOrder($request, $order, $gateway);
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

    /**
     * D-05 zero-value policy: an online order totaling <= 0 completes
     * locally. The gateway is never called (there is nothing to collect):
     * its enabled/configured state is therefore irrelevant here (explicit
     * gateway-bypass exemption — no invoice, no redirect, no provider call).
     * The order currency must still be one the gateway claims to support, so
     * a zero-value order cannot complete in a currency no provider would
     * settle. Canonical completion runs through changeOrderStatus — the same
     * path as manual mark-paid — so inventory, coupons, promotions, invoice,
     * and the PaymentSucceeded lifecycle behave identically.
     */
    private function completeZeroValueOnlineOrder(Request $request, Order $order, string $gateway): JsonResponse
    {
        try {
            $zeroAdapter = $this->paymentGatewayFactory->make($gateway);
        } catch (\App\Exceptions\UnsupportedGatewayException $e) {
            return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 422, false);
        }

        $zeroCurrency = $this->currencyResolver->forOrder($order);

        if (!$zeroAdapter->supportsCurrency($zeroCurrency)) {
            return $this->apiResponse(
                __('message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED', ['currency' => $zeroCurrency]),
                422,
                false
            );
        }

        try {
            DB::transaction(function () use ($request, $order, $gateway) {
                $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                $lockedOrder->transactions()->create([
                    'user_id' => $request->user()->id,
                    'payment_method' => 'online',
                    'status' => 'paid',
                    'amount' => 0,
                    'currency' => $this->currencyResolver->forOrder($lockedOrder),
                    'paid_at' => now(),
                    // No gateway column exists on transactions; the provider
                    // name rides along as technical metadata next to the
                    // zero-value marker (never PII).
                    'gateway_response' => ['zero_value' => true, 'gateway' => $gateway],
                ]);

                // Canonical completion (emits PaymentSucceeded on success).
                // Coupon refusal throws and rolls everything back (fail-closed).
                // Authority exemption: the requester is the customer, but the
                // payable amount is zero, so no payment permission applies.
                $this->orderService->changeOrderStatus(null, 'completed', $lockedOrder->id, true, null, null, false);
            });
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
            'order_id' => $order->id,
        ]);
    }

    public function markCodAsPaid(int $orderId, Request $request): JsonResponse
    {
        $order = Order::query()->findOrFail($orderId);

        try {
            $this->orderService->markCodAsPaid($order, $this->manualPaidReason($request));
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true);
    }

    public function markCashierPaid(int $orderId, Request $request): JsonResponse
    {
        $order = Order::query()->findOrFail($orderId);

        try {
            $this->orderService->markCashierPaid($order, $this->manualPaidReason($request));
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(PAYMENT_SUCCESSFUL, 200, true);
    }

    /**
     * Optional manual-payment audit reason. Unvalidated free text is never
     * trusted: non-strings are dropped, tags stripped, length capped. The
     * service re-sanitizes before persisting to history metadata.
     *
     * NOTE (truncation): capped at 500 chars to fit the order_status_history
     * metadata JSON column and keep admin audit rows bounded — longer input
     * is cut, never rejected, so a verbose reason can't 422 a valid mark-paid.
     */
    private function manualPaidReason(Request $request): ?string
    {
        $reason = $request->input('reason');

        if (!is_string($reason) || trim($reason) === '') {
            return null;
        }

        return \Illuminate\Support\Str::limit(strip_tags($reason), 500, '');
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
            // Verify path goes through the factory (which delegates to the
            // registry): disabled gateways still verify existing payments,
            // and factory mocks keep intercepting in tests.
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

        // B4: gateway-verified but locally unknown payment. Fail SAFE — never
        // present a success UI for an order that does not exist locally.
        if (!$order) {
            \Illuminate\Support\Facades\Log::warning('Payment callback for unknown order blocked', [
                'payment_id' => $paymentId,
                'gateway_verified' => $result->success,
            ]);
            $unknownMessage = __(PAYMENT_FAILED);
            if ($callbackType === 'mobile') {
                return $this->apiResponse(PAYMENT_FAILED, 400, false, [
                    'status' => 'failed',
                    'message' => $unknownMessage,
                    'payment_id' => $paymentId,
                ]);
            }

            return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query([
                'status' => 'failed',
                'message' => $unknownMessage,
                'payment_id' => $paymentId,
            ]));
        }

        // Canonical completion runs inside PaymentCompletionService under the
        // same row locks. This controller keeps: txn lookup, verify call,
        // unknown-order fail-safe, DB::transaction + locks, coupon-blocked
        // catch + token rotation, mismatch → failed marking, events, and the
        // mobile-vs-redirect responses.
        $processed = false;
        $mismatchHandled = false;
        $couponBlocked = null;

        try {
            DB::transaction(function () use ($paymentId, $verifiedInvoiceId, $result, &$processed, &$mismatchHandled) {
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

            try {
                $outcome = $this->paymentCompletionService->completeLocked(
                    $lockedTransaction,
                    $lockedOrder,
                    $result,
                    ['test_bypass' => $this->isTestGatewayBypassAllowed()],
                );
            } catch (PaymentMismatchException $e) {
                // Fail-closed marking inside the same lock: the service changed
                // nothing except the idempotency token, which is retained here
                // exactly as the legacy inline path did.
                \Log::warning('Payment completion mismatch - blocking order', [
                    'transaction_id' => $lockedTransaction->id,
                    'reason' => $e->reason,
                    'context' => $e->context,
                ]);
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

            // Processed → success event + success response below. Every other
            // outcome (idempotent replay, non-pending order, duplicate-hold,
            // unknown order) falls through silently with no event — identical
            // to the legacy early returns.
            if ($outcome === PaymentCompletionOutcome::Processed) {
                $processed = true;
            }
            });
        } catch (\App\Exceptions\CouponConsumptionException $e) {
            // M1: coupon consumption refused completion (fail-closed). The
            // callback transaction rolled back (order stays pending, no partial
            // usage). Record the failure visibly; reconciliation
            // (coupons:reconcile) surfaces the paid-at-gateway / pending-local
            // state for manual handling.
            $couponBlocked = $e;
        }

        if ($couponBlocked) {
            try {
                DB::transaction(function () use ($paymentId, $verifiedInvoiceId, $couponBlocked) {
                    $lt = Transaction::where('gateway_transaction_id', $paymentId)->orWhere('invoice_id', $paymentId)->lockForUpdate()->first();
                    if (!$lt) $lt = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)->orWhere('invoice_id', $verifiedInvoiceId)->lockForUpdate()->first();
                    if ($lt && $lt->status === 'pending') {
                        // M2: rotate the idempotency token (the rolled-back attempt
                        // never persisted one) and stamp the block reason, so a
                        // legitimate retry after ops intervention reprocesses
                        // instead of wedging on a stale token. Status stays failed
                        // so automatic gateway retries cannot complete payment.
                        $merged = is_array($lt->gateway_response) ? $lt->gateway_response : [];
                        $merged['_coupon_blocked_at'] = now()->toIso8601String();
                        $merged['_coupon_blocked_reason'] = \Illuminate\Support\Str::limit(strip_tags($couponBlocked->getMessage()), 500, '');
                        $lt->update([
                            'status' => 'failed',
                            'idempotency_key' => null,
                            'gateway_response' => $merged,
                            'error_message' => \Illuminate\Support\Str::limit(strip_tags($couponBlocked->getMessage()), 500, ''),
                        ]);
                    }
                });
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                event(new PaymentFailed($order->fresh()));
            } catch (\Throwable $e) {
                report($e);
            }

            $blockedMessage = \Illuminate\Support\Str::limit(strip_tags($couponBlocked->getMessage()), 500, '');
            if ($callbackType === 'mobile') {
                return $this->apiResponse(PAYMENT_FAILED, 400, false, [
                    'status' => 'failed',
                    'message' => $blockedMessage,
                    'payment_id' => $paymentId,
                ]);
            }

            return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query([
                'status' => 'failed',
                'message' => $blockedMessage,
                'payment_id' => $paymentId,
            ]));
        }

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
            // F-14: never dispatch PaymentSucceeded with null/invalid order.
            try {
                $fresh = $order ? $order->fresh() : null;
                if ($fresh) {
                    event(new PaymentSucceeded($fresh));
                } else {
                    \Illuminate\Support\Facades\Log::warning('PaymentSucceeded skipped: order missing in success-callback', ['payment_id' => $paymentId]);
                }
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
            // Verify path goes through the factory (which delegates to the
            // registry): disabled gateways still verify existing payments,
            // and factory mocks keep intercepting in tests.
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
            $mismatchInError = false;
            $processedErrorSuccess = false;
            $couponBlockedError = null;
            try {
            DB::transaction(function () use ($paymentId, $verifiedInvoiceId, $result, &$mismatchInError, &$processedErrorSuccess) {
                $lt = Transaction::where('gateway_transaction_id', $paymentId)->orWhere('invoice_id', $paymentId)->lockForUpdate()->first();
                if (!$lt) $lt = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)->orWhere('invoice_id', $verifiedInvoiceId)->lockForUpdate()->first();
                if (!$lt) return;
                $lockedOrder = $lt->order()->lockForUpdate()->first();
                try {
                    $outcome = $this->paymentCompletionService->completeLocked(
                        $lt,
                        $lockedOrder,
                        $result,
                        ['test_bypass' => $this->isTestGatewayBypassAllowed()],
                    );
                } catch (PaymentMismatchException $e) {
                    // F-09 parity: fail-closed marking inside the same lock.
                    \Log::warning('Payment completion mismatch in error-callback - blocking order', [
                        'transaction_id' => $lt->id,
                        'reason' => $e->reason,
                        'context' => $e->context,
                    ]);
                    $sanitized = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? 'Amount or currency mismatch')), 500, '');
                    $merged = is_array($result->rawResponse) ? $result->rawResponse : [];
                    if (isset($lt->gateway_response['_callback_type'])) $merged['_callback_type'] = $lt->gateway_response['_callback_type'];
                    $lt->update(['status'=>'failed','gateway_response'=>$merged,'error_message'=>$sanitized]);
                    $mismatchInError = true;
                    return;
                }
                // Only a fresh canonical completion flips the success flag;
                // replays, non-pending orders, duplicate-holds, and unknown
                // orders fall through with no second completion and no event.
                if ($outcome === PaymentCompletionOutcome::Processed) {
                    $processedErrorSuccess = true;
                }
            });
            } catch (\App\Exceptions\CouponConsumptionException $e) {
                // F-01: mirror success-callback handling. Coupon refused completion
                // (fail-closed): transaction rolled back, order stays pending.
                // Record failure visibly; reconciliation surfaces paid-at-gateway /
                // pending-local for manual handling. Never bubble as 500.
                $couponBlockedError = $e;
            }
            if ($couponBlockedError) {
                try {
                    DB::transaction(function () use ($paymentId, $verifiedInvoiceId, $couponBlockedError) {
                        $lt = Transaction::where('gateway_transaction_id', $paymentId)->orWhere('invoice_id', $paymentId)->lockForUpdate()->first();
                        if (!$lt) $lt = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)->orWhere('invoice_id', $verifiedInvoiceId)->lockForUpdate()->first();
                        if ($lt && $lt->status === 'pending') {
                            // M2 parity with success-callback: rotate token + stamp
                            // block reason so legitimate retry reprocesses.
                            $mergedErr = is_array($lt->gateway_response) ? $lt->gateway_response : [];
                            $mergedErr['_coupon_blocked_at'] = now()->toIso8601String();
                            $mergedErr['_coupon_blocked_reason'] = \Illuminate\Support\Str::limit(strip_tags($couponBlockedError->getMessage()), 500, '');
                            $lt->update([
                                'status' => 'failed',
                                'idempotency_key' => null,
                                'gateway_response' => $mergedErr,
                                'error_message' => \Illuminate\Support\Str::limit(strip_tags($couponBlockedError->getMessage()), 500, ''),
                            ]);
                        }
                    });
                } catch (\Throwable $e) {
                    report($e);
                }
                try {
                    if ($order) {
                        event(new PaymentFailed($order->fresh()));
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
                $blockedMessage = \Illuminate\Support\Str::limit(strip_tags($couponBlockedError->getMessage()), 500, '');
                if ($errorCallbackType === 'mobile') {
                    return $this->apiResponse(PAYMENT_FAILED, 400, false, ['status' => 'failed', 'message' => $blockedMessage, 'payment_id' => $paymentId]);
                }

                return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query(['status' => 'failed', 'message' => $blockedMessage, 'payment_id' => $paymentId]));
            }
            if ($mismatchInError) {
                $sanitized = \Illuminate\Support\Str::limit(strip_tags((string) ($result->errorMessage ?? 'Amount or currency mismatch')), 500, '');
                try { if ($order) event(new PaymentFailed($order)); } catch (\Throwable $e) { report($e); }
                if ($errorCallbackType === 'mobile') {
                    return $this->apiResponse(PAYMENT_FAILED, 400, false, ['status'=>'failed','message'=>$sanitized,'payment_id'=>$paymentId]);
                }
                return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query(['status'=>'failed','message'=>$sanitized,'payment_id'=>$paymentId]));
            }
            if ($processedErrorSuccess) {
                // F-14: PaymentSucceeded contract never receives null. Only dispatch
                // when the authoritative order exists; otherwise skip (logged).
                try {
                    $fresh = $order ? $order->fresh() : null;
                    if ($fresh) {
                        event(new \App\Events\PaymentSucceeded($fresh));
                    } else {
                        \Illuminate\Support\Facades\Log::warning('PaymentSucceeded skipped: order missing in error-callback', ['payment_id' => $paymentId]);
                    }
                } catch (\Throwable $e) { report($e); }
            }
            // B4 parity: gateway-verified but locally unknown payment. Fail SAFE —
            // never present a success UI for an order that does not exist locally.
            if (!$order) {
                \Illuminate\Support\Facades\Log::warning('Payment error-callback for unknown order blocked', [
                    'payment_id' => $paymentId,
                    'gateway_verified' => $result->success,
                ]);
                $unknownMessage = __(PAYMENT_FAILED);
                if ($errorCallbackType === 'mobile') {
                    return $this->apiResponse(PAYMENT_FAILED, 400, false, [
                        'status' => 'failed',
                        'message' => $unknownMessage,
                        'payment_id' => $paymentId,
                    ]);
                }
                return redirect(config('app.app_url_frontend') . '/' . app()->getLocale() . '/payment/failed?' . http_build_query([
                    'status' => 'failed',
                    'message' => $unknownMessage,
                    'payment_id' => $paymentId,
                ]));
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

    /**
     * B5: test-gateway amount/currency bypass gate. The canonical base URL
     * lives in the `payment` tree (config/payment.php
     * gateways.myfatoorah.base_url, as the registry documents); the legacy
     * `services` tree (config/services.php myfatoorah.base_url) is read as a
     * fallback. When both are set, BOTH must point at the apitest host — a
     * split-brain config (one live, one test) fails closed. Bypass
     * additionally requires the explicit `payment.test_gateway_bypass_enabled`
     * flag AND a local/testing environment. Staging/QA pointing at apitest
     * therefore still blocks mismatches.
     */
    private function isTestGatewayBypassAllowed(): bool
    {
        $paymentUrl = trim((string) config('payment.gateways.myfatoorah.base_url', ''));
        $servicesUrl = trim((string) config('services.myfatoorah.base_url', ''));

        $candidates = array_values(array_filter([$paymentUrl, $servicesUrl], fn (string $url): bool => $url !== ''));

        // No URL configured anywhere → never bypass. Otherwise every
        // configured URL must indicate the test host.
        if ($candidates === []) {
            return false;
        }

        foreach ($candidates as $url) {
            if (!str_contains($url, 'apitest')) {
                return false;
            }
        }

        if (!config('payment.test_gateway_bypass_enabled', false)) {
            return false;
        }
        return app()->environment('local', 'testing');
    }
}
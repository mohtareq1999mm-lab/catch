<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Models\GuestIdentity;
use App\Services\Payment\CurrencyPrecision;
use App\Services\Payment\PaymentAttemptLifecycleService;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentCurrencyResolver;
use App\Services\Payment\ZeroValueOrderCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Traits\ApiResponse;

/**
 * Guest payment initiation.
 *
 * Reuses the canonical payment architecture (PaymentCheckoutHandler +
 * PaymentAttemptLifecycleService + gateway adapters). It is NOT a second
 * payment implementation: guest initiation opts into the pre-dispatch claim /
 * SC-1 guard already owned by the lifecycle service.
 */
class GuestPaymentService
{
    use ApiResponse;

    public function __construct(
        private PaymentCheckoutHandler $checkoutHandler,
        private PaymentAttemptLifecycleService $attemptLifecycle,
        private PaymentCurrencyResolver $currencyResolver,
        private ZeroValueOrderCompletionService $zeroValueCompletion,
        private GuestIdempotencyService $idempotency,
    ) {
    }

    public function initiate(Request $request, GuestIdentity $guest, Order $order): JsonResponse
    {
        // Ownership first: a foreign / unknown order is indistinguishable
        // (both 404) — no order existence oracle.
        if ((int) $order->guest_id !== (int) $guest->id) {
            return $this->apiResponse(__('guest_checkout.order_not_found'), 404, false);
        }

        $requestedMethod = $request->input('payment_method', $order->payment_method ?: 'online');

        // Guest checkout supports Online + COD only. Cashier/POS stays disabled.
        if (!in_array($requestedMethod, ['online', 'cod'], true)) {
            return $this->apiResponse(__('guest_checkout.payment_method_not_supported'), 422, false);
        }

        if ($order->payment_method !== null && $order->payment_method !== $requestedMethod) {
            // payment_method is bound at order creation (COD commits inventory
            // at creation); switching later would corrupt the lifecycle.
            return $this->apiResponse(__('guest_checkout.payment_method_mismatch'), 422, false);
        }

        // COD placement legitimately advances the order to `processing` as
        // part of automatic fulfillment release; the COD transaction still
        // must be created for reconciliation/mark-paid. Online initiation
        // stays strictly pending (the attempt guard is pending-only).
        $allowedStatuses = $requestedMethod === 'cod'
            ? [Order::ORDER_STATUS_PENDING, Order::ORDER_STATUS_PROCESSING]
            : [Order::ORDER_STATUS_PENDING];

        if (!in_array($order->status, $allowedStatuses, true)) {
            return $this->apiResponse(__('guest_checkout.order_not_payable'), 409, false);
        }

        if ($order->payment_status === Order::PAYMENT_STATUS_SUCCESS) {
            return $this->apiResponse(__('guest_checkout.order_already_paid'), 409, false);
        }

        $idempotencyKey = $this->idempotencyKey($request);
        $claim = null;

        if ($idempotencyKey !== null) {
            $claimResult = $this->idempotency->claim(
                $guest->id,
                'payment:' . $order->id,
                $idempotencyKey,
                $request->all()
            );

            if ($claimResult['replay']) {
                $stored = $claimResult['response'] ?? [];

                return $this->apiResponse(
                    $stored['message'] ?? CHECKOUT_SUCCESSFUL,
                    (int) ($stored['status'] ?? 200),
                    true,
                    $stored['data'] ?? []
                );
            }

            $claim = $claimResult['claim'];
        }

        try {
            $response = $requestedMethod === 'cod'
                ? $this->initiateCod($request, $order)
                : $this->initiateOnline($request, $order);

            $payload = $response->getData(true);

            if ($claim) {
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $this->idempotency->complete($claim, [
                        'status' => $response->getStatusCode(),
                        'message' => $payload['message'] ?? CHECKOUT_SUCCESSFUL,
                        'data' => $payload['data'] ?? [],
                    ], $order->id);
                } else {
                    $this->idempotency->fail($claim, (string) ($payload['message'] ?? 'payment_init_failed'));
                }
            }

            return $response;
        } catch (\Throwable $e) {
            if ($claim) {
                $this->idempotency->fail($claim, $e->getMessage());
            }

            throw $e;
        }
    }

    private function initiateOnline(Request $request, Order $order): JsonResponse
    {
        $gateway = $request->input('gateway')
            ?: $order->payment_gateway
            ?: config('payment.default_gateway', 'myfatoorah');

        // SC-1 pre-check BEFORE mutating order metadata: an earlier attempt
        // may still collect, so nothing about this order should change.
        if ($this->attemptLifecycle->hasUnresolvedAttempt($order->id)) {
            return $this->apiResponse(__('guest_checkout.payment_in_progress'), 409, false);
        }

        if ($order->payment_gateway !== $gateway) {
            // Gateway selection is non-financial (no attempt exists yet);
            // persist it so callbacks/webhooks resolve the same adapter.
            Order::query()->whereKey($order->id)->update([
                'payment_gateway' => $gateway,
                'updated_at' => now(),
            ]);
            $order->setAttribute('payment_gateway', $gateway);
        }

        $amount = CurrencyPrecision::roundForCurrency(
            (float) $order->total_price,
            $this->currencyResolver->forOrder($order)
        );

        if ($amount <= 0) {
            // D-05: zero-value orders complete locally through the canonical
            // writer; no gateway session is created.
            try {
                $this->zeroValueCompletion->assertSupported($gateway, $order);
            } catch (\App\Exceptions\UnsupportedGatewayException $e) {
                return $this->apiResponse(PAYMENT_GATEWAY_UNAVAILABLE, 422, false);
            } catch (\RuntimeException $e) {
                return $this->apiResponse($e->getMessage(), 422, false);
            }

            $this->zeroValueCompletion->complete($order, $gateway, null);

            return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
                'order_id' => $order->id,
                'payment_method' => 'online',
                'zero_value' => true,
            ]);
        }

        $response = $this->checkoutHandler->handleOnlinePayment(
            $request,
            $order,
            $amount,
            $gateway,
            null,
            null,
            true // guest: enforce SC-1 + pre-dispatch claim
        );

        $payload = $response->getData(true);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $payload['data'] = array_merge($payload['data'] ?? [], [
                'order_id' => $order->id,
                'payment_method' => 'online',
                'gateway' => $gateway,
            ]);

            return response()->json($payload, $response->getStatusCode());
        }

        return $response;
    }

    private function initiateCod(Request $request, Order $order): JsonResponse
    {
        // An unresolved online attempt could still collect money — never add
        // a COD row on top of it.
        if ($this->attemptLifecycle->hasUnresolvedAttempt($order->id)) {
            return $this->apiResponse(__('guest_checkout.payment_in_progress'), 409, false);
        }

        $existing = Transaction::query()
            ->where('order_id', $order->id)
            ->where('payment_method', 'cod')
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return $this->apiResponse(__('checkout.cod_success'), 200, true, [
                'order_id' => $order->id,
                'payment_method' => 'cod',
                'idempotent_replay' => true,
            ]);
        }

        $response = $this->checkoutHandler->handleCodPayment($request, $order);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $payload = $response->getData(true);
            $payload['data'] = array_merge($payload['data'] ?? [], [
                'order_id' => $order->id,
                'payment_method' => 'cod',
            ]);

            return response()->json($payload, $response->getStatusCode());
        }

        return $response;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key')
            ?? $request->input('idempotency_key');

        if (!is_string($key) || trim($key) === '') {
            return null;
        }

        $key = trim($key);

        if (strlen($key) > 191) {
            throw new GuestCheckoutConflictException(__('guest_checkout.invalid_idempotency_key'), 422);
        }

        return $key;
    }
}

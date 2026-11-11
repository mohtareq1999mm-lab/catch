<?php

namespace App\Services\Payment;

use App\Exceptions\UnsupportedGatewayException;
use App\Services\General\OrderService;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;

/**
 * D-05 zero-value completion, extracted so the authenticated checkout and the
 * guest payment path share ONE implementation (no duplicated completion
 * authority). The gateway is never called: there is nothing to collect.
 *
 * Canonical completion still runs through OrderService::changeOrderStatus, so
 * inventory, coupons, promotions, invoice and PaymentSucceeded behave exactly
 * like any other completion.
 */
class ZeroValueOrderCompletionService
{
    public function __construct(
        private PaymentGatewayFactory $paymentGatewayFactory,
        private PaymentCurrencyResolver $currencyResolver,
        private OrderService $orderService,
    ) {
    }

    /**
     * Fail-closed currency gate: a zero-value order must not complete in a
     * currency no provider would settle.
     *
     * @throws UnsupportedGatewayException
     */
    public function assertSupported(string $gateway, Order $order): void
    {
        $adapter = $this->paymentGatewayFactory->make($gateway);
        $currency = $this->currencyResolver->forOrder($order);

        if (!$adapter->supportsCurrency($currency)) {
            throw new \RuntimeException(__(
                'message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED',
                ['currency' => $currency]
            ));
        }
    }

    /**
     * @param  int|null  $userId  NULL for guest orders (approved nullable FK).
     */
    public function complete(Order $order, string $gateway, ?int $userId): void
    {
        DB::transaction(function () use ($order, $gateway, $userId) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Concurrency-safe no-op: another request already completed (or
            // cancelled) this order. Never write a second paid transaction.
            if (
                $lockedOrder->status !== Order::ORDER_STATUS_PENDING
                || $lockedOrder->payment_status === Order::PAYMENT_STATUS_SUCCESS
            ) {
                return;
            }

            $lockedOrder->transactions()->create([
                'user_id' => $userId,
                'payment_method' => 'online',
                'status' => 'paid',
                'amount' => 0,
                'currency' => $this->currencyResolver->forOrder($lockedOrder),
                'paid_at' => now(),
                // No gateway column exists on transactions; the provider name
                // rides along as technical metadata next to the zero-value
                // marker (never PII).
                'gateway_response' => ['zero_value' => true, 'gateway' => $gateway],
            ]);

            // Canonical completion (emits PaymentSucceeded on success).
            // Coupon refusal throws and rolls everything back (fail-closed).
            // Authority exemption: the requester is the customer, but the
            // payable amount is zero, so no payment permission applies.
            $this->orderService->changeOrderStatus(null, 'completed', $lockedOrder->id, true, null, null, false);
        });
    }
}

<?php

namespace App\Services\Payment;

use App\Exceptions\UnsupportedGatewayException;
use App\Models\User;
use App\Services\Order\OrderTransitionService;
use App\ValueObjects\TransitionActor;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;

/**
 * D-05 zero-value completion, extracted so the authenticated checkout and the
 * guest payment path share ONE implementation (no duplicated completion
 * authority). The gateway is never called: there is nothing to collect.
 *
 * Canonical completion still runs through the OrderTransitionService complete
 * intent, so inventory, coupons, promotions, invoice and PaymentSucceeded behave
 * like any other completion.
 */
class ZeroValueOrderCompletionService
{
    public function __construct(
        private PaymentGatewayFactory $paymentGatewayFactory,
        private PaymentCurrencyResolver $currencyResolver,
        private OrderTransitionService $orderTransitions,
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
            // Authority exemption: the zero-total completion is exempt from the
            // F-1 payer gate inside execute(); the buyer is passed as the
            // actor for the audit trail.
            $this->orderTransitions->completeZeroValue($lockedOrder, TransitionActor::user(User::query()->find($userId)));
        });
    }
}

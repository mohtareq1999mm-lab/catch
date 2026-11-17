<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Services\Payment\PaymentAttemptLifecycleService;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Otp\Gateways\OtpGateway;

/**
 * Step 1 of the two-step guest cancellation (design record D1/D3/D9):
 * request the Cancellation OTP for a token-resolvable, still-cancellable
 * order and dispatch it to the phone recorded on the order through the
 * active OTP gateway.
 *
 * The request step reuses the cancellation authority's policy checks with
 * the SAME message keys and statuses, but it is read-only: it NEVER
 * consumes the single-use token. Provider dispatch happens OUTSIDE the
 * guard transaction so provider I/O never holds the row lock.
 */
class GuestCancellationOtpService
{
    public function __construct(
        private GuestOrderTokenService $tokenService,
        private PaymentAttemptLifecycleService $attemptLifecycle,
        private OtpGateway $otpGateway,
    ) {
    }

    /**
     * @return array{otp_id: string, provider: string}
     *
     * @throws GuestCheckoutConflictException
     */
    public function request(string $rawToken): array
    {
        $order = $this->resolveCancellableOrder($rawToken);

        // D5: guests have no verified phone at capture; an order recorded
        // without one cannot receive a Cancellation OTP. No email channel.
        $phone = $order->user_phone;

        if (!is_string($phone) || trim($phone) === '') {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_no_phone'), 422);
        }

        $result = $this->otpGateway->startVerification($phone);

        if (!$result->isValid()) {
            // Upstream provider refused to start the verification: the client
            // did nothing wrong, so the conflict stays a 5xx-class outcome.
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_send_failed'), 502);
        }

        return [
            'otp_id' => $result->getId(),
            'provider' => (string) config('auth.active_otp_gateway'),
        ];
    }

    /**
     * Hash lookup under a row lock with the cancellation policy checks —
     * the request-side half of GuestCancellationService::cancel(): the SAME
     * checks, the SAME message keys and statuses, but no used-mark write.
     */
    private function resolveCancellableOrder(string $rawToken): Order
    {
        $hash = $this->tokenService->hash($rawToken);

        return DB::transaction(function () use ($hash) {
            /** @var Order|null $order */
            $order = Order::query()
                ->where('guest_cancellation_token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
            }

            if ($order->guest_cancellation_token_used_at !== null) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_used'), 422);
            }

            $expiresAt = $order->guest_cancellation_token_expires_at;

            if ($expiresAt !== null && $expiresAt->isPast()) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_expired'), 422);
            }

            if (!$this->tokenService->isOrderCancellable($order)) {
                throw new GuestCheckoutConflictException(__('checkout.order_cancel_not_allowed'), 422);
            }

            // T-03 / R-POL-1: an unresolved attempt could still collect money.
            // Refused BEFORE any OTP dispatch (design §4: 409 before any SMS).
            if ($this->attemptLifecycle->hasUnresolvedAttempt($order->id)) {
                throw new GuestCheckoutConflictException(__('checkout.order_payment_in_progress'), 409);
            }

            return $order;
        });
    }
}

<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Services\General\OrderService;
use App\Services\Payment\PaymentAttemptLifecycleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Otp\Gateways\LocalGateway;
use Marvel\Otp\Gateways\OtpGateway;

/**
 * Guest cancellation via a single-use secure link with OTP verification.
 *
 * The token is the credential: hash lookup under a row lock, policy check,
 * OTP verification, atomic used-mark and the CANONICAL status writer (changeOrderStatus).
 * No second cancellation authority exists.
 */
class GuestCancellationService
{
    public function __construct(
        private GuestOrderTokenService $tokenService,
        private OrderService $orderService,
        private PaymentAttemptLifecycleService $attemptLifecycle,
    ) {
    }

    public function isValidTokenFormat(?string $token): bool
    {
        return $this->tokenService->isValidTokenFormat($token);
    }

    /**
     * Initiate cancellation by validating the token and dispatching an OTP.
     * Does NOT consume the token and does NOT cancel the order.
     *
     * @throws GuestCheckoutConflictException
     */
    public function requestOtp(string $rawToken, ?string $inputPhone = null): array
    {
        if (!$this->tokenService->isValidTokenFormat($rawToken)) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        $hash = $this->tokenService->hash($rawToken);

        $order = Order::query()
            ->where('guest_cancellation_token_hash', $hash)
            ->first();

        if (!$order) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        if ($order->status === Order::ORDER_STATUS_CANCELLED) {
            throw new GuestCheckoutConflictException(__('guest_checkout.order_already_cancelled'), 422);
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

        // T-03 / R-POL-1: an unresolved attempt could still collect money. Fail before dispatching SMS.
        if ($this->attemptLifecycle->hasUnresolvedAttempt($order->id)) {
            throw new GuestCheckoutConflictException(__('checkout.order_payment_in_progress'), 409);
        }

        if (empty($order->user_phone)) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_no_phone'), 422);
        }

        if (!empty($inputPhone)) {
            $normalizedInput = preg_replace('/\D/', '', (string) $inputPhone);
            $normalizedOrder = preg_replace('/\D/', '', (string) $order->user_phone);
            if (!str_ends_with($normalizedOrder, $normalizedInput) && !str_ends_with($normalizedInput, $normalizedOrder)) {
                throw new GuestCheckoutConflictException(__('guest_checkout.phone_mismatch'), 422);
            }
        }

        if ($this->isBypassActive()) {
            return [
                'otp_id' => 'local-verification-id',
                'provider' => 'local',
                'phone' => $this->maskPhone($order->user_phone),
            ];
        }

        try {
            $gateway = $this->getOtpGateway();
            $sendResult = $gateway->startVerification($order->user_phone);

            if (!$sendResult->isValid()) {
                throw new \RuntimeException(__('guest_checkout.otp_send_failed'));
            }

            return [
                'otp_id' => $sendResult->getId(),
                'provider' => config('auth.active_otp_gateway'),
                'phone' => $this->maskPhone($order->user_phone),
            ];
        } catch (\Throwable $e) {
            if ($e instanceof GuestCheckoutConflictException) {
                throw $e;
            }
            Log::error('Guest cancellation OTP dispatch failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(__('guest_checkout.otp_send_failed'), 0, $e);
        }
    }

    /**
     * Confirm cancellation with OTP code.
     *
     * @throws GuestCheckoutConflictException
     */
    public function cancel(string $rawToken, ?string $code = null, ?string $otpId = null): Order
    {
        if (!$this->tokenService->isValidTokenFormat($rawToken)) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        $hash = $this->tokenService->hash($rawToken);

        // Lockout guard: 5 failed attempts per token hash -> 429
        $lockoutKey = 'guest_cancel_otp_lockout:' . $hash;
        if ($this->isLockedOut($lockoutKey)) {
            throw new GuestCheckoutConflictException(__('message.too_many_requests'), 429);
        }

        $order = DB::transaction(function () use ($hash, $code, $otpId, $lockoutKey) {
            /** @var Order|null $order */
            $order = Order::query()
                ->where('guest_cancellation_token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
            }

            if ($order->status === Order::ORDER_STATUS_CANCELLED) {
                throw new GuestCheckoutConflictException(__('guest_checkout.order_already_cancelled'), 422);
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
            if ($this->attemptLifecycle->hasUnresolvedAttempt($order->id)) {
                throw new GuestCheckoutConflictException(__('checkout.order_payment_in_progress'), 409);
            }

            // Verify OTP code
            if (empty($code)) {
                throw new GuestCheckoutConflictException(__('guest_checkout.invalid_otp'), 422);
            }

            if ($this->isBypassCodeAccepted($code)) {
                // Bypass code accepted (local / testing)
            } else {
                $gateway = $this->getOtpGateway();
                $verifyResult = $gateway->checkVerification($otpId, $code, (string) $order->user_phone);

                if (!$verifyResult->isValid()) {
                    $this->recordFailure($lockoutKey);
                    throw new GuestCheckoutConflictException(__('guest_checkout.invalid_otp'), 422);
                }
            }

            // Clear lockout on success
            $this->clearLockout($lockoutKey);

            // Atomic single-use claim. If a concurrent request already used
            // the link, this update matches zero rows and the transaction
            // aborts before any status change.
            $used = Order::query()
                ->whereKey($order->id)
                ->whereNull('guest_cancellation_token_used_at')
                ->update(['guest_cancellation_token_used_at' => now()]);

            if ($used !== 1) {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_used'), 422);
            }

            try {
                $cancelled = $this->orderService->changeOrderStatus(
                    null,
                    'cancelled',
                    $order->id,
                    auditReason: 'Guest single-use cancellation link',
                    auditContext: ['trigger' => 'guest_cancellation_link'],
                    // ORD-1: never decrement promotion usage for an order
                    // whose usage was never incremented (unpaid cancellation).
                    skipPromotionDecrement: $order->payment_status !== Order::PAYMENT_STATUS_SUCCESS,
                );
            } catch (\Throwable $e) {
                // Roll back the used-mark so a transient failure does not burn
                // the customer's only cancellation link. The status writer is
                // transactional; nothing was committed.
                throw $e;
            }

            if (!$cancelled) {
                throw new \RuntimeException(ERROR_CANCELLING_ORDER);
            }

            return $order->refresh();
        });

        return $order;
    }

    /**
     * Check whether dev/local bypass is active. Hard-blocked in production.
     */
    public function isBypassActive(): bool
    {
        $isProduction = function_exists('app') && method_exists(app(), 'environment')
            ? app()->environment('production')
            : (env('APP_ENV') === 'production');

        if ($isProduction) {
            return false;
        }

        return (bool) config('auth.local_otp_enabled', false);
    }

    /**
     * Check whether the provided code matches the bypass code.
     */
    public function isBypassCodeAccepted(?string $code): bool
    {
        if (empty($code) || !$this->isBypassActive()) {
            return false;
        }

        $masterOtp = (string) config('auth.master_otp', '');
        $expected = $masterOtp !== ''
            ? $masterOtp
            : (string) config('auth.local_otp_code', '123456');

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $code);
    }

    protected function getOtpGateway(): OtpGateway
    {
        $gateway = config('auth.active_otp_gateway');
        $gateWayClass = "Marvel\\Otp\\Gateways\\" . ucfirst($gateway) . 'Gateway';
        try {
            return new OtpGateway(new $gateWayClass());
        } catch (\Throwable $e) {
            Log::warning('OTP gateway unavailable, falling back to LocalGateway: ' . $e->getMessage());
            return new OtpGateway(new LocalGateway());
        }
    }

    private function isLockedOut(string $key): bool
    {
        $failures = (int) Cache::get($key, 0);
        return $failures >= 5;
    }

    private function recordFailure(string $key): void
    {
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, now()->addMinutes(15));
    }

    private function clearLockout(string $key): void
    {
        Cache::forget($key);
    }

    private function maskPhone(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }
        $len = strlen($phone);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return substr($phone, 0, 3) . str_repeat('*', max(1, $len - 7)) . substr($phone, -4);
    }
}

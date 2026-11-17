<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Services\Notifications\SMSService;
use App\Services\Payment\PaymentAttemptLifecycleService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Order;
use Marvel\Otp\Gateways\OtpGateway;
use Marvel\Otp\StaticBypass;

/**
 * Cancellation possession proof (#81, spec #73).
 *
 * The single-use cancellation link is a bearer credential: whoever holds it
 * could cancel the order. This service adds the second factor — an OTP sent to
 * the phone on the order — and owns every OTP surface for that link:
 *
 *  - Issuance goes through the configured OtpGateway (the app-level binding
 *    falls back to the committed LocalGateway when a provider cannot be
 *    constructed; ADR 0003). Codes are provider-managed: never generated,
 *    stored or logged here. The cache state carries only the provider
 *    verification id, keyed by the token HASH.
 *  - Verification checks the ONE universal StaticBypass first (ADR 0003),
 *    then the provider. Failure counts are per token hash and survive both
 *    fresh code requests and successful requests for other tokens.
 *  - Five failed attempts lock that token hash for 24 hours (fail closed).
 *    The raw token and the code never appear in a cache key, a response or a
 *    log line.
 */
class GuestCancellationOtpService
{
    private const STATE_PREFIX = 'guest_cancel_otp:state:';
    private const ATTEMPTS_PREFIX = 'guest_cancel_otp:attempts:';
    private const LOCK_PREFIX = 'guest_cancel_otp:lock:';

    public function __construct(
        private GuestOrderTokenService $tokens,
        private PaymentAttemptLifecycleService $attempts,
        private SMSService $sms,
        private StaticBypass $staticBypass,
        private OtpGateway $gateway,
        private RateLimiter $limiter,
    ) {
    }

    /**
     * Issue a code for a live cancellation link.
     *
     * @return array{expires_in: int}
     *
     * @throws GuestCheckoutConflictException
     */
    public function request(string $rawToken): array
    {
        $order = $this->tokens->findByCancellationToken($rawToken);

        if (!$order) {
            // Unknown, used and expired links answer identically: no oracle,
            // and no SMS budget is ever spent on a dead link.
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_link_invalid'), 404);
        }

        if (!$this->tokens->isOrderCancellable($order)) {
            throw new GuestCheckoutConflictException(__('checkout.order_cancel_not_allowed'), 422);
        }

        // T-03 / R-POL-1: never issue a cancellation credential while money
        // may still be collected.
        if ($this->attempts->hasUnresolvedAttempt($order->id)) {
            throw new GuestCheckoutConflictException(__('checkout.order_payment_in_progress'), 409);
        }

        $hash = $this->tokens->hash($rawToken);
        $this->assertNotLocked($hash);

        $result = $this->gateway->startVerification($this->phone($order));

        if (!$result?->isValid()) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_failed'), 422);
        }

        Cache::put($this->stateKey($hash), [
            'verification_id' => $result->getId(),
        ], $this->codeTtl());

        return ['expires_in' => $this->codeTtlSeconds()];
    }

    /**
     * Verify a submitted code against the link's possession proof.
     *
     * @return string the proof recorded in the audit trail: `static` for the
     *                ADR 0003 bypass, `otp` for a provider verification
     *
     * @throws GuestCheckoutConflictException
     */
    public function verify(string $rawToken, string $code, Order $order): string
    {
        $hash = $this->tokens->hash($rawToken);
        $this->assertNotLocked($hash);

        // ADR 0003: the ONE universal bypass check, first. It is hard-gated
        // to non-production and only enabled by the Local OTP switch, so a
        // master value alone can never unlock a cancellation.
        if ($this->staticBypass->accepts($code, 'guest_cancellation_order:' . $order->getKey())) {
            $this->clearFailures($hash);
            Cache::forget($this->stateKey($hash));

            return 'static';
        }

        $state = Cache::get($this->stateKey($hash));

        if (!is_array($state) || empty($state['verification_id'])) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_expired'), 422);
        }

        try {
            $result = $this->gateway->checkVerification(
                (string) $state['verification_id'],
                (string) $code,
                $this->phone($order)
            );
        } catch (\Throwable $e) {
            // A provider outage is not a guess: fail closed without burning
            // one of the five attempts, and never cancel.
            report($e);

            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_failed'), 422);
        }

        if ($result->isValid()) {
            $this->clearFailures($hash);
            Cache::forget($this->stateKey($hash));

            return 'otp';
        }

        $this->recordFailure($hash);

        throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_invalid'), 422);
    }

    /**
     * @throws GuestCheckoutConflictException
     */
    private function recordFailure(string $hash): void
    {
        $key = $this->attemptsKey($hash);
        $this->limiter->hit($key, $this->lockoutSeconds());

        if ($this->limiter->attempts($key) >= $this->maxAttempts()) {
            Cache::put($this->lockKey($hash), true, $this->lockoutTtl());
            $this->limiter->clear($key);

            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_locked'), 429);
        }
    }

    /**
     * @throws GuestCheckoutConflictException
     */
    private function assertNotLocked(string $hash): void
    {
        if (
            Cache::has($this->lockKey($hash))
            || $this->limiter->tooManyAttempts($this->attemptsKey($hash), $this->maxAttempts())
        ) {
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_locked'), 429);
        }
    }

    private function clearFailures(string $hash): void
    {
        $this->limiter->clear($this->attemptsKey($hash));
        Cache::forget($this->lockKey($hash));
    }

    /**
     * @throws GuestCheckoutConflictException
     */
    private function phone(Order $order): string
    {
        $phone = trim((string) $order->user_phone);

        if ($phone === '') {
            // Guest checkout always writes user_phone; this guard covers
            // legacy rows instead of silently sending nowhere.
            throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_unavailable'), 422);
        }

        return $this->sms->formatPhone($phone);
    }

    private function stateKey(string $hash): string
    {
        return self::STATE_PREFIX . $hash;
    }

    private function attemptsKey(string $hash): string
    {
        return self::ATTEMPTS_PREFIX . $hash;
    }

    private function lockKey(string $hash): string
    {
        return self::LOCK_PREFIX . $hash;
    }

    private function codeTtlSeconds(): int
    {
        return max(1, (int) config('guest_checkout.cancellation_otp.code_ttl_minutes', 10)) * 60;
    }

    private function codeTtl(): \DateTimeInterface
    {
        return now()->addSeconds($this->codeTtlSeconds());
    }

    private function lockoutSeconds(): int
    {
        return max(1, (int) config('guest_checkout.cancellation_otp.lockout_hours', 24)) * 3600;
    }

    private function lockoutTtl(): \DateTimeInterface
    {
        return now()->addSeconds($this->lockoutSeconds());
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('guest_checkout.cancellation_otp.max_attempts', 5));
    }
}

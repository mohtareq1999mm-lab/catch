<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Otp\Gateways\OtpGateway;

/**
 * The OTP halves of the two-step guest cancellation (design D1):
 *
 * - request(): dispatch the Cancellation OTP for a token-resolvable,
 *   still-cancellable order (never consumes the single-use token).
 * - verify(): the confirm-side verification gate IN FRONT of the
 *   canonical cancellation authority. It NEVER consumes the token and
 *   NEVER fires cancellation side effects — on success the caller runs
 *   GuestCancellationService::cancel(), the sole cancellation authority.
 *
 * Both steps reuse the cancellation authority's policy checks with the
 * SAME message keys and statuses, but are read-only up to their own
 * side effect: provider dispatch happens OUTSIDE the guard transaction
 * so provider I/O never holds the row lock, and verification is
 * read-only (the used-mark stays owned by the canonical flow).
 */
class GuestCancellationOtpService
{
    public function __construct(
        private GuestCancellationService $cancellation,
        private OtpGateway $otpGateway,
        private StaticOtpBypassGate $bypassGate,
        private GuestCancellationOtpLockout $lockout,
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

        $result = $this->otpGateway->startVerification($order->user_phone);

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
     * Step 2 (D3/D4/D6/D8): verify the submitted code for a
     * token-resolvable, still-cancellable order.
     *
     * Order of checks (design §4): the D8 attempt budget is asserted
     * FIRST so an exhausted budget fails fast with 429; then the
     * read-only policy resolution — which never consumes an attempt —
     * and only then the code verification.
     *
     * @throws GuestCheckoutConflictException 429 locked-out, 422 on a
     *         wrong/refused code, otherwise the resolution statuses
     */
    public function verify(string $rawToken, string $otpId, string $code): void
    {
        // D8: fail fast on an exhausted attempt budget. The check runs
        // before any other work and consumes nothing new.
        $this->lockout->assertNotLocked($rawToken);

        // Policy checks (read-only) run BEFORE any code verification and
        // do NOT consume an attempt: invalid/used/expired/non-cancellable
        // and the unresolved-attempt 409 land here, with the SAME keys and
        // statuses as the request step.
        $order = $this->resolveCancellableOrder($rawToken);

        // D4: the static bypass skips the provider call entirely (works
        // on any selected gateway). otp_id stays a required field but is
        // unused on this path; the gate owns the production hard-block.
        if ($this->bypassGate->accepts($code)) {
            $this->lockout->clear($rawToken);

            return;
        }

        // Provider-managed verification (D3): binding is inherent — both
        // steps derive the phone from the same token-hash-resolved order.
        // Gateways convert their own I/O failures into invalid Results
        // (see Twilio/Local), so an invalid result covers wrong, expired
        // and provider-refused codes alike.
        $result = $this->otpGateway->checkVerification($otpId, $code, $order->user_phone);

        if ($result->isValid()) {
            $this->lockout->clear($rawToken);

            return;
        }

        // D8: a failed verification consumes one attempt, then reports a
        // clear failure without cancelling.
        $this->lockout->recordFailure($rawToken);

        throw new GuestCheckoutConflictException(__('guest_checkout.cancel_otp_invalid'), 422);
    }

    /**
     * The cancellation policy exists exactly once — inside the canonical
     * GuestCancellationService::cancel() guard half (format/hash/used/
     * expiry/cancellable/unresolved-attempt, same keys and statuses, no
     * used-mark write). This OTP wrapper adds only the D5 phone-presence
     * refusal both OTP steps need.
     */
    private function resolveCancellableOrder(string $rawToken): Order
    {
        return DB::transaction(function () use ($rawToken) {
            $order = $this->cancellation->resolveCancellableOrderUnderLock($rawToken);

            // D5: guests have no verified phone at capture; an order recorded
            // without one cannot receive a Cancellation OTP, so neither step
            // can proceed. No email channel. Same key/status at BOTH steps.
            if (!is_string($order->user_phone) || trim($order->user_phone) === '') {
                throw new GuestCheckoutConflictException(__('guest_checkout.cancel_no_phone'), 422);
            }

            return $order;
        });
    }
}

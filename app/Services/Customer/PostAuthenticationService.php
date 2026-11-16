<?php

namespace App\Services\Customer;

use App\Services\Currency\UserCurrencyPreferenceService;
use App\Services\Guest\GuestCartMergeService;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;

/**
 * One post-authentication module (round-2 ticket #53).
 *
 * Owns everything that must happen after a customer credential is issued:
 * mobile-customer eligibility (the ONE decision — no per-controller
 * duplicates), guest cart Login Merge, and currency adoption. Every
 * authentication entry point (password token, OTP login/verify, social
 * login, social exchange) calls {@see afterAuthentication()} exactly once
 * after the credential exists, so a new entry point cannot forget the
 * merge.
 *
 * Eligibility decisions that must happen BEFORE a credential is issued
 * (e.g. rejecting an inactive customer before creating a social login
 * code) go through {@see isEligibleCustomer()} so the rule stays in one
 * place as well.
 *
 * Ordering inside afterAuthentication() preserves the order the embedded
 * call sites used before centralization: currency adoption first, then the
 * Login Merge. A merge failure is never fatal for the login itself: it is
 * reported and returned as a `failed` payload while the guest cart stays
 * intact.
 */
final class PostAuthenticationService
{
    public function __construct(
        private readonly GuestCartMergeService $merger,
        private readonly UserCurrencyPreferenceService $currency,
    ) {
    }

    /**
     * The single mobile-customer eligibility decision: tokens and mobile
     * authentication are restricted to active customers.
     */
    public function isEligibleCustomer(?User $user): bool
    {
        return $user !== null
            && $user->type === 'user'
            && (bool) $user->is_active;
    }

    /**
     * Run the post-authentication duties for a successfully authenticated
     * customer: currency adoption, then the guest cart Login Merge.
     *
     * @return array|null the Login Merge payload for the response envelope,
     *                    or null when there is no guest cart to merge (or
     *                    the customer is not eligible).
     */
    public function afterAuthentication(User $user, ?Request $request = null): ?array
    {
        if (!$this->isEligibleCustomer($user)) {
            return null;
        }

        $request ??= request();

        $this->currency->adoptGuestCurrencyOnLogin($user, $request);

        try {
            $result = $this->merger->merge($request, $user);
        } catch (\Throwable $e) {
            // Non-fatal: a merge failure never blocks the login; the guest
            // cart remains intact (GuestCartMergeService rolls back).
            report($e);

            return ['status' => 'failed'];
        }

        if (($result['status'] ?? null) === 'no_guest_cart') {
            return null;
        }

        return $result;
    }
}

# Guest Order Claim — Activation Runbook (OTP PENDING)

Status: **PREPARED, DISABLED**. No route invokes claiming. Both gates below are OFF.

> Note: phone-OTP login routes exist and are active, but the OTP stack has NOT
> been approved as a *claim identity* authoritative proof. Do not reuse the
> login OTP verification as claim proof without a single-use, user-bound
> verification record.

## What exists now

- `App\Services\Guest\GuestOrderClaimService`
  - `eligibleOrders()` — only the caller's own guest identity + verified phone.
  - `issueToken()` — one dedicated one-time claim token per eligible order.
  - `claim()` — consumes the token atomically and attaches `orders.user_id`.
- `App\Contracts\GuestPhoneVerificationContract` bound to
  `App\Services\Guest\NullGuestPhoneVerification` (always returns `null`).
- `guest_order_claim_tokens` table (hash-only, separate from tracking/cancellation).
- Audit event `guest_order_claimed` via `ActivityAuditService`.
- No route, no login/register invocation.

## Two independent gates

1. `GUEST_ORDER_CLAIM_ENABLED=true` (default false).
2. A **real** `GuestPhoneVerificationContract` binding that verifies a
   server-side OTP/challenge and returns `VerifiedPhoneIdentity` for the same
   authenticated user + normalized phone.

Flipping only the flag, or only uncommenting a call, is insufficient.

## Activation steps (only after OTP is signed off)

1. Implement a verifier, e.g. `App\Services\Guest\OtpGuestPhoneVerification`,
   using the existing OTP gateway/consumption record. It must:
   - consume the OTP server-side (no client-asserted “verified” flag);
   - bind the proof to `User::$id` and the normalized phone;
   - return `null` for expired/used/wrong-user/wrong-phone proofs.
2. Bind it in `AppServiceProvider::register()` (replace the Null binding).
3. Add the **issuance** flow:
   - after a successful OTP verification (not merely “send OTP”),
   - resolve the guest identity from the verified cookie (`GuestIdentityService::current`),
   - call `eligibleOrders()`, then `issueToken()` for each selected order,
   - deliver the raw token through an authenticated channel (email/SMS/account page);
   - never log or persist the raw token.
4. Add the **claim** flow (authenticated user + verified phone + token):
   - call `claim()`;
   - handle `already_claimed` as success (idempotent);
   - treat `invalid_token` / `not_eligible` / `verification_required` generically.
5. Enable an invocation point:
   - the existing password login `UserController::token()` already merges carts
     (cart merge, not order claim);
   - for order claim, invoke only after OTP verification succeeds. Commented
     hooks exist in `UserController::verifyLoginOtp()` and `::otpLogin()`.
6. Set `GUEST_ORDER_CLAIM_ENABLED=true` after the verifier is bound.
7. Add dedicated rate limiting for claim issuance and claim attempts
   (per-user + per-IP), plus alerting on repeated generic failures, BEFORE
   exposing any endpoint.
8. Add/execute the claim security tests against the real verifier, including
   a true concurrent claim test on MySQL.

## Explicit non-goals / safety

- Phone equality alone is **not** proof.
- Tracking/cancellation tokens are **not** accepted as claim credentials.
- Claimed orders:
  - `orders.user_id` is set;
  - guest cancellation token is invalidated;
  - guest order/payment endpoints reject the order (`user_id` is no longer NULL);
  - `guest_id` is retained for audit.
- Claim tokens are single-use, expiring, revocable, and hash-only.
- No raw token is written to logs, activity properties, or API responses except
  the one-time issuance response.

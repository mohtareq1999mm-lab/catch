# Guest Order Claim + Guest Cart Merge — Implementation Report

Date: 2026-11-11
Repository: `D:\work\meem`
Branch: `main`
HEAD at start: `a1089c4` (clean working tree)
HEAD observed after implementation: `6cfb63a` (the implementation was captured by an external/automatic commit; no `git commit` was run in this task)
Final working tree: only test-cache + new test assertions + a pre-existing untracked doc (`docs/guest-api-frontend-reference.md`) remain uncommitted.

Evidence labels: VERIFIED, TESTED, IMPLEMENTED, PARTIALLY VERIFIED, UNKNOWN, BLOCKER, NOT RUN, RISK.

---

## 1. Discovery summary

### Authentication
- Password login: `packages/marvel/src/Http/Controllers/UserController.php::token()` (success after `Hash::check`, token created at ~L490-514).
- Registration: `UserController::register()` (~L666) — creates the account and sends OTP, **does not authenticate** (no token).
- OTP email login: `UserController::verifyLoginOtp()` (~L584).
- Phone OTP login: `UserController::otpLogin()` (~L1187).
- Social login: `UserController::socialLogin()` (~L1047).
- Routes: `packages/marvel/src/Rest/Routes.php` L73-106 (`/api/v1/token`, `/register`, `/otp-login`, `/send-otp-code`).
- OTP routes are registered/active, but the OTP stack is not approved as claim-identity proof.

### Guest identity/cart (existing)
- `app/Services/Guest/GuestIdentityService.php` (cookie → hash lookup; `current`, `rawToken`, `csrfToken`).
- `app/Services/Guest/GuestCartService.php` (`findCart`, `ensureCart`, mutations, retention).
- `packages/marvel/src/Database/Repositories/CartRepository.php::applyItemToCart` (shared mutation/pricing path).
- `app/Services/General/CartInventoryService.php` (quantity limits/increment/decrement).
- `packages/marvel/src/Database/Models/Cart.php`, `CartItem.php`; `carts.user_id` UNIQUE, `carts.guest_id` UNIQUE.
- No cart merge existed anywhere (grep).
- No order claim existed anywhere (grep).

### Cart availability representation
- No dedicated item-status column. Existing conventions: nullable `product_id` (`nullOnDelete`), inactive/soft-deleted product, `CartItemResource`, and checkout revalidation.
- Existing `OrderService::assertCartProductsActive` rejects inactive/out-of-stock carts.
- Added `is_available` / `unavailable_reason` to `CartItemResource` (additive) and a NULL-product guard to both standard and fast-shipping checkout guards.

### Test DB
- `catch_verify` connection verified with `php artisan migrate:status`; all migrations Ran.
- New claim-token migration applied.

---

## 2. Feature A — Guest Order Claim (PREPARED, DISABLED)

### Files
- `app/Services/Guest/GuestOrderClaimService.php`
- `app/Contracts/GuestPhoneVerificationContract.php`
- `app/Services/Guest/NullGuestPhoneVerification.php`
- `app/ValueObjects/VerifiedPhoneIdentity.php`
- `app/Models/GuestOrderClaimToken.php`
- `database/migrations/2026_11_11_000003_create_guest_order_claim_tokens_table.php`
- `app/Providers/AppServiceProvider.php` (Null binding)
- `docs/guest-order-claim-activation.md`
- `tests/Feature/Guest/GuestOrderClaimTest.php`
- `tests/Feature/Guest/FakeGuestPhoneVerification.php`

### Behavior
- `eligibleOrders(User, Request, verificationRef, phone)` resolves the guest identity **from the verified cookie**, filters by that guest id + unowned + phone match.
- `issueToken(User, Request, Order, verificationRef, phone)` issues a dedicated one-time token, revoking older unused tokens; re-reads/locks the order and revalidates.
- `claim(User, Request, token, verificationRef, phone)`:
  - requires real phone verification + usable guest identity + dedicated token;
  - locks token and order, validates token/guest/user/order/phone;
  - atomically assigns `orders.user_id` only if still NULL;
  - invalidates guest cancellation + tracking credentials;
  - assigns the order's NULL-user invoices to the claiming user;
  - consumes the token; same-user replay returns `already_claimed`; other users/guests get `invalid_token`.
  - pending-order unique conflict returns `pending_order_conflict` without consuming the token.
  - writes a `guest_order_claimed` audit event (no raw token/phone in properties).

### Activation gates (both required)
1. `GUEST_ORDER_CLAIM_ENABLED=true` (default false).
2. A real `GuestPhoneVerificationContract` binding (default `NullGuestPhoneVerification` always returns null).

No route or live invocation exists. OTP hooks in `UserController` are documented; claim is not called.

### Claim token security
- 32 random bytes → Base64URL (43 chars).
- HMAC-SHA256 (APP_KEY) hash stored in `guest_order_claim_tokens.token_hash` (UNIQUE, hidden).
- Separate table from tracking/cancellation.
- `expires_at`, `used_at`, `revoked_at`; single active token per order/guest.
- Raw token returned only by `issueToken`; never logged.

---

## 3. Feature B — Guest Cart Merge (ACTIVE)

### Files
- `app/Services/Guest/GuestCartMergeService.php`
- `packages/marvel/src/Http/Controllers/UserController.php` (`token()`, `socialLogin()`, OTP flows)
- `packages/marvel/src/Http/Resources/CartItemResource.php` (availability fields)
- `app/Services/General/OrderService.php` (NULL-product guard)
- `app/Services/General/FastShippingService.php` (same guard)
- `app/Services/Guest/GuestCartService.php` (retry if merge deletes cart mid-write)
- `tests/Feature/Guest/GuestCartMergeTest.php`
- `config/guest_checkout.php`, `.env.example`

### Integration points
- Password login `UserController::token()` after successful auth + token creation.
- Social login `UserController::socialLogin()` after successful auth.
- OTP email/phone login success paths (post-auth only; OTP rules untouched).
- Registration: **not** merged (no authentication/token).
- Merge failures are logged and returned as `guest_cart_merge.status=failed`; login still succeeds and guest cart remains.

### Merge behavior
- Ownership only from `GuestIdentityService::current` (cookie). No client cart id.
- Locks guest + account carts in ascending id order; re-reads items under lock.
- Same product+variant+shipping method: sums quantities, caps at per-line max and known stock (variant stock when applicable), reports adjustments.
- New lines use `CartRepository::applyItemToCart` (authoritative pricing/validation).
- Unavailable/deleted/invalid-channel/invalid-variant lines are preserved in the authenticated cart and reported; checkout rejects them.
- Guest cart deleted only at the end of the successful transaction; failure rolls back and preserves it.
- Same-user repeated merge is a no-op (guest cart gone).
- `CartItemResource` exposes `is_available` and `unavailable_reason`; digital lines are NOT marked unavailable for authenticated carts.

### Response
`data.guest_cart_merge`:
- `status` (`merged`/`failed`/absent)
- `added_items`, `merged_items`
- `adjusted_items[]` (requested/applied/dropped + reason/message)
- `unavailable_items[]` (reason/message)
- `guest_cart_removed`
- `cart` (resolved CartResource after commit)

---

## 4. Tests executed (catch_verify)

| Suite | Result |
|---|---|
| `tests/Feature/Guest` | **PASS — 78 tests, 612 assertions** |
| `tests/Feature/AuthenticationTest.php` | **PASS — 19 / 45** |
| `tests/Feature/Rest/OtpLocalLoginTest.php` | **PASS — 4 / 8** |
| `tests/Feature/SocialLoginFlowTest.php` | **PASS — 17 / 64** |
| `tests/Feature/CartApiTest.php` | **PASS — 80 / 335** |
| `tests/Feature/CheckoutApiTest.php` | **PASS — 13 / 18** |
| `tests/Feature/OrderLifecycleTest.php` | **PASS — 6 / 14** |
| `tests/Feature/PendingOrderLifecycleTest.php` | **PASS — 6 / 19** |
| `tests/Feature/Digital/DigitalCartCheckoutTest.php` | **PASS — 9 / 21** |

### Existing failures / environment limits
- `tests/Feature/UserAuthRegressionTest.php` — 8 errors: its `setUp` queries `settings.language`, which the migrated schema does not have; pre-existing test/schema mismatch.
- `tests/Feature/CheckoutRegressionTest.php` — 9 errors after a clean `migrate:fresh`: it creates legacy `variation_options` DDL inside `DatabaseTransactions`, producing `SAVEPOINT trans2 does not exist`; pre-existing.
- `tests/Feature/OrderTrackingTest.php` admin tests — 403 because `view-orders` permission is not seeded in the verify DB; pre-existing.
- Full `php artisan test` still blocked by the pre-existing `tests/Feature/BannerApiTest.php:612` syntax error.

### Test-quality limitations
- Claim verifier is faked; OTP security (single-use/expiry/wrong-user) is not exercised.
- "Concurrent" claim/merge behavior is proven by locks + conditional updates and sequential replay tests, not by a true two-connection parallel run.
- No real provider/payment tests were run (out of scope; unchanged).

---

## 5. Security / regression audit

- No duplicate pricing, inventory, order, or payment authority introduced.
- Guest claim is inert by default and has no route.
- Claim token is separate/hash-only; guest identity resolved from cookie; claimed orders are rejected by guest endpoints/payment.
- Merge uses cookie ownership; transactional locking; guest cart preserved on failure.
- Authenticated cart/checkout behavior preserved (CartApi 80, CheckoutApi 13, Digital 9, OrderLifecycle 6, PendingOrder 6).
- Existing payment architecture untouched.

### Remaining risks
- Real OTP verifier binding + claim endpoints/throttling are required before activation.
- Availability logic remains duplicated between guest cart, merge, resource, and checkout guards; a dedicated authority is recommended.
- Merge may clear line-level promotion metadata via the canonical cart mutation (existing add behavior); pricing is still recalculated server-side. Documented for product review.
- True parallel concurrency and PostgreSQL portability were not executed.
- Claim tokens have no retention/pruning command yet.
- `docs/guest-api-frontend-reference.md` is untracked and was not authored by this task; it was left untouched.

## 6. Verdict

**GUEST CART MERGE VERIFIED**
**GUEST ORDER CLAIM PREPARED — OTP ACTIVATION PENDING**
Overall: **CONDITIONALLY READY**

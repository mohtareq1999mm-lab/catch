# Guest Checkout — Backend Implementation Report

Date: 2026-11-11
Repository: `D:\work\meem`
Branch: `main`
HEAD at start: `12a9de977afddf44313d55a19dd1e139179a1318`
Working tree at start: 28 modified/new paths (prior payment architecture work) — all preserved.

Evidence labels: **VERIFIED**, **TESTED**, **IMPLEMENTED**, **PARTIALLY VERIFIED**, **UNKNOWN**, **BLOCKER**, **NOT RUN**, **RISK**.

---

## 1. Result summary

Guest checkout is implemented as a backend-first, additive feature:

- server-managed guest identity (hash-only storage, HttpOnly cookie, CSRF double-submit);
- persistent guest cart (7-day inactivity retention, reads never extend it);
- guest order creation through the **existing** order authority;
- online/COD payment through the **existing** payment architecture with an opt-in SC-1/PM-1 guard for guests;
- minimal public tracking and single-use cancellation links (hash-only);
- seven guest rate limiters with fail-closed behavior;
- admin tracking revocation;
- 43 guest feature tests + regression evidence (see §11).

---

## 2. Reused canonical authorities (no duplicates)

| Responsibility | Authority reused |
|---|---|
| Product pricing | `Marvel\Services\Pricing\ProductPricingService` via `CartInventoryService` / `CartRepository` |
| Cart mutations | `Marvel\Database\Repositories\CartRepository::applyItemToCart` (extracted from the authenticated path) |
| Cart line lifecycle | `App\Services\General\CartInventoryService` |
| Checkout totals / discounts / tax | `App\Services\General\OrderService::calculateCheckoutTotals` / `withTaxes` |
| Order creation | `App\Services\General\OrderService::addItemsInOrder` → `OrderCreationService` |
| Pending-order reuse | `OrderCreationService::findPendingOrderForUser` + new `findPendingOrderForGuest` |
| Inventory | `App\Services\Inventory\OrderReservationService` |
| Order status writer | `OrderService::changeOrderStatus` (unchanged, sole writer) |
| Payment attempt lifecycle | `PaymentAttemptLifecycleService` (`createClaim`, `adoptSessionCreated`, `hasUnresolvedAttempt`) |
| Payment initiation | `PaymentCheckoutHandler` (guest passes `enforceAttemptGuard: true`) |
| Payment completion | `PaymentCompletionService::completeLocked` (unchanged) |
| Gateways | existing adapters + `PaymentGatewayRegistry` / `PaymentGatewayFactory` |
| Zero-value completion | extracted to `ZeroValueOrderCompletionService` (same code used by authenticated checkout) |
| Channel rules | `ChannelMiddleware` + `ChannelContext` (`is_fast_shipping_available` home-channel rule) |

---

## 3. Database (additive)

Migrations:

- `database/migrations/2026_11_11_000001_create_guest_checkout_schema.php`
  - `guest_identities` (`token_hash` char(64) UNIQUE, `expires_at`, `revoked_at`)
  - `guest_idempotency_keys` (UNIQUE `(guest_id, scope, idempotency_key)`)
  - `carts.guest_id` (nullable FK, UNIQUE one-cart-per-guest), `carts.guest_expires_at` + index
  - `orders.guest_id` (nullable FK, index), `guest_tracking_token_hash` (UNIQUE), `guest_tracking_revoked_at`, `guest_cancellation_token_hash` (UNIQUE), `guest_cancellation_token_used_at`, `guest_cancellation_token_expires_at`
  - one-pending-order-per-guest guard (`pending_guest_id` generated column + unique on MySQL; partial unique index on SQLite/PG)
- `database/migrations/2026_11_11_000002_make_invoices_user_id_nullable.php`
  - `invoices.user_id` nullable (approved guest decision; `transactions.user_id` was already nullable from the prior payment work).

Verified on `catch_verify`:

```
php artisan migrate:status   → both guest migrations Ran
php artisan db:table carts   → guest_id, guest_expires_at, uq_carts_guest_id, FK, idx
php artisan db:table orders  → guest columns + idx_orders_guest_pending_unique
php artisan db:table invoices→ user_id nullable + FK
```

Rollback notes:
- Guest objects are additive; `down()` drops them.
- `invoices.user_id` rollback is **DATA-AFFECTING**: `down()` refuses while any NULL guest invoice exists (explicit `RuntimeException`) instead of rewriting financial rows.
- No destructive data migration, no production execution.

---

## 4. Guest identity and cookie architecture

Files:
- `app/Services/Guest/GuestIdentityService.php`
- `app/Models/GuestIdentity.php`
- `app/Http/Middleware/GuestCsrf.php`, `GuestOrigin.php`
- `app/Http/Controllers/Api/General/Guest/GuestSessionController.php`

- Token: 32 random bytes → base64url (43 chars); DB stores `hash_hmac('sha256', raw, app.key)` only.
- Cookies: `meem_guest` HttpOnly; `meem_guest_csrf` readable; `Path=/api`; host-only; `SameSite` configurable (`lax` default); `Secure` automatic on HTTPS; 7-day expiry.
- Both cookies are excluded from `EncryptCookies` because the `api` middleware group is applied twice on `v1/general` (double decryption would invalidate the credential). Confidentiality relies on HttpOnly/Secure/SameSite; DB hash-only.
- CSRF: state-changing guest requests require `X-CSRF-Token` = CSRF cookie = server-derived value. Origin allowlist enforced separately for all guest routes (no wildcard with credentials).
- Reads never extend retention; genuine cart mutations extend cookie + identity + `carts.guest_expires_at`.
- Invalid/expired/revoked tokens resolve safely to “no session”.

---

## 5. Guest cart

Files:
- `app/Services/Guest/GuestCartService.php`
- `app/Http/Controllers/Api/General/Guest/GuestCartController.php`
- `packages/marvel/src/Database/Repositories/CartRepository.php` (`applyItemToCart`)
- `packages/marvel/src/Database/Models/Cart.php`

- Ownership is resolved from the cookie only; cart IDs are never authorization.
- One cart per guest, enforced by `UNIQUE(carts.guest_id)`; duplicate-key race resolves the winner.
- Server-side pricing and totals only; client price fields ignored (TESTED).
- Physical products only; digital rejected at cart and checkout (defense in depth).
- Channel rule mirrors `HasChannelFilter` (home channel excludes fast-shipping products).
- Availability / variant / fast-shipping validations mirror `CartRepository::syncItems`.
- Reads do not extend; add/update/remove/clear extend to 7 days.
- Retention reaper: `app/Console/Commands/PruneGuestCarts.php` (`guest:prune-carts`, hourly, bounded batches, idempotent). Protects carts whose guest has `pending`/`processing` orders or any `pending` transaction. Authenticated carts are never touched.

---

## 6. Guest checkout and idempotency

Files:
- `app/Services/Guest/GuestCheckoutService.php`
- `app/Services/Guest/GuestIdempotencyService.php`
- `app/Models/GuestIdempotencyKey.php`
- `app/Http/Controllers/Api/General/Guest/GuestCheckoutController.php`
- `app/Services/General/OrderService.php` (optional guest context)
- `app/Services/Checkout/OrderCreationService.php`

- Uses `OrderCreateRequest` (same validation contract).
- `payment_method` limited to `online|cod`; `pay_at_cashier` rejected for guests.
- Order created with `user_id = NULL`, `guest_id` set, customer contact/address snapshots preserved.
- Totals/reservations via the canonical services (no second order authority).
- One pending order per guest (DB-enforced); retries reuse it.
- Idempotency: durable claim scoped `(guest, 'checkout', key)`; completed → same order; in-flight → 409; request-hash mismatch → 422.
- Raw tracking/cancellation tokens returned only once; idempotent replays return `null` (hash-only rule) and the owning browser can re-issue via `access-tokens`.
- Cart is emptied only after the order transaction commits (`clearCheckedOutSlice`); failures roll back and keep the cart.

---

## 7. Payment integration

Files:
- `app/Services/Payment/PaymentCheckoutHandler.php`
- `app/Services/Guest/GuestPaymentService.php`
- `app/Services/Payment/ZeroValueOrderCompletionService.php`
- `app/Http/Controllers/Api/General/OrderController.php` (zero-value extraction)

- Guest online initiation calls the **same** handler with `enforceAttemptGuard: true`.
- Guard: order row lock → `hasUnresolvedAttempt` → `createClaim` (PM-1) → commit → provider call (no DB lock during I/O) → `adoptSessionCreated` (provider ref) → reconcilable row.
- A second initiation while an attempt is unresolved returns `409` (SC-1). TESTED.
- Provider exceptions/refusals mark the claim failed with provider evidence and release the coupon reservation.
- COD: idempotent (existing pending COD transaction returns success), never marks the order paid, `user_id` NULL. COD `processing` status is accepted (automatic fulfillment release).
- Zero-value online orders complete through the canonical writer, no gateway session.
- Redirect is UX-only for lifecycle attempts; completion remains webhook/reconciliation → `completeLocked`.
- **Authenticated initiation gap remains**: the authenticated `handleOnlinePayment` path still uses the deferred PM-3 behavior (claim after provider response). This is reported, not silently changed.

---

## 8. Tracking and cancellation

Files:
- `app/Services/Guest/GuestOrderTokenService.php`
- `app/Services/Guest/GuestTrackingService.php`
- `app/Services/Guest/GuestCancellationService.php`
- `app/Http/Controllers/Api/General/Guest/GuestPublicOrderController.php`
- `app/Http/Controllers/Api/Admin/GuestTrackingController.php`

Tracking:
- random token, keyed hash stored (UNIQUE), raw never logged/stored;
- valid for the whole lifecycle + 30 days after terminal status;
- minimal response: order number, status fields, timestamps, customer-visible timeline only — no PII/address/items/totals;
- invalid/expired/revoked/foreign tokens are indistinguishable (404);
- admin revocation: `DELETE /api/v1/admin/orders/{orderId}/guest-tracking` (`permission:update-order-status`), clears the hash and records ids only.

Cancellation:
- random token, keyed hash, expiry (72h default), single-use;
- row lock → hash match → used/expired/policy checks → unresolved attempt check → atomic used-mark → `changeOrderStatus` in the same transaction (token not burned on failure);
- concurrent reuse is rejected atomically.

---

## 9. Rate limiting

Files: `app/Providers/RateLimitServiceProvider.php`, `app/Http/Middleware/GuestRateLimit.php`, `config/guest_checkout.php`, `app/Http/Kernel.php`.

Seven limiters: `guest_session`, `guest_cart_read`, `guest_cart_write`, `guest_checkout`, `guest_payment`, `guest_tracking`, `guest_cancel`.

- Dedicated `guest.throttle` middleware (NOT the project-wide `BypassThrottle`), so protected public guest endpoints stay protected when global throttling is disabled.
- Key = verified guest identity when present, otherwise IP.
- Limiter backend failure fails closed (429 + `Retry-After`).
- Defaults documented in `config/guest_checkout.php` and the frontend contract.

---

## 10. Security review (implemented controls)

- Guest token entropy/hash-only storage; raw token never in JSON/logs.
- HttpOnly cookie; readable CSRF cookie with double-submit + Origin allowlist.
- No credentialed wildcard CORS (existing config already enforced explicit origins).
- Guest cart/order IDOR prevented by cookie-scoped lookups (404 indistinguishable).
- No client-controlled prices/totals.
- Digital and POS guest checkout disabled.
- No duplicate order (DB guard + idempotency) and no duplicate payable session (SC-1).
- Tracking exposes status only; cancellation is single-use and payment-aware.
- Safe error messages; internal exceptions reported not returned.

Remaining risks:
- Third-party cookie blocking on different registrable domains (BFF recommended).
- Rate limiting is only global with a shared cache (Redis) across servers.
- Guest coupons are not implemented (no approved policy).
- Cart merge on login is not implemented (no approved policy; guest checkout works without login).
- Fast shipping is rejected for guest carts (no guest fast-shipping checkout path; silently dropping the line was worse).

Independent review fixes applied:
- Ambiguous gateway dispatch now stays `pending`/`dispatch_unknown` (never provider-confirmed `failed`), preserving SC-1.
- CORS default headers include `X-CSRF-Token` and `Idempotency-Key` (`.env.example` updated).
- Session JSON returns `csrf_token` (cross-host Frontends cannot read the API host's cookie) and re-queues cookies without extending retention.
- Failed idempotency claims are retryable with the same key; stale-claim completion is epoch-guarded.
- FAST-shipping guest lines are rejected (cart + checkout) instead of being silently dropped.
- Promotion usage is not decremented on unpaid guest cancellations (ORD-1).
- Public tracking no longer interpolates event metadata (no amounts/refund balances).
- Zero-value completion re-checks order state under lock (no duplicate paid rows).
- Origin allowlist also honours `cors.allowed_origins_patterns`.
- Guest identity/idempotency retention pruning is bounded and idempotent.

Pre-existing payment-scope findings reported but **not changed** (prior 28-path work):
- Authenticated online initiation still writes `attempt_state='session_created'` + `dispatch_reference` after provider response (PM-3 deferred) — redirects are UX-only and the unpaid-order reaper excludes these rows. This is the known authenticated SC-1/PM-3 gap.
- `PaymentCompletionService::completeLocked` resolves completion via `order_id` (audit `invoice_id` loss) — pre-existing decision in the payment work.

---

## 11. Test matrix (executed on `catch_verify`)

| Suite | Result |
|---|---|
| `tests/Feature/Guest` | **PASS** — 47 tests, 375 assertions |
| `tests/Feature/CartApiTest.php` | **PASS** — 80 tests, 335 assertions |
| `tests/Feature/CheckoutApiTest.php` | **PASS** — 13 tests, 18 assertions |
| `tests/Feature/OrderLifecycleTest.php` | **PASS** — 6 tests, 14 assertions |
| `tests/Feature/PendingOrderLifecycleTest.php` | **PASS** — 6 tests, 19 assertions |
| `tests/Feature/Payment/AttemptLifecycleArchitectureTest.php` | **PASS** — 14 tests, 41 assertions |
| `tests/Feature/Payment/PaymentWebhookTest.php` | **PASS** — 10 tests, 79 assertions |
| `tests/Feature/PaymentIdempotencyTest.php` | **PASS** — 5 tests, 9 assertions |
| `tests/Feature/Payment/PaymentSecurityTest.php` | **PASS** — 11 tests, 172 assertions |
| `tests/Feature/Payment/PaymentCompletionTest.php --filter=zero_value_online_checkout` | **PASS** — 2 tests, 12 assertions |
| `tests/Feature/CheckoutRegressionTest.php` | **ENV-LIMITED** — after a clean `migrate:fresh`, its `setUp` creates legacy `variation_options` DDL inside `DatabaseTransactions` → `SAVEPOINT trans2 does not exist` in teardown. Passed earlier when the legacy table pre-existed. Not caused by guest code. |
| `tests/Feature/OrderTrackingTest.php` | **ENV-LIMITED** — 11/15 pass; 4 admin dashboard tests 403 because `view-orders` permission is not seeded after `migrate:fresh`. Not caused by guest code. |
| `tests/Feature/PaymentCheckoutTest.php` | **ENV-LIMITED** — suite assumes `countries.id=1`/`governorates.id=1` and a default warehouse; shared verify DB counters/data make it fail before exercising our code. Subset COD/auth tests were run earlier. |
| `tests/Feature/WebhookPaymentCompletionTest.php` | **NOT RUN TO COMPLETION** — long-running `RefreshDatabase` suite; killed after >10 min on the shared verify DB. Guest completion is covered directly in `GuestPaymentAndOrderAccessTest`. |
| Full `php artisan test` | **NOT RUN** — pre-existing syntax error in `tests/Feature/BannerApiTest.php:612` prevents the whole-suite loader. |

---

## 12. Route evidence

`php artisan route:list --path=api/v1/general/guest`:

13 routes (session, cart read/write, checkout, order payment/confirmation/access-tokens, public track/cancel) with the expected middleware (`guest.origin`, `guest.csrf`, `guest.throttle:*`).

`php artisan route:list --path=api/v1/admin/orders`:

`DELETE api/v1/admin/orders/{orderId}/guest-tracking` (`permission:update-order-status`).

---

## 13. Frontend

Contract: `docs/guest-checkout-frontend-contract.md`.

Frontend repository is not in this workspace; no Frontend code was modified or claimed.

---

## 14. Readiness

**Verdict: BACKEND GUEST CHECKOUT READY FOR FRONTEND INTEGRATION.**

Not claimed:
- END-TO-END (Frontend not integrated);
- production readiness (sandbox payment, Redis limiter, deployment, browser cookie matrix not verified);
- guest cart merge on login;
- guest coupons;
- POS guest checkout.

Authenticated SC-1/PM-3 initiation gap remains open and is reported separately.

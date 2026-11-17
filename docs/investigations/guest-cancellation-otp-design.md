# Design Record — Guest Order Cancellation with OTP Verification

**Status**: DESIGN ONLY — no code written. Implementation is owned by the parallel session
(the session already carrying the uncommitted OTP config WIP).
**Process**: grill-with-docs round 1 + round 2; user accepted every decision ("all yes").
Binding documents: this record + `docs/adr/0003-static-otp-bypass-is-value-only-and-hard-gated.md` + `GLOSSARY.md` § Cancellation & OTP.

---

## 1. Verified facts (this session, with anchors)

- `POST /api/v1/guest/orders/cancel` exists: `routes/api.php` → `GuestPublicOrderController::cancel`
  (`app/Http/Controllers/Api/General/Guest/GuestPublicOrderController.php`) → `GuestCancellationService::cancel(rawToken)`.
  Thin controller; `GuestCheckoutConflictException` → `apiResponse($msg, $httpStatus, false)`.
- `GuestCancellationService` (`app/Services/Guest/GuestCancellationService.php`) is the **single cancellation authority**:
  token-format check → HMAC-SHA256 hash (`GuestOrderTokenService::hash`, keyed with `app.key`) → transaction:
  `lockForUpdate` lookup by `guest_cancellation_token_hash` → used/expired checks → `isOrderCancellable`
  → `PaymentAttemptLifecycleService::hasUnresolvedAttempt` → **409** if unresolved → atomic used-mark
  (`update ... whereNull('guest_cancellation_token_used_at')`) → `OrderService::changeOrderStatus(..., 'cancelled',
  auditReason: 'Guest single-use cancellation link', skipPromotionDecrement: unpaid)` → rollback of used-mark on throw.
- `GuestOrderTokenService::isOrderCancellable` (`app/Services/Guest/GuestOrderTokenService.php`):
  `status ∈ {PENDING, PROCESSING} && payment_status !== SUCCESS`. This IS the cancellable-state policy.
- Token TTL: `config('guest_checkout.cancellation_link_ttl_hours', 72)`. This IS the "grace period".
- OTP stack (all committed + unit-tested): `packages/marvel/src/Otp/` — `OtpInterface`, `Gateways/OtpGateway`,
  `Gateways/LocalGateway`, `Result`; `tests/Unit/OtpLocalGatewayTest.php`. Provider-managed verification:
  `startVerification(phone)` → `Result` carrying an `otp_id`; `checkVerification(id, code, phone)` → `Result`.
  `LocalGateway`: id `'local-verification-id'`, `hash_equals` against `auth.local_otp_code`, gated by
  `auth.local_otp_enabled`, refused when empty; no real SMS sent.
- Config (committed, `packages/marvel/config/auth.php`): `active_otp_gateway` (twilio|messagebird|local),
  `local_otp_enabled` (default `APP_ENV !== 'production'`), `local_otp_code` (`'123456'`).
  **Uncommitted WIP (parallel session, 10:31–10:34)** adds `master_otp` there and mirrors the OTP block into
  `config/auth.php`, plus `.env.example` `MASTER_OTP=123456 / LOCAL_OTP_ENABLED=true / LOCAL_OTP_CODE=123456`,
  and edits `UserController.php`.
- Login-OTP caller pattern: `UserController::sendOtpCode` → `getOtpGateway()->startVerification($phone)` →
  returns `otp_id` to the client; verification via `checkVerification(id, code, phone)`. No app-side code storage anywhere.
- Canonical events: `app/Services/General/OrderService.php` — `changeOrderStatus` fires `OrderStatusChanged` (L1388)
  and `OrderCancelled` (`App\Events\OrderCancelled`, L1391). Never fire a second event manually.
- Rate limiters (`app/Providers/AppServiceProvider.php`): only `public-api` active; the `throttle:otp` named in the
  task spec is **commented out** (L208). Guest routes use the `GuestSurface` limiter pattern (#52).
- Order phone: `orders.user_phone` written at creation (`app/Services/Checkout/OrderCreationService.php` L67),
  **may be null** — guests have no verified phone at capture.
- Schema: `guest_cancellation_token_hash/used_at/expires_at` all exist → **zero migrations**.
- Route surfaces: `app/Enums/GuestSurface.php` has one case per guest route (`ORDER_CANCEL` exists);
  `GuestRouteContractTest` fails loud on token-less/unmapped routes.

## 2. Decisions (all user-accepted)

- **D1 — Flow shape.** Two endpoints: NEW `POST /api/v1/guest/orders/cancel/request-otp` (initiate; never consumes the
  token) and the EXISTING `POST /api/v1/guest/orders/cancel` (confirm; now requires OTP).
- **D2 — Credentials.** The Cancellation Token is the **sole** credential for request-otp. No
  `order_number`/`user_phone` fallback (enumeration + SMS-bombing oracle). Confirm = token + otp_id + code.
- **D3 — OTP mechanism.** Reuse the provider-managed gateway stack. request-otp =
  `OtpGateway::startVerification(order->user_phone)` (provider generates/sends/manages the code; we store nothing).
  Confirm = `checkVerification(otp_id, code, order->user_phone)`. Binding is inherent: both steps derive the phone
  from the same token-hash-resolved order; a mismatched otp_id+phone fails at the provider; LocalGateway pins the id.
- **D4 — Bypass gating (see ADR-0003).** Static-code acceptance is owned by the cancellation OTP service (universal
  across gateways), gated **only** on `auth.local_otp_enabled` **and** hard-blocked in production
  (`APP_ENV === 'production'` → never bypass, regardless of env). `hash_equals` always. Accepted value:
  `MASTER_OTP` if non-empty, else `local_otp_code`. In bypass mode the provider call is skipped; `otp_id` remains
  a required field but is unused.
- **D5 — No-phone orders.** request-otp → **422** with a translation key when `user_phone` is null. No email channel.
- **D6 — Confirm body.** `{ token, otp_id, code }` — all required.
- **D7 — Route/surface.** New `GuestSurface` case `ORDER_CANCEL_OTP` (one case per route, per #52), same middleware
  shape as `ORDER_CANCEL` plus its own limiter key (e.g. `guest.throttle:guest_cancel_otp`, per-IP **and** per-token).
  The commented-out global `throttle:otp` is NOT resurrected.
- **D8 — Brute-force lockout.** Laravel `RateLimiter` (cache store): max **5** failed code attempts per token-hash →
  429; cleared on success; no DB schema.
- **D9 — Policies preserved, unchanged.** `isOrderCancellable` stays the only state policy; unresolved payment
  attempt → **409 at BOTH steps** (fail at request-otp **before** dispatching any SMS); the used-mark stays atomic
  with rollback-on-failure; no manual event firing (`changeOrderStatus` already fires both events); **zero migrations**.
- **D10 — Venue.** Tests under `tests/Feature/Guest/` (+ unit test for the bypass gate); translations in the
  `guest_checkout.*` namespace added to **all** supported locales; docs confined to `docs/investigations/` + ADR + GLOSSARY.

## 3. Breaking-change disclosure (approved by the task itself)

`POST /api/v1/guest/orders/cancel` changes contract: previously `{token}` alone succeeded; now `{token, otp_id, code}`
is required. Frontend/mobile consumers must ship the request-otp step in lockstep. This is the task's explicit intent.

## 4. Failure matrix (both steps unless noted)

| Condition | request-otp | confirm |
|---|---|---|
| token format invalid / hash miss | 404 `guest_checkout.cancel_link_invalid` | same |
| token already used | 422 `cancel_link_used` | same |
| token expired | 422 `cancel_link_expired` | same |
| not cancellable (`isOrderCancellable`) | 422 `checkout.order_cancel_not_allowed` | same |
| unresolved payment attempt | **409 before any SMS** | 409 |
| `user_phone` null | 422 (new key, e.g. `guest_checkout.cancel_no_phone`) | n/a |
| rate limited (guest limiter / 5-attempt lockout) | 429 | 429 |
| wrong/expired code, bypass refused | n/a | 422 |
| success | 200 `{ otp_id, provider, message }` | 200 (existing shape: `order_number` + `status`) |

Failure responses must not reveal which check failed beyond the existing keys' granularity; no raw hashes,
no `master_otp` values, no full phone numbers in responses or logs.

## 5. Test checklist (for the implementing session)

1. Success via a fake/gateway OTP (real path, `checkVerification`).
2. Success via the static code when `local_otp_enabled=true`.
3. Refusal of the static code when `local_otp_enabled=false`.
4. **Production hard block**: `APP_ENV=production` + `local_otp_enabled=true` → static code refused.
5. `MASTER_OTP` overrides `local_otp_code` as the accepted value (and works with any gateway selected).
6. Invalid/expired code → 422; rate limiting on request-otp → 429; 5-attempt lockout on confirm → 429; lockout cleared on success.
7. No-phone order → 422 at request-otp.
8. Token-only credentials: no fallback path exists; missing fields → validation error.
9. request-otp does NOT consume the token (`used_at` stays null) and does not cancel.
10. Unresolved payment attempt → 409 at request-otp with **no SMS sent** (gateway spy), and at confirm.
11. Non-cancellable state → 422 at both steps.
12. Confirm executes the canonical path exactly once; `App\Events\OrderCancelled` fired via `changeOrderStatus`
    (assert no duplicate), promotion-decrement skipped for unpaid orders (existing semantics).
13. `GuestRouteContractTest` updated: new route mapped to the new `GuestSurface` case (contract fails loud otherwise).
14. Bypass gate unit test (timing-safe compare + gating rules) at unit level, not only HTTP.

## 6. Config hygiene recommendation (to the implementing session)

In the WIP config, prefer `'master_otp' => env('MASTER_OTP')` (null default) over `env('MASTER_OTP', '123456')`
so "unset" stays distinguishable from "configured"; the accepted value already resolves
`MASTER_OTP` → `local_otp_code` in code (D4). The `.env.example` values are then safe in every environment.

## 7. Ownership / collision notes

- The parallel session owns implementation AND already owns the WIP files:
  `config/auth.php`, `packages/marvel/config/auth.php`, `.env.example`, `packages/marvel/src/Http/Controllers/UserController.php`.
- My session wrote only: `docs/adr/0003-*.md`, `GLOSSARY.md` (new cluster), this record. No code touched.
- Outstanding from earlier rounds (user's call, unrelated to this task): orphaned `26d3f22`
  (`withTrashed` CategoryImportTest fix) still not in `origin/main`.

## 8. Evidence of record (ticket #71)

Executed on the integration branch tip `e17dc3a` in an isolated worktree against the isolated
database `catch_verify_t71` (the repo's phpunit guard refuses a database named exactly `catch`).

Environment: PHP 8.2.30 (ZTS), PHPUnit 10.0.13, Laravel Framework 10.30.1.

### 8.1 Suites (fresh isolated DB)

All commands ran with `$env:DB_DATABASE='catch_verify_t71'`:

| Command | Result |
|---|---|
| `php vendor/bin/phpunit tests/Feature/Guest` | **OK (164 tests, 1527 assertions)** |
| `php vendor/bin/phpunit tests/Feature/Rest/OtpLocalLoginTest.php` | OK (7 tests, 17 assertions) |
| `php vendor/bin/phpunit tests/Feature/Rest/OtpEmailCustomerOnlyTest.php` | OK (4 tests, 12 assertions) |
| `php vendor/bin/phpunit tests/Feature/AuthenticationTest.php` | OK (23 tests, 63 assertions) |
| `php vendor/bin/phpunit tests/Feature/Localization/TranslationCatalogueTest.php` | OK (24 tests, 8327 assertions) |
| `php vendor/bin/phpunit tests/Unit/GuestStaticOtpBypassGateTest.php` | OK (21 tests, 25 assertions) |
| `php vendor/bin/phpunit tests/Unit/GuestCancellationOtpLockoutTest.php` | OK (2 tests, 4 assertions) |

The guest battery includes the route-surface contract (fails loud on unmapped routes) and the
session/cookie posture contracts.

### 8.2 Development end-to-end demonstration (static code, no real SMS)

Method: `php artisan serve` on the isolated verify DB with `APP_ENV=local` and
`ACTIVE_OTP_GATEWAY=local` (environment overrides the `.env`'s `twilio` selection), then real HTTP
calls through the public API: guest session -> cart item -> COD checkout -> request cancellation
OTP -> confirm with the static code `123456` (`LOCAL_OTP_CODE`). Transcript (token redacted):

```text
STEP 1  session opened ................. success=True
STEP 2  cart item added ................ success=True
STEP 3  checkout placed ................ order=<id 1> cancellable_token=56Vvat...3bktc4U
STEP 4  request-otp dispatched ......... harness=local otp_id=local-verification-id provider=local
STEP 5  confirm with static code ....... message='Order cancelled successfully.' order.status=cancelled
STEP 6  replay rejected ................ HTTP 422 {"status":422,"message":"This cancellation link has already been used.","success":false}
STEP 7  token-only rejected ............ HTTP 422 {"message":"The given data was invalid.","status":false,"success":false,"errors":{"otp_id":["The OTP ID field is required."],"code":["The code field is required."]}}
```

Persisted state after the flow (`orders` row 1): `status=cancelled`, `cancelled_at` set,
`guest_cancellation_token_used_at` set, `payment_status=payment-pending`,
`user_phone=+201000000001`.

No real SMS: the request step ran on the `local` gateway (`LocalGateway` performs no network I/O —
`startVerification` returns a static `Result`), and the confirm step accepted the static code
through the bypass gate, which never touches the gateway. Both properties are also pinned by
tests: `the_static_code_is_accepted_with_any_gateway_selected_and_skips_the_provider` (HTTP seam)
and the full gating matrix (unit seam).

### 8.3 Old token-only contract — remaining references

`git grep` for `orders/cancel` across the repository finds call sites only in `routes/api.php`,
the guest test suites, and documentation. Two frontend-facing documents still describe the old
token-only payload — `docs/guest-checkout-frontend-contract.md` (cancel action) and
`docs/guest-api-frontend-reference.md` (endpoint 9). They are stale and were deliberately NOT
edited here: documentation mode is off (API docs change only on an explicit documentation
command). They must be updated in the release that ships this breaking change. No runtime consumer
(mobile app, SDK, JS, Postman collection) exists in this repository.

### 8.4 Note on demo data and test isolation

The E2E demonstration leaves rows in the verify database. Tests that assert absolute row counts
fail against that pre-existing data (7 such assertions were observed). The suite evidence in 8.1
was taken after `php artisan migrate:fresh --force` on the same isolated database — this was demo
data pollution, not a code regression, and it is not reachable through the normal test flow
(`DatabaseTransactions` rolls each test back).

# Guest Cart — Web + Mobile Authentication: Discovery & Audit (Phase One)

Status: **READ-ONLY DISCOVERY — no code, config, migration, test or database change was made.**
Nothing in this document is implemented or approved. Implementation waits for explicit approval (§13).
Evidence labels: **VERIFIED** (read in current source) · **TESTED** (asserted by an existing test) · **INFERRED** (deduced from framework behaviour) · **UNKNOWN** (no evidence available) · **RISK** (defect or exposure that needs a decision).

---

## 1. Method

Inspected, not assumed:

- `routes/api.php`, `packages/marvel/src/Rest/Routes.php`, `app/Providers/RouteServiceProvider.php`, `packages/marvel/src/Providers/RestAPIServiceProvider.php`
- `app/Http/Kernel.php`, all guest middleware (`GuestOrigin`, `GuestCsrf`, `GuestRateLimit`), `ChannelMiddleware`, `CheckLangMiddleware`, `RequestIdMiddleware`, `EncryptCookies`, `BypassThrottle`
- `GuestSessionController`, `GuestCartController`, `GuestCheckoutController`, `GuestOrderController`, `GuestPublicOrderController`
- `GuestIdentityService`, `GuestCartService`, `GuestCartMergeService`, `GuestCheckoutService`, `GuestOrderTokenService`, `GuestOrderClaimService`
- `CartRepository`, `CartInventoryService`, `ProductPricingService`
- `Product`, `ProductVariant`, `Category`, `Cart`, `CartItem`, `GuestIdentity`
- `CartCreateRequest`, `CartUpdateRequest`
- `ApiResponse`, `app/Exceptions/Handler.php`, `CartResource`, `CartItemResource`
- `config/guest_checkout.php`, `config/cors.php`, `config/channel.php`, `config/cart.php`, `config/telescope.php`, `.env` (non-secret keys only)
- migration `2026_11_11_000001_create_guest_checkout_schema.php`
- existing suites: `tests/Feature/Guest/*`, `tests/Feature/CartApiTest.php`, `tests/Feature/Cart/*`, `docs/guest-checkout-frontend-contract.md`, `docs/api/post-cart.md`

**No test was executed in this phase.** `phpunit.xml` targets MySQL via `DB_DATABASE` default `catch`, there is no `.env.testing`, and the suite uses `DatabaseTransactions` against a real schema. Executing tests is a database write and is deferred to the approved test phase with an isolated database (§11).

---

## 2. Architecture map (current, verified)

```
                        ┌──────────────────────────── Browser (only supported client) ───────────────────────────┐
                        │  Cookie meem_guest (HttpOnly) + meem_guest_csrf (readable) + X-CSRF-Token               │
                        └───────────────────────────────────────────┬─────────────────────────────────────────────┘
                                                                    │
RouteServiceProvider: prefix api ──► routes/api.php ──► prefix v1/general ──► middleware(['api','throttle:public-api'])
                                                                    │
                          ┌─────────────────────────────────────────▼──────────────────────────────────────────┐
                          │ api group: throttle:api(Bypass), EncryptCookies, AddQueuedCookies,                 │
                          │            SubstituteBindings, RequestId, Channel, Lang    (applied TWICE)          │
                          │ guest.origin → guest.csrf → guest.throttle:<limiter>                               │
                          └─────────────────────────────────────────┬──────────────────────────────────────────┘
                                                                    │
                 ┌──────────────────────────────┬───────────────────┴──────────────────┬──────────────────────────┐
                 │ GuestSessionController       │ GuestCartController                 │ GuestCheckout/Order      │
                 │ POST/DELETE guest/session    │ GET/POST/PUT/DELETE guest/cart*     │ guest/checkout, orders/* │
                 └──────────────┬───────────────┴───────────────────┬──────────────────┴──────────────┬───────────┘
                                │                                   │                                 │
                                └──────────────► GuestIdentityService::current($request) ◄─────────────┘
                                                     (cookie → HMAC → guest_identities)
                                                                    │
                                                          GuestCartService
                                        assertEligibleItem → ensureCart → applyWithRetry → touch
                                                                    │
                                  CartRepository::applyItemToCart (DB::transaction, SELECT … FOR UPDATE)
                                    ├─ syncItems (variant/product/shipping/operation rules)
                                    └─ CartInventoryService::incrementItem → upsertItem
                                                                    │
                                                     ProductPricingService (server-authoritative)
                                                                    │
                                              carts / cart_items  (+ guest_expires_at, 7-day retention)
```

Authenticated customer path (separate, looser): `packages/marvel/src/Rest/Routes.php` → `POST /api/v1/cart` → `Marvel\Http\Controllers\CartController::store` → `CartRepository::storeCart` → same `applyItemToCart`. Middleware: `auth:sanctum`, `throttle:cart`, `lang`.

### 2.1 Database (verified)

`guest_identities`: `id`, `token_hash` char(64) UNIQUE, `expires_at`, `revoked_at`, timestamps, index on `expires_at`.
`carts`: `user_id` NULL, `guest_id` UNIQUE (FK → guest_identities, nullOnDelete), `guest_expires_at`, index `(guest_id,status)`, index `guest_expires_at`.
`cart_items`: `cart_id`, `product_id`, `product_variant_id`, `quantity`, `price`, `total_price`, `attributes` (json), `shipping_method`, `promotion_id`, `discount_amount`, `is_gift`.
No mobile/token-per-device table exists.

### 2.2 Identity resolution is a single choke point (verified)

`GuestIdentityService::current()` is called by: `GuestCartController` (4×), `GuestCheckoutController`, `GuestOrderController` (3×), `GuestSessionController` (2×), `GuestOrderClaimService`, `GuestCartMergeService`, `RateLimitServiceProvider::guestKey`, `GuestCsrf`.
⇒ Adding Bearer support in `current()` propagates to **cart, checkout, payment, guest orders, login merge, order claim and rate-limit keying** in one place. This is the architectural lever, and its blast radius is a decision item (D1).

---

## 3. Current Browser flow (verified, mostly tested)

1. `POST /api/v1/general/guest/session` — `GuestOrigin` (no Origin ⇒ pass), `guest_session` limiter. `GuestSessionController::store` → `current()`; if none, `GuestIdentityService::create()` (32 random bytes → base64url 43 chars, HMAC-SHA256 with `APP_KEY`, only hash stored) + `queueCookies()`.
   Response: `{guest.expires_at, csrf_token, cookies{…}}`. Raw credential never in JSON (**TESTED**). CSRF cookie readable by JS; identity cookie `HttpOnly` (**TESTED**).
2. Cookies: `Path=/api`, `Domain` null unless configured, `Secure` when request is HTTPS (or `GUEST_COOKIE_SECURE`), `SameSite` from config (`lax` default; `none` refused without `Secure`). TTL 7 days (**TESTED**), refreshed only on genuine mutations.
3. Write request: browser sends `Cookie` automatically + `X-CSRF-Token` from `data.csrf_token` (or the readable cookie on same host). `GuestOrigin` validates `Origin` **only when present**; `GuestCsrf` requires `header == cookie == HMAC(identity)` for a live identity; missing token ⇒ 419; no identity ⇒ 401 from the controller (CSRF cannot be bypassed by "having no session").
4. `GuestCartController::store` → `GuestCartService::addItem` (eligibility, cart, lock, price) → 201 `CartResource`; `touch()` extends `guest_expires_at` and re-queues cookies.
5. `GET /guest/cart` is read-only: never extends retention (**TESTED**).

**Gap:** no Bearer credential exists. A native app cannot present `meem_guest` (HttpOnly cookie jar) and cannot satisfy a double-submit CSRF token reliably. Mobile is therefore **unsupported today**.

---

## 4. Mobile readiness assessment

| Capability | Exists today | Evidence |
|---|---|---|
| Opaque guest token issuance | No | No token endpoint; `GuestIdentityService` only emits cookies |
| Token storage/hash | No (cookie token only) | `guest_identities.token_hash` is the cookie credential |
| Bearer resolution | No | `current()` reads `$request->cookie(...)` only |
| CSRF exemption for non-ambient credentials | No | `GuestCsrf` has no auth-mode concept |
| Origin-independent access | Yes (no Origin ⇒ allowed) | `GuestOrigin::assertAllowed()` early return |
| Shared cart business logic | Yes | `GuestCartService` / `CartRepository` / `CartInventoryService` |
| Session revocation | Yes (identity-level, cookie) | `DELETE /guest/session` sets `revoked_at` |
| Sliding expiry | Yes (mutation only) | `GuestCartService::touch` → `GuestIdentityService::extend` |
| Mobile contract/docs | No | `docs/guest-checkout-frontend-contract.md` documents browser only |

Conclusion: **mobile readiness = 0 %**. Everything reusable already exists; the missing pieces are credential issuance, Bearer resolution, auth-mode-aware CSRF, and a contract.

---

## 5. Findings register

Severity: CRITICAL / HIGH / MEDIUM / LOW.

| ID | Sev | Finding | Evidence | Status |
|---|---|---|---|---|
| F-01 | HIGH | Guest identity resolves **only** from the HttpOnly cookie; no Bearer path. Native apps cannot authenticate. | `GuestIdentityService::current()` — `$request->cookie(self::COOKIE)` only. | VERIFIED |
| F-02 | HIGH | `GuestCsrf` would block any future Bearer request as soon as identity resolution accepts it (no auth-mode distinction). | `GuestCsrf::handle()` requires cookie+header+HMAC whenever an identity resolves. | VERIFIED |
| F-03 | HIGH | `GuestIdentityService::current()` is used by cart **and** checkout/orders/payment/merge/claim; enabling Bearer silently widens mobile access to all of them. | 14 call sites (grep). | VERIFIED |
| F-04 | HIGH | `cart_items` uniqueness/merging is enforced by row lock only; there is **no idempotency** on cart writes. A retried add increments twice. | `CartRepository::applyItemToCart`; no `Idempotency-Key` handling on cart routes. | VERIFIED |
| F-05 | HIGH | Telescope is enabled by default with **no header redaction**; `Authorization`/`Cookie` would be persisted to `telescope_entries`. | `config/telescope.php` (`TELESCOPE_ENABLED` default `true`), no `hideRequestHeaders` anywhere. | RISK |
| F-06 | MEDIUM | Variant existence is validated **globally**, then re-scoped to the product in the repository ⇒ generic `422 Invalid item data` instead of a field error. | `CartCreateRequest`: `exists:product_variants,id`; `CartRepository::syncItems`: `$product->variations()->whereKey($variantId)`. | VERIFIED |
| F-07 | MEDIUM | `item.operation` is unvalidated on **create**; any non-`increment`/`decrement` value returns `201` with an unchanged cart (silent no-op). Update **does** validate it. | `CartCreateRequest` (absent) vs `CartUpdateRequest` (`required|in:increment,decrement`); `syncItems` has no `else`. | VERIFIED |
| F-08 | MEDIUM | Variant stock is never validated at add time; only product-level stock is, and only when `stock_quantity > 0`. Dead variants are addable and later flagged `variant_unavailable`. | `GuestCartService::assertEligibleItem`; `CartItemResource::availability()`; contrast `GuestCartMergeService::knownAvailableQuantity()` (variant-stricter). | VERIFIED |
| F-09 | MEDIUM | Three different error envelopes: `ApiResponse` (`status` = HTTP code), `Handler` (`status:false`), `GuestCsrf` (`status:419`). No machine-readable reason code. | `ApiResponse`, `Handler::handleJsonResponse`, `GuestCsrf`. | VERIFIED |
| F-10 | MEDIUM | Guest and authenticated cart reject the same domain failure with different statuses: guest `422`, authenticated `400` (live exception text). | `GuestCartController::store` vs `CartController::store` (`400`). | VERIFIED |
| F-11 | MEDIUM | `throttle:public-api` is attached to every `v1/general` route but the limiter is **not registered** (commented out). `BypassThrottle` hides it only while `DISABLE_RATE_LIMITING=true`. If enabled, the 6-argument delegation skips the named-limiter branch and `InvalidArgumentException` ⇒ `500`. | `AppServiceProvider` (commented limiter), `BypassThrottle::handle`, Laravel 10 `ThrottleRequests`. | RISK (INFERRED) |
| F-12 | LOW | `api` middleware group applied twice on `v1/general` ⇒ `EncryptCookies`/`AddQueuedCookiesToResponse` run twice (duplicate `Set-Cookie` possible). | `RouteServiceProvider` + in-file `Route::middleware(['api', …])`; documented in `EncryptCookies`. | VERIFIED |
| F-13 | LOW | No `Cache-Control: no-store` on identity-scoped guest responses. | No cache headers in guest controllers/middleware. | VERIFIED |
| F-14 | LOW | `attribute` payload is accepted (`sometimes|array`) but ignored for variants and unvalidated for simple products. | `CartCreateRequest`; `CartInventoryService::upsertItem`. | VERIFIED |
| F-15 | LOW | `config('guest_checkout.digital_products_allowed')` exists but is never read; the digital rule is hard-coded. | `config/guest_checkout.php` vs `GuestCartService::assertEligibleItem`. | VERIFIED |
| F-16 | INFO | Browser CSRF token is deterministic per identity and never rotates; revocation of the identity is the only kill switch. | `GuestIdentityService::csrfToken()`. | VERIFIED |
| F-17 | INFO | Cookie token length/format and HMAC pattern are duplicated in `GuestOrderTokenService` (same generator). | Both classes. | VERIFIED |

No cross-guest cart access was found: cart queries are scoped by `guest_id` derived from the credential, never from client input (**TESTED**: `guest_cannot_reach_another_guest_cart_through_ids`, `registered_users_cart_is_never_exposed_to_guest_endpoints`).

---

## 6. Product / variant eligibility audit

### 6.1 Conditions actually applied (in order)

Guest add (`GuestCartService::assertEligibleItem`):

1. `Product::query()->active()->find($id)` — requires `status=1` **and** (`in_stock=1` **or** `stock_quantity-reserved_quantity>0`) **and** (no categories **or** at least one `categories.status=1`).
2. `item_type !== DIGITAL`.
3. Not (`ChannelContext::isHome()` **and** `is_fast_shipping_available`).
4. `shipping_method` (uppercased) `=== SCHEDULED`.
5. `product_type === 'variable'` ⇒ `product_variant_id` required.
6. Only if `stock_quantity > 0`: `quantity <= stock_quantity - reserved_quantity`.

Then (`CartRepository::syncItems`): FAST eligibility (`is_fast_shipping_available`), variant belongs to product (`$product->variations()->whereKey()`), variable-without-variant, effective quantity ≤ `cart.max_item_quantity` (100), `operation` branch.

Authenticated add applies only step 1 + the `syncItems` set (no digital rule, no channel rule, no SCHEDULED-only rule).

### 6.2 Classification and required action

| # | Condition | Intentional? | Classification | Action |
|---|---|---|---|---|
| E-01 | `status=1` required | Yes | Business rule | Keep |
| E-02 | `in_stock` / availability gate in `active()` | Yes | Business rule | Keep, document |
| E-03 | Active-category gate | Yes (storefront lists via the same scope) | Business rule | Keep, document |
| E-04 | Digital rejected for guests | Yes — `config/guest_checkout.php` documents "physical products only (locked decision)"; `GuestCheckoutService` has no digital path | Intentional | Keep; document per client; remove or wire the dead config key (F-15) |
| E-05 | FAST rejected for guests | Yes — `GuestCheckoutService` explicitly rejects non-`SCHEDULED` lines (order builder consumes SCHEDULED only) | Intentional | Keep; align mobile (same rule) |
| E-06 | Fast product hidden on `home` channel | Yes — `HasChannelFilter` applies the same rule to storefront listings | Intentional | Keep; document the `X-Channel` requirement |
| E-07 | Variant mandatory for `variable` | Yes | Business rule | Keep; return a field error, not a generic message |
| E-08 | Variant must belong to the product | Yes (security/integrity) | **Implementation gap** (validated globally first) | Fix in `CartCreateRequest` (scoped `Rule::exists`) |
| E-09 | Variant stock | **No evidence of intent to skip**; merge path enforces it, checkout enforces it | **Inconsistency** | Decide D2 |
| E-10 | `stock_quantity = 0/NULL` ⇒ stock check skipped | Partly — `knownAvailableQuantity()` documents "unlimited/unknown stock semantics" | Intentional but undocumented; conflicts with `in_stock=false` gate | Keep; document explicitly |
| E-11 | Variant has no `status` column; `in_stock` unused at add | No | **Gap** | Fold into D2 |
| E-12 | `operation` unvalidated on create | No — update validates it | **Bug** | Fix (F-07) |
| E-13 | Client `price`/`total_price` ignored | Yes | Correct | Keep |
| E-14 | Client `attributes` for variable products ignored | Yes (derived from variant) | Correct but undocumented | Document |
| E-15 | Attributes unvalidated for simple products | Unclear | Contract gap | Decide D5 |
| E-16 | No stock reservation at cart time | Yes (order-time reservation, documented) | Correct | Keep |

### 6.3 Required test matrix (current outcome vs target)

| Scenario | Current outcome | Target | Delta |
|---|---|---|---|
| Active physical simple, in stock, scheduled | `201` | `201` | — |
| Active product + valid variant | `201` | `201` | — |
| Multiple valid variants | one line per variant | same | — |
| Variant of another product | `422 Invalid item data` | `422` with field error | F-06 |
| Nonexistent variant | `422` (field) | same | — |
| Inactive product | `422 product_unavailable` | same | — |
| Inactive variant | may be added | rejected or explicitly allowed per D2 | E-09/E-11 |
| Product insufficient stock | `422 insufficient_stock` (when stock>0) | same | — |
| Variant insufficient stock | added, later flagged | rejected per D2 | E-09 |
| Unlimited stock (0/NULL) | added | unchanged, documented | E-10 |
| Digital product (guest) | `422 digital_not_supported` | same (intentional) | — |
| Digital product (authenticated) | `201` | unchanged (parity decision D3) | — |
| Scheduled shipping, eligible product | `201` | `201` | — |
| Fast shipping (guest) | `422 fast_shipping_not_supported` | same (intentional) | — |
| Invalid shipping method | `422` (field) | same | — |
| Invalid operation | `201` + no change | `422` | F-07 |
| Quantity < 1 / > max | `422` | same | — |
| Invalid attributes | accepted/ignored | per D5 | E-15 |

All rows must be executed three ways: Browser cookie, Mobile Bearer, Authenticated cart (where applicable).

---

## 7. Security audit

| Area | Current state | Verdict |
|---|---|---|
| Identity token entropy | 32 random bytes → base64url (43 chars), HMAC-SHA256 with `APP_KEY` stored | PASS |
| Plaintext token at rest | Never (`token_hash` only) | PASS |
| Token in JSON | Cookie token never returned; `csrf_token` returned (not an auth credential) | PASS |
| Cookie flags | `HttpOnly` on identity, `Secure` on HTTPS, `SameSite` configurable, `Path=/api`, host-only by default | PASS |
| CSRF | Double-submit + server-derived HMAC; GET/HEAD/OPTIONS exempt; no-identity writes answered `401` before any state change | PASS |
| Missing Origin | Does **not** bypass CSRF (CSRF runs independently) | PASS |
| Origin as authentication | Never used as identity | PASS |
| CORS | Exact origins only when credentials enabled; wildcard stripped for guest routes; `Authorization` allowed | PASS |
| Credential ambiguity | N/A today (cookie only); **must be defined** once Bearer exists | PENDING DESIGN |
| Rate limiting | Guest limiter does **not** inherit `disable_rate_limiting`; fails closed; keyed by identity, else IP | PASS |
| Rate-limit bypass by identifier rotation | `guest_session` is IP-keyed only when no identity; 10/min per IP; **no IP ceiling** on session creation | RISK (LOW) → harden |
| Bearer on public routes | Does not exist | PENDING |
| Secrets in logs | App logging does not log headers; **Telescope does** (F-05) | RISK (HIGH) |
| Cross-guest access | Scoped by credential-derived `guest_id`; tested | PASS |
| Enumeration | Cart item 404 vs 403 behaviour tested; no cart id accepted from client | PASS |
| Response caching | No `no-store` | RISK (LOW) |

---

## 8. API contract gaps (current)

1. Mobile contract: nonexistent.
2. Error envelope inconsistency (F-09) and status-code inconsistency between guest/authenticated cart (F-10).
3. No machine-readable rejection reason (clients string-match localised text).
4. `422` from `CartCreateRequest` includes `errors`; business `422` does not.
5. Validation error for cross-product variant is not field-scoped (F-06).
6. `operation` accepted but meaningless on create (F-07).
7. No documented stock semantics for `0`/`NULL` (E-10).
8. No documented `X-Channel` requirement for guest adds (E-06).
9. Idempotency absent for cart writes; inconsistent with checkout/payment which require `Idempotency-Key` (F-04).

Backward-compatibility requirement: all existing browser contracts and tests must keep passing unchanged; any new field must be additive.

---

## 9. Proposed target architecture

### 9.1 One credential resolver, two transports

```
GuestIdentityService
  resolve(Request): ?GuestIdentity        (existing current(), extended)
    ├─ cookie token  → HMAC lookup → mode = cookie
    ├─ bearer token  → HMAC lookup in mobile_token_hash → mode = bearer
    ├─ both, same identity      → accept (mode = cookie, CSRF required)
    ├─ both, different identity → 401 credential_conflict (new)
    └─ none/invalid/expired/revoked → null
  authMode(Request): cookie|bearer|null   (request attribute, no public surface)
```

Rules:
- Only a token that verifies against a **guest** token hash counts as a guest credential. A Sanctum customer token in `Authorization` is ignored by the guest resolver → does not break login merge (`GuestCartMergeService` still resolves the cookie identity).
- Bearer mode ⇒ `GuestCsrf` skips CSRF **and only then**; cookie mode keeps the existing 419 contract.
- `GuestOrigin` is unchanged: validate when present, never require it (mobile sends none).
- Cancellation/conflict responses use a new localised key `guest_checkout.credential_conflict`.

### 9.2 Endpoints

Browser (unchanged):
- `POST /api/v1/general/guest/session`
- `GET|POST|PUT|DELETE /api/v1/general/guest/cart[...]`
- `POST /guest/checkout`, `GET|POST /guest/orders[...]`

Mobile (new, additive):
- `POST /api/v1/general/guest/mobile/session` → `201 {guest.expires_at, token, token_type:"Bearer", cart|null}`; sets **no** cookies; no CSRF; `guest.origin` + `guest_session` limiter.
- `POST /api/v1/general/guest/mobile/session/rotate` (Bearer) → `200 {token, expires_at}`; old token invalidated atomically.
- `DELETE /api/v1/general/guest/mobile/session` (Bearer) → `200`; identity `revoked_at` set, mobile token hash cleared, cookies cleared if any.

Cart/checkout/orders routes are **shared** (same URLs, same services); the credential decides the transport. No mobile-specific cart endpoints and no duplicated business logic.

Rationale for separate mobile routes: the browser session endpoint must never be able to emit a Bearer token by accident; mobile session needs its own rate-limit story; the contract stays explicit.

### 9.3 Storage

Migration (additive, nullable, no backfill):
- `guest_identities.mobile_token_hash` `char(64)` NULL, UNIQUE `uq_guest_identities_mobile_token` (MySQL/PostgreSQL allow many NULLs), index optional.

Avoided: a separate `guest_access_tokens` table (multi-device, per-token revocation history). Trade-off: one active mobile session per guest identity; a second device gets its own identity/cart (same as the browser today). Revisit only if multi-device-per-identity is a product requirement (D9).

### 9.4 Token policy

- Generation: 32 random bytes → base64url 43 chars (same generator/pattern as `GuestOrderTokenService`, format-validated before DB lookup).
- Storage: HMAC-SHA256 with `APP_KEY`, unique.
- TTL: same `guest_checkout.cookie.ttl_days` (7) and the same **mutation-only sliding extension** as the cookie (call `touch()` already does this; `extend()` will skip cookie queueing when there is no cookie).
- Rotation: explicit `rotate` endpoint (also recommended after app reinstall / forensic events). No silent rotation on refresh, because the raw token cannot be re-returned and rotate-on-every-call would race concurrent requests (decision D6).
- Revocation: `DELETE /mobile/session` (identity-level, mirrors browser kill switch); DB hash cleared.
- Logging: token never logged, never returned twice, never in Telescope (F-05 fix required before enabling).

### 9.5 Rate limiting

- Reuse `guest_session` (10/min) for mobile session creation; **add an IP ceiling** (`max × 3`) for `guest_session`, mirroring checkout/payment, so identity farming from one IP is bounded.
- Cart writes keep `guest_cart_write` (40/min) keyed by resolved identity ⇒ Bearer inherits protection with no client-supplied identifier.
- Invalid/absent token ⇒ IP-keyed ⇒ brute force is bounded.

### 9.6 Error contract (proposed, additive)

Keep all current shapes. Add only:
- `403` → `messages` unchanged.
- `401` → new `code: credential_conflict` when cookie and Bearer disagree.
- Cart business `422` → `data.reason ∈ {product_unavailable, digital_not_supported, fast_shipping_not_supported, variant_required, variant_unavailable, insufficient_stock, invalid_item_data, invalid_operation}`.
- Field-level `422` keeps `errors`.

No envelope rewrite (breaking change).

---

## 10. Implementation plan (after approval)

P0 — credential + security core
1. `app/Services/Guest/GuestIdentityService.php`: bearer resolution, auth-mode attribute, conflict detection, `createMobileIdentity`, `rotateMobileToken`, `revokeMobile`, `mobileTokenHash` helpers. Reuse existing generator/HMAC.
2. `app/Http/Middleware/GuestCsrf.php`: skip only when `authMode === 'bearer'`; keep 419 for cookie mode.
3. Migration: `mobile_token_hash` on `guest_identities` (+ unique index).
4. `app/Models/GuestIdentity.php`: fillable/casts/hidden update (`mobile_token_hash` hidden).
5. `app/Http/Controllers/Api/General/Guest/GuestMobileSessionController.php` (new): store/rotate/destroy.
6. `routes/api.php`: 3 mobile routes with `guest.origin`, `guest.throttle:guest_session`; no `guest.csrf`.
7. `resources/lang/en/guest_checkout.php` + `ar`: `credential_conflict`, `mobile_session_ready`, `mobile_session_revoked` (+ any new eligibility reasons).
8. `app/Providers/AppServiceProvider.php`: Telescope header redaction (`authorization`, `cookie`, `x-csrf-token`) or documented `TELESCOPE_ENABLED=false` for production.

P1 — eligibility defects
9. `packages/marvel/src/Http/Requests/CartCreateRequest.php`: scoped `Rule::exists('product_variants','id')->where(product_id)`; `operation` `sometimes|in:increment,decrement`.
10. `packages/marvel/src/Database/Repositories/CartRepository.php`: explicit failure for unknown `operation` (defense in depth; mirrors F-07).
11. `app/Services/Guest/GuestCartService.php`: variant eligibility/stock (D2), align semantics with `GuestCartMergeService::knownAvailableQuantity()`.
12. `app/Http/Controllers/Api/General/Guest/GuestCartController.php`: additive `data.reason` (D5).

P2 — hardening / consistency (optional, only if approved)
13. `app/Providers/RateLimitServiceProvider.php`: IP ceiling for `guest_session`.
14. `GuestOrigin` or a small middleware: `Cache-Control: no-store` on guest responses.
15. Fix or delete the dead `digital_products_allowed` config (F-15) — documentation-only unless the flag is meant to be live.
16. `throttle:public-api` limiter registration or remove the route middleware (F-11).

### Files expected to change (exhaustive)

| File | Change |
|---|---|
| `app/Services/Guest/GuestIdentityService.php` | Bearer resolution, mode, conflict, mobile token lifecycle |
| `app/Http/Middleware/GuestCsrf.php` | Auth-mode-aware CSRF exemption |
| `app/Http/Controllers/Api/General/Guest/GuestMobileSessionController.php` | **new** |
| `app/Models/GuestIdentity.php` | `mobile_token_hash` fillable/hidden |
| `database/migrations/<ts>_add_mobile_token_to_guest_identities.php` | **new** (additive) |
| `routes/api.php` | 3 mobile routes |
| `packages/marvel/src/Http/Requests/CartCreateRequest.php` | scoped variant rule, operation rule |
| `packages/marvel/src/Database/Repositories/CartRepository.php` | unknown-operation failure |
| `app/Services/Guest/GuestCartService.php` | variant eligibility/stock |
| `app/Http/Controllers/Api/General/Guest/GuestCartController.php` | additive `reason` |
| `app/Providers/AppServiceProvider.php` | Telescope redaction |
| `app/Providers/RateLimitServiceProvider.php` | `guest_session` IP ceiling |
| `resources/lang/{en,ar}/guest_checkout.php` | new keys |
| `tests/Unit/Guest/*`, `tests/Feature/Guest/*` | new suites |
| `docs/...` | contract update (see D7) |

Explicitly **not** changed: `CartRepository` business rules beyond F-07, `CartInventoryService`, `ProductPricingService`, checkout/payment flows, Marvel cart endpoints, customer Sanctum auth.

---

## 11. Test strategy

Levels (all new tests must be transport-parameterised where the behaviour is shared):

A. Unit
- token format/entropy, HMAC hashing, expiry, rotation, revocation, `current()` cookie/bearer/both/conflict/none, format rejection.
- CSRF policy: cookie mode requires, bearer mode exempts, GET exempt, missing-identity write still 401.
- eligibility: product status/active scope/category, digital, channel, shipping, variant ownership, variant stock, quantity bounds, operation.

B. Feature — Browser
Existing suites must stay green (no contract change). Add: revoke → 401, stale CSRF → 419, foreign Origin → 403, 41st write → 429, `operation` invalid → 422, cross-product variant → 422 field error.

C. Feature — Mobile
session issuance (no cookies, token once), add simple, add variant, read cart, missing/invalid/expired/revoked token → 401, no CSRF required, no Origin required, conflict (cookie + different bearer) → 401, rotation invalidates old token, delete revokes, rate limit.

D. Cross-client parity matrix
Same product/variant set through Browser, Mobile, Authenticated; assert `201/422` equality where policy is shared, and documented differences only (digital guest, fast shipping).

E. Regression
Full guest suite + `CartApiTest` + `GuestCartMergeTest` + checkout/payment guest suites.

Execution prerequisites (blocking):
- Isolated DB (`DB_DATABASE=catch_verify` or a dedicated `.env.testing`), seeded as the existing guest suite expects, because `phpunit.xml` defaults to `catch` and there is no `.env.testing`.
- Commands to run after approval: `php artisan migrate --pretend` (review), `vendor/bin/phpunit tests/Feature/Guest`, `vendor/bin/phpunit tests/Feature/CartApiTest.php`, then the full suite for regressions. Results reported verbatim with pass/fail counts.

---

## 12. Open decisions required before implementation

| ID | Decision | Recommendation |
|---|---|---|
| D1 | Bearer scope: cart only, or all guest endpoints (checkout, orders, payment, claim)? | **All** — same identity, same credential power, one resolver; document the widened surface. If restricted, needs per-route middleware and duplicated resolution logic. |
| D2 | Enforce variant stock/`in_stock` at add time? | **Yes** — matches `GuestCartMergeService` and checkout; fixes "added then broken". Checkout remains authoritative. |
| D3 | Keep guest-only digital / fast-shipping restrictions (vs authenticated)? | **Keep** — `GuestCheckoutService` cannot fulfil them; removing them would let guests build unpayable carts. Document per client. |
| D4 | Idempotency for cart writes (`Idempotency-Key`)? | **Defer**, documented as RISK; retries today double-increment. If adopted, additive optional header + replay cache keyed by identity+key for ~10 min. |
| D5 | Error standardization depth | **Additive only** (`data.reason`, `credential_conflict`). No envelope rewrite. |
| D6 | Mobile token rotation | **Explicit rotate endpoint**, sliding expiry on mutations, no silent rotation. |
| D7 | Documentation: may `docs/api/*` be updated? | Project rules require an explicit activation command. Please confirm "Update API documentation" is authorised for this task; otherwise the contract stays in `docs/investigations/`. |
| D8 | Add IP ceiling to `guest_session`? | **Yes** (LOW risk, prevents identity farming). |
| D9 | One mobile session per guest identity (column) vs multi-device table? | **Column** (minimal schema). Revisit only for a stated multi-device requirement. |

---

## 13. Approval gate

Nothing above has been implemented. On approval I will execute P0 → P1 incrementally, run the tests in §11 against an isolated database, report actual pass/fail counts, and produce the final evidence report (executive summary, findings register, architecture, contract, eligibility matrix, changed files, DB changes, test results, remaining risks, deployment checklist).

Known constraints that must be stated up front:
- **No test has been executed**, so no claim of "working" is made.
- `Telescope` header redaction (F-05) must ship with the mobile token, otherwise Bearer tokens land in the database.
- If D1 is "cart only", mobile checkout is impossible and the design needs a second phase.

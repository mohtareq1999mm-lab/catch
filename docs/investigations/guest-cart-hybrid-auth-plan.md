# Guest Cart — Web + Mobile Authentication: Approved Implementation Plan

Status: **PLAN ONLY — NOT IMPLEMENTED.**
No code, configuration, migration, test or database change has been made for this plan.
Companion audit (evidence, line-level findings, eligibility matrix): `docs/investigations/guest-cart-hybrid-auth-discovery.md`.

Approved by the product owner on the discovery deliverables, with these decisions:

| ID | Decision | Value |
|---|---|---|
| Gate | Implementation scope | **Do not change code — file the plan only** |
| D1 | Bearer credential scope | **All guest endpoints** (cart, checkout, orders, payment, claim) |
| D2 | Variant stock / `in_stock` at add time | **Enforce at add time** |
| D7 | Documentation location | **`docs/investigations/` only** — `docs/api/*` and `API_STORY` stay untouched |

Defaults adopted from the discovery document (unchanged by the owner):

| ID | Decision | Adopted value |
|---|---|---|
| D3 | Guest digital / fast-shipping restrictions | **Keep** (checkout cannot fulfil them) |
| D4 | Cart-write idempotency | **Defer**, documented as residual risk |
| D5 | Error standardization | **Additive only** (`data.reason`, `credential_conflict`) |
| D6 | Mobile token rotation | **Explicit rotate endpoint**; no silent rotation |
| D8 | IP ceiling on `guest_session` | **Yes** |
| D9 | Mobile sessions per identity | **One** (nullable column, no new table) |

### Round 1 + Round 2 amendments (Q1–Q7 + follow-ups, owner-confirmed)

These extend the baseline above and are equally binding:

| # | Decision | Value |
|---|---|---|
| A1 | Mobile credential lifetime (Q1) | Same 7-day inactivity window as browser; renewed **only** by add/update/remove/clear/checkout; reads never extend it |
| A2 | Expired mobile token (Q2) | Expired credential + new `store()` ⇒ **new identity, empty cart**; no server-side recovery of expired identities |
| A3 | Rotation policy (Q3 + Q4/R2) | Explicit and rare only (suspected theft, security reset; reinstall with a **live restored** credential). Rotation always requires the live Bearer token; a lost credential can only end in `store()` + new identity. No automatic cold-start rotation; app reuses its stored credential |
| A4 | `POST /guest/mobile/session` with a usable credential presented (R2-Q1) | **409 `mobile_session_exists`** — never creates a second identity, never idempotent-resume |
| A5 | Cold-start usability probe (R2-Q2) | `GET /guest/cart`: `200` ⇒ session usable; `401` ⇒ call `store()`. No dedicated session-status endpoint |
| A6 | Cart-write idempotency (Q4) | **Deferred** (matches D4); retried adds may double-increment; accepted residual risk, both transports |
| A7 | Multi-device (Q5) | One identity + one token per installation; login merge is the only unification point |
| A8 | Bearer scope (Q6) | All guest endpoints — cart, checkout, orders, payment initiation, access-tokens, claim |
| A9 | Guest restrictions on mobile (Q7) | Identical to web: no digital, no fast-shipping method, no fast-shipping products on the `home` channel |
| A10 | Machine-readable error codes (R2-Q3) | `credential_conflict` (401) and `mobile_session_exists` (409) emitted with a `code` key via a small additive change in `app/Exceptions/Handler.php` — **the only approved file addition outside §4** |
| A11 | ADRs | ADR-0001 (two transports, one identity, domain-separated hashes) and ADR-0002 (cart writes not idempotent) approved and recorded in `docs/adr/` |

---

## 1. Target behaviour (one paragraph)

The guest credential becomes a transport concern, not a business concern. A browser proves ownership with the existing `meem_guest` HttpOnly cookie plus `X-CSRF-Token`; a native app proves ownership with an opaque `Authorization: Bearer <token>`. Both resolve through `GuestIdentityService::current()` to the same `guest_identities` row, therefore to the same cart, the same eligibility rules, the same pricing, the same inventory semantics and the same response resource. CSRF applies only to the cookie transport; Origin is validated when present and never required; a request carrying both credentials that disagree is rejected instead of silently choosing one.

---

## 2. Contracts

### 2.1 New mobile endpoints (additive)

| Method | URL | Auth | Middleware |
|---|---|---|---|
| POST | `/api/v1/general/guest/mobile/session` | none | `guest.origin`, `guest.throttle:guest_session` |
| POST | `/api/v1/general/guest/mobile/session/rotate` | Bearer | `guest.origin`, `guest.throttle:guest_session` |
| DELETE | `/api/v1/general/guest/mobile/session` | Bearer | `guest.origin`, `guest.throttle:guest_session` |

`POST /mobile/session` — creates a fresh identity, returns the raw token exactly once, sets **no** cookies:

```json
{
  "status": 201,
  "message": "Guest session is ready.",
  "success": true,
  "data": {
    "guest": { "expires_at": "2026-11-23T10:00:00+00:00" },
    "token": "<43-char base64url>",
    "token_type": "Bearer",
    "cart": null
  }
}
```

`POST /mobile/session/rotate` — requires a valid Bearer token, atomically replaces the hash, returns the new token once; the old token is invalid immediately.

```json
{ "status": 200, "message": "Guest session has been rotated.", "success": true,
  "data": { "token": "<new>", "token_type": "Bearer", "expires_at": "…" } }
```

`DELETE /mobile/session` — sets `revoked_at` on the identity, clears `mobile_token_hash`, clears cookies if the request also carried them. Mirrors the browser kill switch.

```json
{ "status": 200, "message": "Guest session has been revoked.", "success": true }
```

### 2.2 Shared endpoints (unchanged URLs, credential-agnostic)

`GET /guest/cart`, `POST /guest/cart/items`, `PUT /guest/cart/items`, `DELETE /guest/cart/items/{itemId}`, `DELETE /guest/cart`, `POST /guest/checkout`, `GET /guest/orders/{orderId}`, `POST /guest/orders/{orderId}/payment`, `POST /guest/orders/{orderId}/access-tokens`.

Browser: `Cookie` + `X-CSRF-Token` (unchanged).
Mobile: `Authorization: Bearer <token>`, **no** `Origin`, **no** CSRF header.

Common optional headers for both: `Content-Type`, `Accept`, `X-Channel`, `X-Currency`, `lang`, `X-Request-ID`.

### 2.3 Error deltas (additive only)

| Status | When | New body key |
|---|---|---|
| `401` | cookie identity ≠ bearer identity | `code: "credential_conflict"`, localised message |
| `422` | cart business rejection | `data.reason` ∈ `product_unavailable, digital_not_supported, fast_shipping_not_supported, variant_required, variant_unavailable, insufficient_stock, invalid_item_data, invalid_operation` |
| `422` | invalid `operation` on create | field error under `errors["item.operation"]` |

All existing shapes (`ApiResponse`, `Handler`, `GuestCsrf` 419) remain byte-compatible.

---

## 3. Step-by-step plan

Each step lists: file → change → why → acceptance.

### P0 — credential core

**S1. `app/Services/Guest/GuestIdentityService.php`**

- Add `private const ATTR_MODE = '_guest_auth_mode';` and `public function authMode(Request $request): ?string` returning `'cookie' | 'bearer' | null`.
- Split resolution:
  - `fromCookie(Request)` — current behaviour (raw cookie → `token_hash` lookup → `revoked_at IS NULL` → `expires_at > now()`).
  - `fromBearer(Request)` — `$request->bearerToken()`, format-gated by the existing pattern (`/^[A-Za-z0-9_-]{20,120}$/`), then lookup by `mobile_token_hash`, same expiry/revocation predicates.
- `current()` becomes:

```php
$cookie = $this->fromCookie($request);
$bearer = $this->fromBearer($request);

if ($cookie && $bearer && $cookie->id !== $bearer->id) {
    throw new HttpException(401, __('guest_checkout.credential_conflict'));
}

$identity = $cookie ?? $bearer;
$request->attributes->set(self::ATTR_MODE, $bearer && !$cookie ? 'bearer' : ($identity ? 'cookie' : null));
$request->attributes->set(self::ATTR_IDENTITY, $identity);

return $identity;
```

- Add lifecycle methods (all reuse the existing generator and `hashToken()`):
  - `createForMobile(Request): array{0: GuestIdentity, 1: string}` — create identity (no cookie queue), generate token, store `mobile_token_hash`.
  - `rotateMobileToken(Request, GuestIdentity): string` — regenerate, overwrite hash, extend `expires_at`.
  - `revokeMobile(GuestIdentity): void` — `revoked_at = now()`, `mobile_token_hash = null`.
  - `isValidTokenFormat(?string): bool`.
- `extend()` already queues cookies only when a raw cookie exists → Bearer requests extend the identity without emitting cookies (no change needed; verify in tests).

Acceptance: cookie flow byte-identical; Bearer resolves the same model; conflict → 401; a Sanctum customer token is **not** accepted as a guest credential (it does not match a mobile hash) and does not break login merge.

**S2. `app/Http/Middleware/GuestCsrf.php`**

- After the `GET/HEAD/OPTIONS` early return and after `GuestOrigin::assertAllowed($request)`:

```php
if ($this->identities->authMode($request) === 'bearer') {
    return $next($request);
}
```

Acceptance: Bearer writes need no CSRF; cookie writes still return `419` on mismatch; missing origin still validated when present; no-identity write still yields `401` from the controller.

**S3. Migration — `database/migrations/<timestamp>_add_mobile_token_to_guest_identities.php`**

```php
Schema::table('guest_identities', function (Blueprint $table) {
    $table->char('mobile_token_hash', 64)->nullable()->after('token_hash');
    $table->unique('mobile_token_hash', 'uq_guest_identities_mobile_token');
});
```

`down()`: drop unique index then column. Additive, nullable, no backfill, MySQL/PostgreSQL-safe (multiple NULLs allowed).

**S4. `app/Models/GuestIdentity.php`** — add `mobile_token_hash` to `$fillable` and `$hidden`. No cast needed.

**S5. `app/Http/Controllers/Api/General/Guest/GuestMobileSessionController.php`** (new)

- `store()`: if a usable credential (either transport) is already presented and resolves to an identity ⇒ **409 `mobile_session_exists`** (A4); otherwise create identity + token; return 201; never set cookies; response `Cache-Control: no-store`.
- `rotate()`: require a Bearer-resolved identity (401 otherwise), rotate, return new token; the old token is invalid immediately (A3).
- `destroy()`: require Bearer identity, revoke, clear cookies if present, `200`.
- Token value appears only in `store`/`rotate` responses; never logged.
- Cold-start probe is NOT provided by these endpoints: clients use `GET /guest/cart` (A5).

**S6. `routes/api.php`** — add the three routes inside the existing `guest` prefix group, immediately after the browser session routes, with the middleware from §2.1 and names `api.guest.mobile.session`, `api.guest.mobile.session.rotate`, `api.guest.mobile.session.revoke`. No `guest.csrf` on any of them.

**S7. `resources/lang/en/guest_checkout.php` + `ar`**

Add: `credential_conflict`, `mobile_session_ready`, `mobile_session_rotated`, `mobile_session_revoked`, `variant_unavailable`, `invalid_operation`.

**S8. `app/Providers/AppServiceProvider.php`** — Telescope redaction (must ship with S1):

```php
if (class_exists(\Laravel\Telescope\Telescope::class)) {
    \Laravel\Telescope\Telescope::hideRequestHeaders(['authorization', 'cookie', 'x-csrf-token']);
    \Laravel\Telescope\Telescope::hideResponseHeaders(['set-cookie']);
}
```

Acceptance: `telescope_entries` never contains a Bearer token or cookie value.

### P1 — eligibility defects

**S9. `packages/marvel/src/Http/Requests/CartCreateRequest.php`**

```php
'item.product_variant_id' => [
    'sometimes', 'nullable', 'integer',
    Rule::exists('product_variants', 'id')->where(
        fn ($q) => $q->where('product_id', (int) $this->input('item.product_id'))
    ),
],
'item.operation' => ['sometimes', 'string', Rule::in(CartOperation::getValues())],
```

Rationale: F-06 (global existence → generic repository error) and F-07 (silent no-op). `sometimes` keeps existing clients that omit `operation` valid. `CartUpdateRequest` already enforces this; the two now agree.

**S10. `packages/marvel/src/Database/Repositories/CartRepository.php`** — `syncItems()`: add an explicit failure for an unknown operation (defence in depth, covers any non-HTTP caller).

```php
} else {
    throw new Exception(INVALID_ITEM_DATA);
}
```

**S11. `app/Services/Guest/GuestCartService.php`** — `assertEligibleItem()` (D2, adopt the merge service's stricter variant semantics):

```php
if (!empty($item['product_variant_id'])) {
    $variant = $product->variations()->whereKey((int) $item['product_variant_id'])->first();

    if (!$variant) {
        throw new \InvalidArgumentException(__('guest_checkout.variant_unavailable'));
    }

    $variantStock = (int) ($variant->stock_quantity ?? 0);
    $variantAvailable = max(0, $variantStock - (int) ($variant->reserved_quantity ?? 0));

    if ($variantStock > 0 && $quantity > $variantAvailable) {
        throw new \InvalidArgumentException(__('guest_checkout.insufficient_stock'));
    }

    if ($variantStock > 0 && !$variant->in_stock && $variantAvailable <= 0) {
        throw new \InvalidArgumentException(__('guest_checkout.variant_unavailable'));
    }
}
```

Keep the existing product-level check. `0/NULL` keeps "unlimited/unknown" semantics (documented).

**S12. `app/Http/Controllers/Api/General/Guest/GuestCartController.php`** — attach `data.reason` to business `422`s (introduce a small `GuestCartRejection` exception carrying `(reason, message)` or pass reason explicitly); `store` and `update` only. Do not change messages or statuses.

### P2 — hardening (approved defaults, optional ordering)

**S13. `app/Providers/RateLimitServiceProvider.php`** — add `guest_session` to the IP-ceiling list (`Limit::perMinutes($decay, $max * 3)->by('ip:…')`).

**S14. Guest response cache policy** — `Cache-Control: no-store` on guest responses (in `GuestOrigin::handle` after `$next($request)`, or a dedicated small middleware).

**S15. `config/guest_checkout.php` / `GuestCartService`** — resolve the dead `digital_products_allowed` key: either wire it or delete it. Documentation-only change if the rule stays hard-coded.
  - **RESOLVED (T15, evidence-based):** `config('guest_checkout.digital_products_allowed')` is **never read** anywhere in `app/` or `packages/` (verified by search: only its definition at `config/guest_checkout.php` and a redundant test `Config::set` reference it). The digital restriction is deliberately hard-coded in three enforcement points: `GuestCartService` (add-time rejection), `GuestCartMergeService` (login merge), and `GuestCheckoutService` (defense-in-depth at checkout). Outcome per D3: **keep the hard-coded behaviour; the key stays in config as dead, documented here**. Wiring it now would add a live toggle for a restriction the approved baseline keeps absolute — no code change made.

**S16. `throttle:public-api`** (F-11, pre-existing) — register the limiter in `AppServiceProvider` or remove the route middleware. Out of the guest-cart scope; listed so it is not forgotten.

---

## 4. Files expected to change (exhaustive, nothing else)

| File | Step | Type |
|---|---|---|
| `app/Services/Guest/GuestIdentityService.php` | S1 | modify |
| `app/Http/Middleware/GuestCsrf.php` | S2 | modify |
| `database/migrations/*_add_mobile_token_to_guest_identities.php` | S3 | new |
| `app/Models/GuestIdentity.php` | S4 | modify |
| `app/Http/Controllers/Api/General/Guest/GuestMobileSessionController.php` | S5 | new |
| `routes/api.php` | S6 | modify |
| `resources/lang/en/guest_checkout.php`, `resources/lang/ar/guest_checkout.php` | S7 | modify |
| `app/Providers/AppServiceProvider.php` | S8 | modify |
| `app/Exceptions/Handler.php` | A10 | modify (approved out-of-list addition: additive `code` key for `credential_conflict` / `mobile_session_exists`) |
| `packages/marvel/src/Http/Requests/CartCreateRequest.php` | S9 | modify |
| `packages/marvel/src/Database/Repositories/CartRepository.php` | S10 | modify |
| `app/Services/Guest/GuestCartService.php` | S11 | modify |
| `app/Http/Controllers/Api/General/Guest/GuestCartController.php` | S12 | modify |
| `app/Providers/RateLimitServiceProvider.php` | S13 | modify |
| `tests/Unit/Guest/GuestIdentityBearerTest.php` (new) | tests | new |
| `tests/Unit/Guest/GuestEligibilityTest.php` (new) | tests | new |
| `tests/Feature/Guest/GuestMobileSessionTest.php` (new) | tests | new |
| `tests/Feature/Guest/GuestMobileCartTest.php` (new) | tests | new |
| `tests/Feature/Guest/GuestCredentialConflictTest.php` (new) | tests | new |
| `tests/Feature/Guest/GuestEligibilityParityTest.php` (new) | tests | new |

**Not changed**: `CartInventoryService`, `ProductPricingService`, `GuestCheckoutService`, `GuestPaymentService`, `GuestOrderTokenService`, `GuestCartMergeService`, checkout/order resources, Marvel cart endpoints, Sanctum customer auth, CORS config, cookie config.

---

## 5. Test plan (execution deferred until an isolated DB exists)

> **RESOLVED (owner-confirmed):** tests are authorized against the `catch` database (root, empty password) **with these conditions**:
> 1. Verify the active connection targets `catch` before execution.
> 2. Review the exact migration contents before running it.
> 3. Run **only** `php artisan migrate --path=database/migrations/2026_11_16_000001_add_mobile_token_to_guest_identities.php --force` — never the full migration set.
> 4. Do not modify or drop existing columns or data.
> 5. Verify the new nullable column and unique index after execution.
> 6. Report migration result and test results separately.
> 7. Do not automatically roll back the migration.
>
> This authorization covers the test prerequisite and test execution only; it is not authorization to implement the feature or to deploy.

Commands (after approval + DB):

```
php artisan migrate --pretend
vendor/bin/phpunit tests/Unit/Guest
vendor/bin/phpunit tests/Feature/Guest
vendor/bin/phpunit tests/Feature/CartApiTest.php
vendor/bin/phpunit tests/Feature
```

### Unit

- token format/length, HMAC hashing, hash-only persistence.
- resolution: cookie only, bearer only, both same, both different (conflict), none, malformed, expired, revoked.
- `authMode()` values.
- CSRF policy: cookie requires, bearer exempt, GET exempt.
- eligibility: active scope, digital, channel, shipping, variant ownership, variant stock, `in_stock`, quantity bounds, operation.

### Feature — Browser (regression)

All existing guest tests must pass unchanged, plus: revoked → 401, stale CSRF → 419, foreign Origin → 403, 41st write → 429, invalid `operation` → 422, cross-product variant → 422 field error.

### Feature — Mobile

- session: 201, token returned once, no cookies, hash stored, raw never stored.
- add simple / add valid variant / add second variant / read cart.
- 401: missing, malformed, expired, revoked, rotated-out token.
- no CSRF header required; no `Origin` required.
- conflict: cookie A + bearer B → 401 `credential_conflict`; cookie A + bearer A → success.
- rotation: old token 401, new token 200.
- delete: 200, subsequent use 401, cart unreachable.
- rate limiting: session 10/min; writes 40/min; invalid tokens keyed by IP.
- Sanctum customer token is not accepted as a guest credential.

### Cross-client parity

One table-driven test that pushes the same product/variant set through browser cookie, mobile bearer and authenticated Sanctum, asserting identical outcomes where policy is shared and explicitly asserting the documented differences (digital and fast shipping for guests).

### Evidence rules

Report the exact command, pass/fail counts, and classify any failure as new regression / pre-existing / environment / test-data. No test result may be claimed without execution.

---

## 6. Acceptance criteria (mapped to the 20 non-negotiables)

| # | Criterion | Covered by |
|---|---|---|
| 1 | Browser cookie + CSRF works | S2 regression, existing suite |
| 2 | Origin validated when present | unchanged `GuestOrigin` |
| 3 | Missing Origin does not bypass CSRF | S2 (mode-aware, cookie path unchanged) |
| 4 | Mobile Bearer works | S1, S5, S6 |
| 5 | Mobile needs no Origin/CSRF | S2, tests |
| 6 | Same identity/cart logic | S1 single resolver |
| 7 | No cross-guest access | existing scoping + conflict test |
| 8–11 | Eligibility incl. variants and stock | S9–S11 + parity matrix |
| 12 | Server-side pricing | unchanged |
| 13 | Shipping/channel restrictions intentional | documented, unchanged |
| 14 | No silent success on invalid operation | S9, S10 |
| 15 | Concurrency safety | row locks unchanged + concurrent test |
| 16 | Login merge intact | `GuestCartMergeService` untouched + regression test |
| 17 | Tests executed and reported | §5 |
| 18 | No hidden regression | full suite + classification |
| 19 | No open critical/high security issue | S8 Telescope redaction + audit |
| 20 | Unverified items disclosed | §7 |

---

## 7. Residual risks and unknowns

| ID | Risk / unknown | Handling |
|---|---|---|
| R-01 | Cart writes are not idempotent (D4 deferred). A retried add increments twice. | Document; optional `Idempotency-Key` later; limiter is the only backpressure. |
| R-02 | Bearer widens mobile access to checkout/payment (D1). | Accepted explicitly; same credential power as the cookie; documented in the contract. |
| R-03 | One mobile token per identity (D9). Rotating on a second device invalidates the first. | Documented; each install creates its own identity. |
| R-04 | `0`/`NULL` stock = unlimited/unknown is undocumented in the client contract. | Document; revisit if the business wants strict stock. |
| R-05 | Telescope redaction (S8) must ship **with** S1, otherwise tokens reach the DB. | Treated as part of P0, not optional. |
| R-06 | Test DB isolation not available today (no `.env.testing`). | Blocking prerequisite for §5; until then every test claim stays UNVERIFIED. |
| R-07 | `throttle:public-api` 500-if-enabled (F-11/pre-existing). | Out of scope; listed for a separate decision. |
| R-08 | Guest vs authenticated eligibility divergence for digital/fast is intentional but invisible to clients. | Document per client; no rule removal. |
| R-09 | No runtime/network evidence exists for the originally reported "some products fail" symptom. | Backend causes are code-verified; the per-product confirmation still needs a request capture. |

---

## 8. Rollout and rollback (for the future implementation)

Order:
1. Ship S8 (Telescope redaction) and S3 (migration) first — both are inert.
2. Ship S1/S2/S4 (resolver + CSRF mode) — no behaviour change for cookie clients.
3. Ship S5/S6/S7 (mobile endpoints).
4. Ship S9–S12 (eligibility fixes) and verify the browser regression suite.
5. Deploy the app; mobile clients adopt `/guest/mobile/session` afterwards.

Rollback: revert files; `php artisan migrate:rollback --step=1` drops `mobile_token_hash` only. No data migration, no cart data touched, no contract break for existing browser clients.

Deployment checklist: `php artisan migrate --force` (after `--pretend` review), shared Redis cache for guest throttling, HTTPS end-to-end (token never over plaintext), `TELESCOPE_ENABLED` policy confirmed for production, scheduler unchanged.

---

## 9. What happens next

This document is the approval artifact. No file outside `docs/investigations/` was created or modified. Implementation begins only on an explicit instruction such as "implement the approved plan", at which point the steps in §3 run incrementally with the tests in §5 and the final evidence report (executive summary, findings register, architecture, contract, eligibility matrix, changed files, DB changes, executed test results, remaining risks, deployment checklist).

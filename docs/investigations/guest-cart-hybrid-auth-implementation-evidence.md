# Guest Cart — Web + Mobile Hybrid Authentication: Implementation Evidence

Status: **implemented + tested on the isolated `catch_verify` database.** NOT deployed (no migration on `catch` or production, nothing pushed). All approved decisions D1–D9, Q1–Q7, A1–A11 and ADR-0001/0002 are honoured.

Companion documents: `docs/investigations/guest-cart-hybrid-auth-discovery.md`, `docs/investigations/guest-cart-hybrid-auth-plan.md`, `docs/adr/0001-one-guest-identity-two-credential-transports.md`, `docs/adr/0002-cart-writes-not-idempotent.md`.

## 1. Executive summary

One **Guest Identity** now accepts two credential transports: the existing HttpOnly cookie (browser) and an opaque `Authorization: Bearer` token (native mobile). Both resolve through `GuestIdentityService::current()` to the same identity row, so cart, checkout, orders and payment business rules are shared — no second cart system, no duplicated logic.

Mobile credential lifecycle: `POST /guest/mobile/session` (201, token returned once, no cookies), `POST …/rotate` (explicit rotation, old token dies immediately), `DELETE …` (revocation). A usable credential presented to `store()` yields **409 `mobile_session_exists`** (never idempotent-resume). Conflicting cookie + Bearer identities yield **401 `credential_conflict`**. Both errors carry a machine-readable `code` key (additive, Handler).

## 2. Implemented vs deferred

Implemented (T1–T21):

- Dual-transport resolution, auth-mode tracking, domain-separated hashes (ADR-0001)
- Bearer CSRF exemption; cookie CSRF (419) and Origin (403) unchanged
- Mobile session endpoints + routes (no `guest.csrf`, `guest.origin` + `guest.throttle:guest_session`)
- Additive `mobile_token_hash` column (nullable CHAR(64) + unique index), reversible
- Localized keys (en/ar): `credential_conflict`, `mobile_session_ready`, `mobile_session_rotated`, `mobile_session_revoked`, `mobile_session_exists`, `variant_unavailable`, `invalid_operation`
- Telescope redaction guard (self-activates only if Telescope is installed; it is not)
- Variant ownership + stock + `in_stock` enforcement at add time (D2), preserving product-level 0/NULL unlimited semantics
- Additive `data.reason` on business 422s (S12 closed reason set)
- `guest_session` IP ceiling (D8) with a namespaced ceiling key (see §9 finding)
- `Cache-Control: no-store` on guest responses
- `digital_products_allowed` resolved as documented dead config (D3 unchanged)

Deferred by decision: cart-write idempotency (D4/ADR-0002); server-side recovery of expired identities; automatic rotation; multi-device shared identities; mobile-specific eligibility relaxations.

## 3. Authentication flow

```
Web:    browser ──cookie(meem_guest)──► GuestCsrf(cookie) ─► current() ─► GuestIdentity
Mobile: app ──Authorization: Bearer──► (no CSRF)          ─► current() ─► GuestIdentity (same row)
        both present & different ids ─► 401 credential_conflict
```

Cold-start probe (A5): `GET /guest/cart` → 200 ⇒ reuse token; 401 ⇒ `POST /guest/mobile/session`.

## 4. Endpoints

| Method | Path | Notes |
|---|---|---|
| POST | `/api/v1/general/guest/mobile/session` | 201 + token once; no cookies; `no-store`; 409 if usable credential presented |
| POST | `/api/v1/general/guest/mobile/session/rotate` | Requires live Bearer; old token invalid immediately |
| DELETE | `/api/v1/general/guest/mobile/session` | Requires Bearer; revokes identity; clears cookies when present |

All other guest endpoints accept either transport unchanged.

## 5. Database change + migration evidence

Migration: `database/migrations/2026_11_16_000001_add_mobile_token_to_guest_identities.php` — nullable `char(64)` `mobile_token_hash` + unique `uq_guest_identities_mobile_token`, guarded, reversible.

Executed on the isolated test DB only:

```
$env:DB_DATABASE='catch_verify'; php artisan migrate --force
→ 2026_11_16_000001_add_mobile_token_to_guest_identities ......... DONE (409ms)
verified: column=char(64) null=YES ; unique-index=YES non_unique=0
```

`catch` (dev) and production were **not** migrated. `catch` still lacks the base guest schema (`2026_11_11_000001_create_guest_checkout_schema` never ran there), so the additive migration cannot run there until that base set is applied — a deployment decision, not a code defect.

## 6. Security

- Hash-only storage: `hash_hmac('sha256', …)` keyed with `app.key`; mobile domain-separated (`guest-mobile|` prefix) so tokens cannot cross transports (ADR-0001).
- Bearer shape gate before any DB lookup — Sanctum customer tokens (`id|…`) are never guest credentials.
- 401 `credential_conflict` on mixed identities; never silent picking.
- Rate limit: per-identity limits preserved; `guest_session` gains an IP ceiling (max × 3) with a distinct key namespace.
- `Cache-Control: no-store` on every guest response.
- Telescope redaction guard for `authorization`, `cookie`, `x-csrf-token`, `set-cookie` (only active if Telescope exists).
- No raw token in JSON after issuance, in logs, or in serialization (`mobile_token_hash` in `$hidden`).

## 7. Eligibility matrix (add time)

| Rule | Behaviour | Reason code |
|---|---|---|
| Product active | reject | `product_unavailable` |
| Digital product | reject | `digital_not_supported` |
| Fast-shipping product on home channel | reject | `product_unavailable` |
| Shipping method ≠ scheduled | reject | `fast_shipping_not_supported` |
| Variable product without variant | reject | `variant_required` |
| Variant from another product | reject (422 field error at HTTP, S9; service reason) | `variant_unavailable` |
| Variant `in_stock = false` (D2) | reject | `variant_unavailable` |
| Quantity > variant `stock − reserved` | reject | `insufficient_stock` |
| Quantity > product `stock − reserved` (stock > 0) | reject | `insufficient_stock` |
| Product stock 0/NULL | allowed (unlimited/unknown preserved) | — |
| Unknown operation (service/repository) | reject | `invalid_operation` |

## 8. Test execution evidence

Environment: local MySQL `catch_verify` (isolated; `catch` untouched), `CACHE_DRIVER=array` for the suite (Redis used explicitly where relevant), DB migration applied as above.

| Command | Result |
|---|---|
| `php artisan test tests/Unit/Guest` | **21 passed** (36 assertions) |
| `php artisan test tests/Feature/Guest/GuestMobileSessionTest.php` | **8 passed** (55 assertions) |
| `php artisan test tests/Feature/Guest/GuestMobileCartTest.php` | **7 passed** (33 assertions) |
| `php artisan test tests/Feature/Guest/GuestCredentialConflictTest.php` | **3 passed** (22 assertions) |
| `php artisan test tests/Feature/Guest/GuestEligibilityParityTest.php` | **6 passed** (53 assertions) |
| `php artisan test tests/Feature/Guest` (full guest directory incl. pre-existing browser suites) | **106 passed** (794 assertions) |
| `php artisan test tests/Feature/CartApiTest.php tests/Feature/CartExpirationTest.php tests/Feature/CartOrderLifecycleTest.php` | **80 passed** (335 assertions) |

Browser regression covered inside the above: session cookies, CSRF 419, foreign Origin 403, retention rules, digital/unavailable rejections, cross-guest isolation, revoked/expired tokens, CORS credential rules, limiter fail-closed.

Redis-specific check: `GuestEligibilityParityTest::test_rate_limiter_policy_holds_on_the_redis_store` (real Redis store, unique key, explicit cleanup) — **passed**.

Full-feature-suite run: **blocked by a pre-existing parse error** in `tests/Feature/BannerApiTest.php:612` (last touched by commit `72f7a3f`, unmodified here). A timed-out attempt to run the remainder via a temporary exclusion config produced no usable output; the full-suite step remains **unverified** and is not claimed as passing.

## 9. Findings raised during implementation

1. **Limiter key collision (fixed):** the IP ceiling used the same key string (`ip:<addr>`) as the anonymous per-key fallback, so both limits incremented one counter and silently halved budgets. Ceiling now uses `ceiling:ip:<addr>`. This also corrects the same latent collision for `guest_checkout`/`guest_payment`.
2. **`guest_identities` absent from `catch`:** the base guest schema was never migrated there; tests now run on the isolated `catch_verify`.
3. **Variant ownership at HTTP is a form-request error (S9), not a business reason** — intentional; the machine reason is service-level and covered by `GuestEligibilityTest`.
4. **Symfony appends `, private`** to explicit `Cache-Control: no-store`; tests assert `no-store, private`.
5. **Concurrent uncommitted changes** appeared outside this feature (`UserController.php`, `AuthenticationTest.php`, `database/seeders/AdminSeeder.php`, `docs/mobile/`) and were left untouched.

## 10. Known limitations / residual risks

- Cart writes are not idempotent (ADR-0002): a retried successful add increments twice, on both transports.
- Mobile cart is per installation; login merge is the only unification point.
- Rotation is explicit; a client rotating on cold-start would invalidate its own live token.
- No production-like load test; rate ceilings are policy-level verified.
- Telescope guard is latent (package absent) — cannot be runtime-verified here.

## 11. Deployment checklist (NOT performed)

1. Approve and run the base guest schema on the target DB: `2026_11_11_000001_create_guest_checkout_schema`, `2026_11_11_000003_create_guest_order_claim_tokens_table` (only if not already applied).
2. Run `php artisan migrate --path=database/migrations/2026_11_16_000001_add_mobile_token_to_guest_identities.php --force` on the target.
3. Verify column + unique index (`SHOW COLUMNS`/`SHOW INDEX`).
4. Deploy code; confirm `route:list` shows the three mobile session routes with `guest.origin` + `guest.throttle:guest_session`.
5. Confirm `Cache-Control: no-store` on guest responses and 409/401 `code` keys.
6. Publish the client contract note: cold-start probe via `GET /guest/cart`; rotate only on suspicion/reset.

## 12. Rollback

1. Code: revert the feature commits (see §13).
2. Schema: `php artisan migrate:rollback --path=database/migrations/2026_11_16_000001_add_mobile_token_to_guest_identities.php` — drops the unique index then the column; browser cookie credentials untouched.
3. No data migration occurred, so no data restore is required for this feature.

## 13. Changed files (feature commits on `main`, local only)

- `app/Services/Guest/GuestIdentityService.php` — dual transport, rotation, revocation
- `app/Models/GuestIdentity.php` — fillable/hidden
- `app/Http/Middleware/GuestCsrf.php` — Bearer exemption
- `app/Http/Middleware/GuestOrigin.php` — `no-store`
- `app/Http/Controllers/Api/General/Guest/GuestMobileSessionController.php` (new)
- `app/Http/Controllers/Api/General/Guest/GuestCartController.php` — `data.reason`
- `app/Exceptions/Handler.php` — additive `code` key
- `app/Exceptions/GuestCartRejectionException.php` (new)
- `app/Services/Guest/GuestCartService.php` — variant/stock/reason
- `app/Providers/AppServiceProvider.php` — Telescope redaction
- `app/Providers/RateLimitServiceProvider.php` — `guest_session` ceiling (+ key namespace fix)
- `routes/api.php` — mobile session routes; cart read `guest.origin`
- `resources/lang/{en,ar}/guest_checkout.php` — 7 keys (6 approved + A4 `mobile_session_exists`)
- `packages/marvel/src/Http/Requests/CartCreateRequest.php` — scoped variant + operation rules
- `packages/marvel/src/Database/Repositories/CartRepository.php` — unknown operation fails loudly
- `database/migrations/2026_11_16_000001_add_mobile_token_to_guest_identities.php` (new)
- `tests/Unit/Guest/GuestIdentityBearerTest.php`, `tests/Unit/Guest/GuestEligibilityTest.php` (new)
- `tests/Feature/Guest/GuestMobileSessionTest.php`, `GuestMobileCartTest.php`, `GuestCredentialConflictTest.php`, `GuestEligibilityParityTest.php` (new)

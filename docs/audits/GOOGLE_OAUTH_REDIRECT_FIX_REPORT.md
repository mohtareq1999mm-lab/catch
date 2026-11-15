# GOOGLE OAUTH — REDIRECT_URI FIX REPORT (Strict Route-Level Scope)

- **Date:** 2026-11-15
- **Scope mandate:** The four social routes and `SocialController`'s directly associated methods only.
- **Prior implementation note:** A broader implementation from an earlier mandate (OAuth state transactions, migrations, hashed code storage, middleware/provider changes) was **fully reverted to baseline** before this scoped fix — it was outside the new scope and was never fully verified. This report covers only the scoped correction.

---

## 1. Exact Root Cause

`packages/marvel/src/Http/Controllers/SocialController.php` → `callbackUrl()` (baseline L209–212):

```php
protected function callbackUrl(string $provider): string
{
    return url("api/v1/social/{$provider}/callback");
}
```

Laravel's `url()` resolves the **request host**, so the OAuth `redirect_uri` was nondeterministic:

| Initiation path | Generated `redirect_uri` (verified live before the fix) |
|---|---|
| Direct backend call | `https://catch.mohammedtareq.me/api/v1/social/google/callback` |
| Via frontend Vercel proxy (the deployed app flow) | `https://catch-beauty-ecommerce-v0-kappa.vercel.app/api/v1/social/google/callback` |

Google requires an **exact** match against the client's Authorized redirect URIs; whichever URI was generated was not (or not consistently) registered → `Error 400: redirect_uri_mismatch`. Meanwhile `GOOGLE_REDIRECT_URI` existed in `.env`/`.env.example` (wired into `config/services.php` → `services.google.redirect` via `ShopServiceProvider::register()` which replaces the app `services` config with the marvel package's) but was **never consumed** — dead configuration that misled operators.

## 2. Affected Routes / Methods

All four routes route their callback URL through `callbackUrl()`; only that helper changed behavior:

| Route | Method | Change |
|---|---|---|
| `GET /api/v1/social/{provider}` | `redirect()` | Behavior change only — now emits the configured canonical `redirect_uri` |
| `GET /api/v1/social/redirect` | `redirectFromQuery()` | None (delegates to `redirect()`) |
| `GET /api/v1/social/{provider}/callback` | `callback()` | Same canonical URI for token exchange + two diagnostic-log corrections |
| `POST /api/v1/social/exchange` | `exchange()` | None (no demonstrated defect) |
| Route declarations | — | None (ordering verified correct) |

## 3. Files Changed

| File | Change | Reason |
|---|---|---|
| `packages/marvel/src/Http/Controllers/SocialController.php` | `callbackUrl()` now returns `config("services.{$provider}.redirect")`; fails safely (500 / existing failure redirect) with actionable logs when unset or non-HTTPS in production. Plus: `$providerName` captured before the try (was assigned inside the try after the Socialite call — undefined on early exceptions); failure-log context key `exception` → `exception_class` (a string under Laravel's reserved `exception` context key breaks test exception diagnostics). | The defect itself + two directly demonstrated diagnostic defects in the same method |
| `tests/Feature/SocialLoginFlowTest.php` | `setUp()` gains the `services.google.*` config lines (3 lines) | The controller now (correctly) requires configured callback; regression suite otherwise unchanged (restored to baseline first) |
| `tests/Feature/SocialRoutesCallbackContractTest.php` | NEW — 13 tests covering the 10 mandated checks | Mandated verification |
| `config/database.php` | ADDITIVE test-only `sqlite` connection (default remains `mysql`) | **Disclosure / approval item** — no MySQL server is running on this machine (checked: no service, no Docker, no binaries; port 3306 refused), and the test guard refuses the shared `catch` database. Without a test connection the mandated test phase could not execute. Runtime code and the default suite are untouched. Remove this block if the same tests are run on a MySQL instance instead. |

**Confirmation of no unrelated changes:** `git status` shows exactly the four files above (plus the pre-existing untracked audit/report docs from earlier sessions, untouched).

## 4. Before / After Behavior

| Scenario | Before | After |
|---|---|---|
| Initiation via frontend proxy | `redirect_uri` = frontend domain (depends on entry host) | `redirect_uri` = **configured** `GOOGLE_REDIRECT_URI`, identical for every entry path |
| Initiation via backend domain | backend-domain `redirect_uri` | same configured value |
| Token exchange at callback | `redirect_uri` from request host of the callback request | **same configured value** → OAuth legs consistent |
| `GOOGLE_REDIRECT_URI` set to a URI registered in Google Console | mismatch error possible | matches → consent screen proceeds |
| `GOOGLE_REDIRECT_URI` unset | silently generated request-host URL (masked the defect) | fails safely: initiation → 500 JSON `{success:false, message:"Social login failed, please try again."}`; callback → existing `302 /auth?error=social_login_failed` (mobile: 400 JSON); precise operator log |
| Production + `http://` callback | accepted silently | rejected with 500 + log (HTTPS guard) |
| Host-header manipulation | could change generated `redirect_uri` | proven impossible (test) |

Response contracts unchanged: initiation `{success, url}`; web callback `302 {frontend}/?code=`; mobile callback `{success, code}`; failure `302 {frontend}/auth?error=social_login_failed` / mobile 400 JSON; exchange `{success, message, token, token_type, user}`.

## 5. Tests Executed (actual results)

Runtime: SQLite (disclosed connection) + `CreatesTestTables` schema; Google/Socialite mocked; no real Google authentication.

```
php vendor/phpunit/phpunit tests/Feature/SocialRoutesCallbackContractTest.php
  → OK (16 tests, 63 assertions)          # final state, after review fixes

php vendor/phpunit/phpunit tests/Feature/SocialLoginFlowTest.php
  → OK (17 tests, 64 assertions)          # final state, after review fixes

php -l packages/marvel/src/Http/Controllers/SocialController.php
  → No syntax errors detected

php artisan route:list (social filter)
  → 4 routes resolve to the 4 methods; /social/redirect literal precedes /social/{provider}
```

Pre-review intermediate runs (13 tests / 55 assertions contract suite) also passed; the independent reviewer additionally executed both suites against **MySQL** (13+17 / 119 assertions green) before the post-review test additions — the post-review additions themselves were executed on SQLite only.

Covered checks (mandate §5): route resolution ×4, configured callback in URL, config-change propagation, host-header independence, trusted frontend redirect, invalid provider handling, exchange contract compatibility (claim/delete/replay), missing-config fail-safe, production HTTPS guard (initiation + callback paths), non-string misconfig fail-safe, facebook branch (its own `services.facebook.redirect`), initiation↔callback URI consistency (captured `redirectUrl()` argument).

## 6. External Google Cloud Console Action (operator)

Register under **Google Cloud Console → APIs & Services → Credentials → OAuth 2.0 Client `494344424703-39rali7ac6ntdnueivo3m11p93j3hf20…` → Authorized redirect URIs** (exact strings):

1. `https://catch-beauty-ecommerce-v0-kappa.vercel.app/api/v1/social/google/callback` ← matches the deployed frontend flow (canonical; **required**)
2. `https://catch.mohammedtareq.me/api/v1/social/google/callback` ← direct backend calls/tests (optional but recommended)

Then set in the deployment env (Render dashboard):

```env
GOOGLE_REDIRECT_URI=https://catch-beauty-ecommerce-v0-kappa.vercel.app/api/v1/social/google/callback
```

and refresh the config cache if one is used. **⚠️ Config-cache caveat — empirically verified (2026-11-15):**

| Runtime scenario | `services.google.redirect` | `services.fcm` / `services.resend` | App boot |
|---|---|---|---|
| No config cache (verified live) | env value ✓ | **erased (null)** | OK |
| Config cache + env vars from real process environment (Render dashboard style) | cached value survives ✓ | **erased (null)** | OK |
| Config cache + env vars from a `.env` file on the server | **null at runtime** (the overwrite re-evaluates `env()` while `LoadEnvironmentVariables` is skipped — vendor-verified early return) | **erased (null)** | **FATAL — Pusher TypeError** (the same overwrite also clobbers cached `broadcasting`; reproduced locally) |

Root defect (in `ShopServiceProvider::register()` L218–236, **outside this fix's authorized scope — STOP-and-report**):
1. It **replaces** (not merges) the entire `services` config at runtime with the marvel package's file → app-only keys `fcm` (consumed by `PushNotificationService` — `config('services.fcm.enabled')` is therefore always falsy) and `resend` (consumed by the `AppServiceProvider` mail transport fallback) are erased in every mode.
2. The runtime re-evaluation of `env()` nulls cached values for `.env`-file deployments and, for `broadcasting`, crashes all console/HTTP boots.

Evidence: local `php artisan tinker` run shows `config('services')` keys `= mailgun, postmark, ses, google, facebook, twilio, messagebird, myfatoorah` (no `fcm`/`resend`); `php artisan config:cache` then produced a cached `services` block containing google/facebook/messagebird but no fcm/resend; a tinker run with the cache present reproduced `Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given` (cache removed and workspace restored afterwards).

Current production state: **UNKNOWN whether a config cache exists on the instance** — but the live deployment demonstrably reaches Google with a valid `client_id` (impossible if `services.google.redirect` were nulled at boot), so production is either cache-free or uses process-environment vars. Operator check: `ls bootstrap/cache/config.php` on the deployed instance.

Proposed minimal correction (requires explicit approval — modifies `ShopServiceProvider.php`):
```php
'services' => array_merge(
    (array) config('services'),
    File::getRequire(__DIR__ . '/../config/services.php'),
),
```
Optionally, additionally guard the whole `config([...])` block with `if (! $this->app->configurationIsCached())` so cached values are trusted as baked. Required tests after either change: the two social suites (config keys survive), plus a boot-level test asserting `config('services.fcm')` is not null when `FCM_*` env vars are present.

## 7. Reported Items Requiring Approval (per mandate §2)

1. **`GOOGLE_FRONTEND_REDIRECT_URL` is not connected** to Laravel configuration. The frontend post-login redirect currently resolves from `shop.social_login.frontend_url` = `env('SOCIAL_LOGIN_FRONTEND_URL', env('SHOP_URL'))` (already env-driven, per-project). Smallest proposed change (ONE line, `packages/marvel/config/shop.php` + one helper call, on approval):
   ```php
   'google_frontend_url' => env('GOOGLE_FRONTEND_REDIRECT_URL'),
   ```
   consumed as `config('shop.social_login.google_frontend_url') ?: config('shop.social_login.frontend_url')`.
2. **Keep or remove the test-only sqlite connection** in `config/database.php` (see §3 disclosure). Keeping it enables isolated test runs on machines without MySQL; it changes nothing at runtime.

## 8. Post-Deployment Verification (operator)

1. `curl https://catch.mohammedtareq.me/api/v1/social/google` → `redirect_uri` equals the configured `GOOGLE_REDIRECT_URI` regardless of entry path.
2. Call the same endpoint via `https://catch-beauty-ecommerce-v0-kappa.vercel.app/api/v1/social/google` → identical `redirect_uri`.
3. Browser: click Google login → consent screen appears (no Error 400) → callback → land on `/?code=…` → session established → authenticated API call works → logout/revocation works.
4. Cancel at consent → land on `/auth?error=social_login_failed`.

## 9. Independent Review Findings & Resolution

Read-only independent review completed (git diff + source + vendor internals + empirical runs of both suites on **MySQL and SQLite**, all green).| Finding | Class | Resolution |
|---|---|---|
| No blockers / must-fixes | — | — |
| Config-cache trap: kernel `ShopServiceProvider` overwrite re-evaluates `services.php` post-cache (pre-existing debt, outside scope) | SHOULD-FIX | Documented above as deployment caveat + approval item; empirically the live deployment is not in this state (the reported mismatch required a valid client_id reaching Google, impossible under the overwrite) |
| Network-dependent test (real outbound POST to Google) | SHOULD-FIX | FIXED — callback-route test now mocks the provider |
| Non-string misconfig → "Array to string" notice | NICE-TO-HAVE | FIXED — `is_string()` guard + fail-safe + test |
| Callback-path HTTPS rejection untested | NICE-TO-HAVE | FIXED — test added (asserts existing failure redirect) |
| Facebook branch of `callbackUrl()` untested | NICE-TO-HAVE | FIXED — test added (captures `redirectUrl()` argument) |
| `app.env` values other than `production` skip the HTTPS guard | NICE-TO-HAVE | Documented (default `APP_ENV` unset → production, conservative) |
| `.phpunit.cache/test-results` tracked in git | NICE-TO-HAVE | Pre-existing hygiene, out of scope (restored after each run) |
| Duplicate log on initiation fail-safe | NICE-TO-HAVE | Accepted (explicit operator log + framework report) |
| sqlite stanza reuses `DB_DATABASE` | NICE-TO-HAVE | Documented intent; disclosed in §3 |

**Review confirmation:** fix correct, minimal, in-scope; identical `redirect_uri` on both legs; no other consumers of `callbackUrl()` / `services.{provider}.redirect`; response contracts unchanged; user-facing messages and logs leak no secrets or config values.

## 10. Not Verified

- Production (Render) runtime facts: actual `GOOGLE_REDIRECT_URI` value, Google Console registered URIs, live config-cache state — operator actions.
- Real browser E2E (requires external configuration first — see §8).
- Facebook live flow (code path identical except config key; mocked test added).
- Full-suite regression beyond the two social suites (per the narrow mandate: "Do not run unrelated suites unless needed to detect a regression"; no other code references the changed method — verified by grep).


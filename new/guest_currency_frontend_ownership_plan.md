# Guest Currency — Frontend Ownership Plan

**Status:** PLAN ONLY — No code modified
**Date:** 2026-09-07 UTC
**Repository:** `meem` (Laravel 10, `app/Services/Currency/UserCurrencyPreferenceService.php:12`)
**Backend host (declared):** `meem.mohammedtareq.me` (`meem.mohammedtareq.me` cited by requester; `APP_URL` in repo is `http://localhost` / `http://localhost:8000` — see §7.1)
**Frontend hosts:** `localhost` (dev) + real frontend domain (unspecified / `SHOP_URL=` empty in `.env.example` and `.env`)

> **Read order:** Executive Summary (§1) → Current Architecture (§2-4) → Domain Analysis (§7) → Desired Architecture (§5-6) → All changes (§8-11) → Tests / Migration / Rollback (§13-15)

---

## 1. Executive Summary

### What you asked
Move `guest_currency` from **backend-owned HttpOnly encrypted cookie** (`Cookie::queue(Cookie::make(...))` in `UserCurrencyPreferenceService.php:57`) to **frontend-owned JS-readable cookie** (`guest_currency=EGP` set by browser JS, sent on every API request, validated server-side).

### What discovery proved
- Backend is the **sole creator/encryptor/deleter** today (`UserCurrencyPreferenceService.php:57`, `75`, `86`; `CurrencyController.php:38`; `UserController.php:493,587,1002`).
- Cookie is `guest_currency` (encrypted), `host-only`, `path=/`, `max-age=31557600` (525960 min), `httpOnly`, no explicit `Domain`/`SameSite`/`Secure` in code → Laravel defaults `httpOnly=true`, `sameSite=lax`, `secure=null`, encrypted via `EncryptCookies.php:7` (no except list).
- CORS is `allowed_origins=['*']`, `supports_credentials=false` (`config/cors.php:22,28`) — **incompatible** with cross-site credentialed cookie flows.
- Effective currency resolution is `user preference > guest cookie > catalog` **only when** `currency_selection_enabled=true` (`CurrencyService.php:114`), otherwise always catalog. `adoptGuestCurrencyOnLogin()` copies guest → `user_preferences` if user has no preference and clears guest cookie (`UserCurrencyPreferenceService.php:86`).
- No frontend repo in this codebase; `SHOP_URL`/`DASHBOARD_URL` are empty, `APP_URL=http://localhost` / `http://localhost:8000`. Real production frontend domain is **not declared in repo**.

### What makes the request non-trivial
`localhost → backend` and `frontend-domain → backend` **cannot share one cookie if the two origins are cross-site** (unrelated eTLD+1). A cookie whose `Domain=meem.mohammedtareq.me` is never sent to `vercel.app` and cannot be set by JS running on `vercel.app`. The plan must therefore be **environment-aware** and, for truly unrelated domains, recommend a **header fallback** rather than forcing an invalid `Domain`.

### Recommendation (one paragraph)
Keep `user_preferences` as-is. **Stop encrypting and stop HttpOnly** for `guest_currency`, make it JS-readable, and make the frontend the writer. Keep the backend as **reader + strict validator** (`isValidActiveCurrency()` + uppercase). Fix CORS to `supports_credentials=true` with an explicit origin allowlist and set `SameSite=Lax` (with `Secure` on HTTPS) but **do not set `Domain`** for host-only operation; for same-site subdomains (`*.meem.mohammedtareq.me`) use `Domain=.meem.mohammedtareq.me`. For cross-site unrelated frontend domains **do not attempt a shared cookie** — send `guest_currency` as a plain cookie **on the frontend domain plus** a lightweight `X-Currency: EGP` (or `Currency-Code` header) fallback that the backend prefers when the cookie is absent. That fallback is the only correct way to support `frontend-domain ≠ backend-domain` without violating browser cookie rules. Login adoption should **adopt if user has no preference but no longer delete the browser cookie via `Set-Cookie`** — instead let the frontend clear its own cookie on successful adoption signal, while the backend keeps a **no-op `forget` for backward-compat on host-only deployments**.

---

## 2. Current Architecture

### 2.1 Ownership table (who does what, today)

| Question | Answer | Evidence |
|----------|--------|----------|
| 1. Who creates? | Backend `UserCurrencyPreferenceService::setGuestCurrencyCode()` via `Cookie::queue(Cookie::make(...))` | `UserCurrencyPreferenceService.php:57` |
| 2. Who stores? | Browser cookie jar under backend host (`meem.mohammedtareq.me`, `host-only`) | `guest_currency=<encrypted> domain=meem.mohammedtareq.me path=/` per your observation |
| 3. Who encrypts? | `EncryptCookies` middleware (global on `api` group) — every cookie outside `except` is AES-256-CBC + HMAC | `app/Http/Middleware/EncryptCookies.php:7` empty `except`; `Kernel.php:22` `api` has `EncryptCookies` |
| 4. Who decrypts? | Same middleware on every response/request; `Request::cookie()` returns plaintext | Laravel `EncryptCookies` lifecycle |
| 5. Who reads? | Backend `getGuestCurrencyCode()` → `$request->cookie(name)` + `CurrencyService::getEffectiveCode()` | `UserCurrencyPreferenceService.php:44`; `CurrencyService.php:114` |
| 6. Who deletes? | Backend `clearGuestCurrencyCode()` → `Cookie::queue(Cookie::forget(name))` + `CurrencyService.php:119` on invalid | `UserCurrencyPreferenceService.php:75`; `CurrencyService.php:118` |
| 7. When created? | On every `POST /api/v1/general/currencies/select` (always), regardless of auth | `CurrencyController.php:44` |
| 8. When changed? | Same endpoint; no other writer | `CurrencyController.php:38` |
| 9. When sent to backend? | Browser automatically on every `api/*` request to backend host if cookie matches domain/path | Standard cookie behaviour |
| 10. What happens during login? | `adoptGuestCurrencyOnLogin()` reads guest cookie, validates active, copies to `user_preferences` if empty, then clears guest cookie | `UserCurrencyPreferenceService.php:86`; calls in `UserController.php:493,587,1002` |
| 11. If user already has saved preference? | No-op — `getUserPreference !== null` returns immediately | `UserCurrencyPreferenceService.php:94` |
| 12. For guests? | No `user_preferences` row; effective currency falls back to `guest_currency` if present+valid, else catalog | `CurrencyService.php:101` |
| 13. Invalid/inactive cookie? | `isValidActiveCurrency()` fails → `clearGuestCurrencyCode()` and/or `clearUserPreference()`, fallback to catalog | `CurrencyService.php:103,118` ; `UserCurrencyPreferenceService.php:99` |
| 14. Missing cookie? | `getGuestCurrencyCode()` returns `null` → fallback to catalog | `UserCurrencyPreferenceService.php:52` |

### 2.2 Flow diagram (current)

```
User selects EGP in UI
        │
        ▼
POST /api/v1/general/currencies/select  {currency_code: "KWD"}
        │
        ▼
CurrencyController::select()                        [app/Http/Controllers/Api/Currency/CurrencyController.php:32]
   1. auth('sanctum')->user() ?? auth()->user()
   2. if user: setUserPreference(KWD) → user_preferences
   3. setGuestCurrencyCode(KWD) → Cookie::queue(Cookie::make(
        name=guest_currency, value=KWD, minutes=525960, path=/,
        domain=null, secure=null, httpOnly=true, sameSite=lax (default)
      ))
   4. forgetEffectiveCode() + return CurrencyResource
        │
        ▼
Response: Set-Cookie: guest_currency=<AES encrypted>; Path=/; HttpOnly; Host-only; Max-Age=31557600
        │
        ▼
Browser (backend domain) stores encrypted HttpOnly cookie ──► JS cannot read (document.cookie excludes it)
        │
        ▼
Every later API request to meem.mohammedtareq.me:
  Cookie: guest_currency=<encrypted>
        │
        ▼
EncryptCookies decrypts → Request::cookies has guest_currency=KWD
        │
        ▼
CurrencyService::getEffectiveCode()                    [app/Services/Currency/CurrencyService.php:82]
  if !currency_selection_enabled → catalog (USD)
  else if userPreference exists+valid → that
  else if guestCode exists+valid → guest  (decrypted KWD)
  else → catalog
        │
        ▼
Products / Cart / Orders use effectiveCode for conversion & caching
        │
        ▼
On login — three hooks call adoptGuestCurrencyOnLogin():
  UserController::token()                              [:493]
  UserController::verifyLoginOtp()                     [:587]
  UserController::socialLogin()                        [:1002]
  adoptGuestCurrencyOnLogin():
    if table missing → return
    if user already has preference → return (no override)
    guestCode = getGuestCurrencyCode()
    if invalid/inactive → return
    setUserPreference(user, guestCode)
    clearGuestCurrencyCode() → Set-Cookie guest_currency=; Max-Age=0  (backend deletes its own host cookie)
```

### 2.3 Secondary readers/writers

- **Validation:** `SelectCurrencyRequest.php:10` — `currency_code` must exist in `currencies.code` with `is_active=true`.
- **Cache key:** `ProductController.php:152` — `md5(fullUrl + '|currency:' + effectiveCode)` — guest currency affects cache.
- **Tests:** `UserCurrencyPreferenceTest.php` asserts queuing/reading/clearing + login adoption + select endpoint behaviour.

---

## 3. Current Request/Response Flow (wire-level)

### 3.1 Select currency (guest, current)

```http
# Request
POST /api/v1/general/currencies/select HTTP/1.1
Host: meem.mohammedtareq.me
Content-Type: application/json
Cookie: (maybe guest_currency=<old encrypted> if previously set)

{"currency_code":"KWD"}

# Response
HTTP/1.1 200 OK
Set-Cookie: guest_currency=eyJpdiI6...; Path=/; HttpOnly; Max-Age=31557600
Content-Type: application/json

{"status":200,"message":"Currency updated successfully","success":true,"data":{...CurrencyResource KWD...}}
```

### 3.2 Subsequent product list (guest)

```http
GET /api/v1/general/products HTTP/1.1
Host: meem.mohammedtareq.me
Cookie: guest_currency=eyJpdiI6...

HTTP/1.1 200 OK
# CurrencyService resolved effectiveCode=KWD, prices converted 100 USD → 22.1 KWD, cached under key currency:KWD
```

### 3.3 Login (guest had KWD, user had no preference)

```http
POST /api/v1/token HTTP/1.1
Host: meem.mohammedtareq.me
Cookie: guest_currency=eyJpdiI6...(KWD)

{"email":"...","password":"..."}

HTTP/1.1 200 OK
Set-Cookie: guest_currency=; Path=/; Max-Age=0; HttpOnly   # backend cleared it
{"token":"5|abc...", ...}
# side-effect: user_preferences {user_id, currency_code=KWD} created
```

---

## 4. Repository Findings (Phase 1 — factual)

### 4.1 Every reference to requested symbols

| Symbol | Files (line) |
|--------|--------------|
| `guest_currency` | `UserCurrencyPreferenceService.php:14`, `config/currency.php:13`, `tests/Feature/Currency/UserCurrencyPreferenceTest.php:52,57,63,69,74,78,97,119,126,133,149,164,187,195`, `api-desc/currency/*` (5 files) |
| `UserCurrencyPreferenceService` | `UserCurrencyPreferenceService.php:12`, `CurrencyService.php:47,48,102,114,118`, `CurrencyController.php:11,38`, `UserController.php:40,493,587,1002`, `tests/Feature/Currency/*` (3 files) |
| `getGuestCurrencyCode` | `UserCurrencyPreferenceService.php:44`, `UserCurrencyPreferenceService.php:98` (adopt), `CurrencyService.php:114` |
| `setGuestCurrencyCode` | `UserCurrencyPreferenceService.php:57`, `CurrencyController.php:44` |
| `clearGuestCurrencyCode` | `UserCurrencyPreferenceService.php:75`, `UserCurrencyPreferenceService.php:106`, `CurrencyService.php:118` |
| `adoptGuestCurrencyOnLogin` | `UserCurrencyPreferenceService.php:86`, `UserController.php:493,587,1002` |
| `isValidActiveCurrency` | `UserCurrencyPreferenceService.php:108`, used in `CurrencyService.php:103,115` and `UserCurrencyPreferenceService.php:99` |
| `Cookie::make` / `Cookie::queue` / `Cookie::forget` | `UserCurrencyPreferenceService.php:65,66,83` |
| `currency.guest_cookie` (config) | `config/currency.php:13,14,15`, `UserCurrencyPreferenceService.php:69,70,122` |
| `user_preferences` / `UserPreference` | `app/Models/UserPreference.php:9`, `database/migrations/2026_08_11_000002_create_user_preferences_table.php:11`, `UserCurrencyPreferenceService.php:24,33,41,88`, `CurrencyService.php:101` |
| `currency_code` | `UserPreference.php:12`, migration `currency_code` string(3), `CurrencyService.php` many, `SelectCurrencyRequest.php:11`, `CurrencyController.php:34` |
| `withCredentials` / `credentials: include` | **0 matches** in repo (no frontend code; no axios config committed) |
| `EncryptCookies` / `CORS` / `SameSite` | `EncryptCookies.php:7`, `Kernel.php:22`, `config/cors.php:22,28`, `config/session.php:158,171,199` |

### 4.2 Currency selection endpoints (real, not invented)

| Method | URL | File | Auth | Behaviour |
|--------|-----|------|------|-----------|
| `GET` | `/api/v1/currencies` (admin) | `packages/marvel/src/Rest/Routes.php:186` | `auth:sanctum` + `view-currencies` | list, paginated |
| `POST` | `/api/v1/currencies` (admin) | same | `create-currency` | store |
| `PUT` | `/api/v1/currencies/{currency}` | same | `update-currency` | update |
| `DELETE` | `/api/v1/currencies/{currency}` | same | `delete-currency` | soft delete |
| `POST` | `/api/v1/currencies/{id}/set-base` | same | `set-base-currency` | set base |
| `POST` | `/api/v1/currencies/{id}/set-catalog` | same | `set-catalog-currency` | set catalog |
| `GET` | `/api/v1/currency-rates` / `POST` etc. | same | rate permissions | rates CRUD |
| **`GET`** | **`/api/v1/general/currencies`** | `routes/api.php:100` | **public, `throttle:public-api`** | active currencies, cached 4h |
| **`POST`** | **`/api/v1/general/currencies/select`** | `routes/api.php:101` | **public, `throttle:public-api`** | validates active code, sets `user_preferences` if authed **and always** queues `guest_currency` cookie |

### 4.3 Middleware / Auth / Guest flows

- `Kernel.php:29` `api` group: `throttle:api`, `EncryptCookies`, `AddQueuedCookiesToResponse`, `SubstituteBindings`, `ChannelMiddleware`, `CheckLangMiddleware` — every `api/*` response carries queued cookies.
- Auth: Sanctum personal tokens (`createToken('auth_token', [], now()->addWeek())` in `UserController.php:1005`). Login paths: `token()` (password), `verifyLoginOtp()` (OTP), `socialLogin()` (/social). All three call `adoptGuestCurrencyOnLogin`.
- Guest checkout: `app/Http/Controllers/Api/General/OrderController.php` + `CartService` — no direct currency cookie involvement beyond `getEffectiveCode()` for pricing.
- Product/pricing: `ProductController.php:152`, `ConvertsProductPrice.php:20` use `CurrencyService::getEffectiveCode()`. Caches are currency-aware.

### 4.4 Frontend state/store — none in this repo

- `resources/js` is empty / not a SPA (`api-desc/activity-log/frontend.md:5`). Frontend is separate repo (not inspected). No `axios`/`fetch` config found.
- `SHOP_URL=` and `DASHBOARD_URL=` are empty in both `.env.example` and local `.env`; `APP_URL=http://localhost` (`:8000` locally). Render `APP_URL` is dynamic `fromService host`.

### 4.5 CORS / Cookie / Session config (exact values)

```php
// config/cors.php:22-28
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => ['*'],               // ← permissive
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'supports_credentials' => false,           // ← incompatible with credentialed cookies

// config/session.php:158,171,199
'domain' => env('SESSION_DOMAIN', null),   // ← currently null in .env
'secure' => env('SESSION_SECURE_COOKIE'), // ← not set
'same_site' => 'lax',

// config/currency.php:13-15
'guest_cookie_name' => env('GUEST_CURRENCY_COOKIE', 'guest_currency'),
'guest_cookie_lifetime' => (int) env('GUEST_CURRENCY_COOKIE_LIFETIME', 525960),
'guest_cookie_path' => env('GUEST_CURRENCY_COOKIE_PATH', '/'),

// app/Http/Middleware/EncryptCookies.php:7
protected $except = [];  // ← guest_currency IS encrypted

// app/Services/Currency/UserCurrencyPreferenceService.php:57
Cookie::make(name, value, minutes, path) // ← secure / domain / httpOnly / sameSite not passed → defaults
```

### 4.6 Tests involving currency/cookies

- `tests/Feature/Currency/UserCurrencyPreferenceTest.php` (8 tests): queue/read/clear guest cookie, effective currency precedence, login adoption, select endpoint.
- `tests/Feature/Currency/CurrencySelectionEnabledTest.php` (9 tests): catalog fallback when selection disabled, enabled path uses guest cookie, settings toggle.
- `tests/Feature/Currency/CurrencyTestCase.php`: shared helpers `seedCurrencyData()` (USD/KWD/SAR), `createSettings()` with `currency_selection_enabled=true`.

---

## 5. Desired Architecture

### 5.1 Ownership model

| Cookie | Owner | Can JS read? | Encrypt? | Sender | Receiver | Authoritative? |
|--------|-------|--------------|----------|--------|----------|----------------|
| `guest_currency` (target) | **Frontend** (browser JS) | **Yes** | **No** (except list) | Frontend (Set-Cookie via JS or via header fallback) | Backend (reads `Cookie:` or `X-Currency` header) | Backend validates — frontend is **source of intent**, backend is **source of truth for validity** |
| `user_preferences` (row) | Backend | N/A (DB) | N/A | Backend | Backend | Persistent user preference; adopted once from guest at login |

### 5.2 Cookie format (target)

```
guest_currency=EGP
Path=/
Domain=(host-only — no Domain attribute, see §7)  OR  Domain=.meem.mohammedtareq.me (shared-subdomain)
Max-Age=31557600 (or 31536000 — 1 year, either is fine; keep 525960 min for BC)
SameSite=Lax              # allows top-level navigation + same-site fetches; None+Secure for cross-site
Secure=true on HTTPS, false on http://localhost
HttpOnly=false            # must be readable
Expires=...
# Value: plaintext uppercase 3-letter ISO 4217, e.g. EGP, USD, SAR, KWD
# No encryption, no signing — value is public and tamper-evident via server validation
```

### 5.3 Visibility, encryption, security

- **Plaintext 3-letter code** is appropriate. Encryption adds no confidentiality (value is public) and prevents JS read. Signing is optional (cheap integrity) but unnecessary because **server validation (`isValidActiveCurrency`) is authoritative** — any tampered value is simply rejected → fallback to catalog. If signing desired later, add `guest_currency_sig` HMAC without re-encrypting.
- **Data sensitivity:** None — currency preference is not PII, not a session token, not a payment secret.
- **XSS implication:** `HttpOnly=false` exposes cookie to XSS JS. But an attacker who can run JS can already read `localStorage` or DOM and can also set any cookie value. The damage of stealing `guest_currency` is **price display in wrong currency** — trivial. So `HttpOnly=false` is the correct trade-off. The **real XSS protection** remains CSP + escaping + sanitization (separate concern).
- **Cookie tampering:** Mitigated by server validation, not by encryption. Never trust raw value.

### 5.4 Domain / Path / SameSite / Secure / Credentials

| Attribute | Localhost | Same-site subdomains (`app.example.com` + `api.example.com`) | Cross-site unrelated (`vercel.app` + `meem.mohammedtareq.me`) |
|-----------|-----------|--------------------------------------------------------------|----------------------------------------------|
| `Domain` | omit (host-only `localhost` — do not set `Domain=localhost`; spec rejects it) | `Domain=.meem.mohammedtareq.me` — shared | **cannot share** — must keep cookie on frontend domain only + use `X-Currency` header |
| `Path` | `/` | `/` | `/` |
| `SameSite` | `Lax` (works across ports `localhost:3000` → `localhost:8000` which are same-site) | `Lax` (same-site, no cross-site needed) | `None` would be required for cross-site cookie, but **frontend JS cannot set `Domain=meem.mohammedtareq.me`** — so no cookie will ever be cross-site. Use header. |
| `Secure` | `false` (http) | `true` (https) — Lax+Secure is safe, None requires Secure | N/A for header path |
| `credentials` | `fetch(..., {credentials:'include'})` or `axios {withCredentials:true}` required for cookie to be sent; keep `withCredentials` on localhost even though same-site, for consistency | same | `withCredentials` **still required** for header-auth path if cookies (Sanctum) are used for auth, but **guest currency via header does not need cookie credentials** — just `Authorization: Bearer <token>` |
| CORS | `Access-Control-Allow-Origin: http://localhost:3000` (explicit), `Allow-Credentials: true` | `Allow-Origin: https://app.meem.mohammedtareq.me`, `Allow-Credentials: true` | Same — explicit origin, `Allow-Credentials: true`, and `allowed_headers` must include `X-Currency` |

### 5.5 CORS requirements (explicit)

Current `config/cors.php` is **broken for credentialed flows**:

```php
'allowed_origins' => ['*'],          // ❌ combined with supports_credentials=false → cookies never sent
'supports_credentials' => false,     // ❌ must be true
```

When `supports_credentials=true`, browsers **reject** `Allow-Origin: *` — must list origins explicitly.

Must also expose the fallback header:

```
Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Currency, ...
Access-Control-Expose-Headers: (optional, not needed for request header)
```

### 5.6 Target flow diagram

```
                        FRONTEND (source of truth)                          
┌─────────────────────────────────────────────────────────┐
│ User selects USD → EGP in storefront                    │
│ Frontend JS:                                            │
│   document.cookie = "guest_currency=EGP; Path=/;        │
│     Max-Age=31557600; SameSite=Lax; Secure(optional)"   │
│   // or js-cookie: Cookies.set('guest_currency','EGP', )│
│   localStorage sync (optional)                          │
└────────────────────┬────────────────────────────────────┘
                     │ Cookie jar (frontend domain OR shared subdomain)
                     ▼
Future API request ───┐
GET /api/v1/general/products   ──►  Browser adds:
Host: meem.mohammedtareq.me         Cookie: guest_currency=EGP   (if cookie domain matches API host)
                                    + Authorization: Bearer <token> (if authed)
                                    + X-Currency: EGP           (cross-site fallback, always set by frontend)
                     │
                     ▼
             Laravel backend ──► EncryptCookies ignores guest_currency (except)
                               → CurrencyService::getEffectiveCode()
                                   reads cookie OR header (validation → see §11)
                               → converts prices, caches per currency
                               → response
                     │
                     ▼
             Login ──►  POST /api/v1/token  (+ Cookie: guest_currency=EGP)
                        backend: adoptGuestCurrencyOnLogin()
                          if user has no preference & EGP is active → set user_preferences
                          returns {adopted: true, currency:"EGP"} so frontend can clear its cookie
                        frontend: on {adopted:true} → Cookies.remove('guest_currency')
                        // backend Set-Cookie deletion is kept for BC but is a no-op for cross-site
```

---

## 6. Cookie Ownership Model

| Concern | Backend-owned (today) | Frontend-owned (target) |
|---------|-----------------------|-------------------------|
| Writer | `CurrencyController::select` → `Cookie::queue` | Frontend JS (`document.cookie` / `js-cookie`) |
| Reader | Backend `getGuestCurrencyCode()` (decrypts) | Backend reads same `Cookie:` header (plaintext) + optional `X-Currency` header |
| Encryption | Yes — opaque `eyJp...` | **No** — plaintext `EGP` |
| JS readable | No (`HttpOnly`) | Yes (`HttpOnly=false`) |
| Lifetime enforcement | Server minutes → `Set-Cookie` | Frontend `Max-Age` / `Expires`; backend ignores stale via `isValidActiveCurrency` |
| Deletion | Backend `Set-Cookie: guest_currency=; Max-Age=0` | Frontend `Cookies.remove()`; backend deletion kept for host-only BC |
| Race / double-write | Backend is single writer | Frontend may write concurrently across tabs — last write wins (fine; value is trivial) |
| Migration | — | Old encrypted cookie must be discarded; new plaintext cookie is written on next `select` or on first read by frontend |

---

## 7. Domain & Browser Cookie Analysis — Extremely Important

### 7.1 Actual domains discovered in repo

| Var | Repo value | Meaning |
|-----|------------|---------|
| `APP_URL` (`.env.example`) | `http://localhost` | default for artisan / URLs |
| `APP_URL` (local `.env`) | `http://localhost:8000` | your dev backend |
| `APP_URL` (`render.yaml`) | `fromService host` (dynamic Render URL) | prod backend host (unknown until deploy) |
| `SHOP_URL` / `DASHBOARD_URL` | empty | frontend origins **not declared** |
| `APP_URL_FRONTEND` | `http://localhost` (default) | frontend URL placeholder — not used for cookies |
| Production host cited by you | `meem.mohammedtareq.me` | backend host for this analysis |

**Fact:** No frontend origin is pinned in the repo. Any plan that assumes `Domain=.meem.mohammedtareq.me` will work for an unrelated frontend (e.g. `meem-market-ecommerce.vercel.app`) is **invalid**.

### 7.2 Browser cookie rules (must-know)

1. **Host-only vs. Domain cookies.** If `Set-Cookie` omits `Domain`, the cookie is **host-only**: only sent to the exact host that set it (e.g. `meem.mohammedtareq.me`). If `Domain=.example.com` is set, it is sent to `example.com` and `*.example.com`. You **cannot** set `Domain=other-site.com` from `your-site.com` — browsers reject foreign `Domain` attributes.

2. **`localhost` is special.** `Domain=localhost` is **rejected** by spec/Chrome. For dev, omit `Domain` and set `Path=/`; cookies on `localhost:3000` are automatically host-only and **are sent to `localhost:8000`** because browsers treat same-host, different-port as same-site (scheme+host+TL). So a frontend on `http://localhost:3000` that sets `guest_currency=EGP` (no Domain) **will** send it to `http://localhost:8000/api/*` **iff** `withCredentials:true` + CORS allow-credentials.

3. **SameSite.** `Lax` (default) allows same-site requests and top-level cross-site GET navigations. `None` is only for third-party iframes/XHR where you intentionally want cross-site cookie send; `None` **requires** `Secure`. For not-cross-site, `Lax` is safest.

4. **Cross-site cookie impossibility.** JS running on `https://shop.vercel.app` that does `document.cookie="guest_currency=EGP; Domain=meem.mohammedtareq.me"` **fails silently** — browser discards it because the effective Domain must be a parent of the document's domain. Consequences:
   - Frontend **cannot** create a cookie that the backend domain will receive if the domains are unrelated.
   - Backend `Set-Cookie: Domain=meem.mohammedtareq.me` **is** stored, but JS on `vercel.app` cannot read it (HttpOnly aside) because it's not its jar.

5. **`withCredentials` / `credentials:include`.** For `fetch`/`axios` to include cookies cross-origin, request must set `credentials:'include'` / `withCredentials:true` **and** response must have `Access-Control-Allow-Credentials:true` plus an explicit `Allow-Origin`. `Allow-Origin:*` with credentials is **rejected** by browsers.

### 7.3 Combination table

| Frontend → Backend | Same domain? | Can one cookie be shared? | How? | Required attributes |
|---|---|---|---|---|
| `http://localhost:3000` → `http://localhost:8000` | **Yes** (same host) | **Yes** | Cookie host-only `localhost`, no `Domain` | `Path=/; SameSite=Lax; Secure=false`; `withCredentials:true`; CORS `Allow-Origin=http://localhost:3000`, `Allow-Credentials:true` |
| `https://app.meem.mohammedtareq.me` → `https://api.meem.mohammedtareq.me` | **Yes** (same eTLD+1, subdomain) | **Yes** | Shared domain cookie | `Domain=.meem.mohammedtareq.me; Path=/; SameSite=Lax; Secure=true`; `withCredentials:true`; CORS explicit |
| `https://meem-market-ecommerce.vercel.app` → `https://meem.mohammedtareq.me` | **No** (unrelated) | **Impossible** | Do not try `Domain` sharing | Keep frontend cookie on `vercel.app` domain **only for UI**, **add `X-Currency: EGP` header** to every API request; backend reads header as fallback. Cookie to backend host will never arrive, by design. |
| Frontend on any IP literal | No | **No** for `Domain` | host-only works if same IP:port | avoid IPs |

### 7.4 Correct strategy (environment-aware)

```text
if (dev) {
  // localhost:*
  cookie: guest_currency=EGP; Path=/; SameSite=Lax  (no Domain, no Secure)
  fetch: credentials: 'include'
  CORS: Allow-Origin = http://localhost:3000 (and any other dev ports you use), Allow-Credentials true
  Result: cookie IS sent cross-port — works
}
else if (frontend and backend share *.meem.mohammedtareq.me) {
  cookie: guest_currency=EGP; Path=/; Domain=.meem.mohammedtareq.me; SameSite=Lax; Secure=true
  frontend writes once, reads everywhere on the suffix
  backend reads Cookie: — authoritative
  Header fallback kept but rarely needed
}
else {
  // cross-site unrelated (current likely reality: vercel + meem.mohammedtareq.me)
  cookie: guest_currency=EGP; Path=/; SameSite=Lax  (frontend domain only — for fast UI read)
  request: ALWAYS also send  X-Currency: EGP header  (or Cookie header is irrelevant)
  backend: prefer X-Currency when Cookie absent; validate identically
  Do NOT set Domain=meem.mohammedtareq.me from frontend — it will be dropped
}
```

**Fact vs. recommendation separator:** The `localhost` row is a **fact** (spec + Chrome behaviour). The cross-site row is a **fact** (impossibility theorem). The header fallback is a **recommendation** — the only valid design for unrelated domains. If you can move the frontend to a subdomain of `meem.mohammedtareq.me`, that collapses to the simple shared-cookie path and the header becomes optional.

---

## 8. Laravel Changes (Phase 5)

> **Convention:** Every row lists `Risk` and `Dependencies` so sequencing and rollback are visible. All file paths are repo-relative.

### 8.1 `UserCurrencyPreferenceService`

| File | Current | Required | Why | Risk | Dependencies |
|---|---|---|---|---|---|
| `app/Services/Currency/UserCurrencyPreferenceService.php:57-73` `setGuestCurrencyCode()` | Queues `Cookie::make(..., minutes, path)` encrypted, `httpOnly=true`, default `Secure`/`SameSite` | **Remove queuing responsibility** (or make it no-op behind a feature flag). Keep method for BC on host-only deployments but gate with `config('currency.guest_cookie_frontend_owned', true)`. When frontend-owned, the method should **do nothing** (frontend writes). Document that it becomes a BC shim. | Frontend becomes writer; backend must not compete for `Set-Cookie` on wrong domain | Low — method is only called from `CurrencyController::select`. Callers continue to work; guest flow still works via header fallback. Breaking if flag mis-toggles → double-write. |
| `...:75-84` `clearGuestCurrencyCode()` | Queues `Cookie::forget` (Sets `Max-Age=0` on backend host) | Keep for host-only BC, but add feature-flag no-op when frontend-owned. The **authoritative clear** must be frontend-side; backend should return adoption signal instead of relying on `Set-Cookie` delete cross-domain. | Backend delete cannot clear frontend-domain cookie | Low — stale cookie fallback is harmless (validated then ignored). |
| `...:44-54` `getGuestCurrencyCode()` | Reads `$request->cookie(name)` (decrypted), strtoupper | **Extend** to read `Cookie` **or** `X-Currency` header (case-insensitive), in priority `header → cookie`, trim, strtoupper, validate length 3. Also read from `request()->header('X-Currency')` when cookie absent. Keep uppercase normalization. | Enables cross-site fallback without breaking localhost shared-cookie path | Low — pure read path extension; add `X-Currency` to CORS `allowed_headers`. Must not log header value as sensitive (it's public). |
| `...:86-106` `adoptGuestCurrencyOnLogin()` | Reads guest cookie, validates, creates `user_preferences`, clears guest cookie unconditionally | **Keep adoption logic, change clearing**: only clear via `clearGuestCurrencyCode()` when `guest_cookie_frontend_owned==false`. Always **return** or fire event/context about adoption so controller can signal frontend to clear. Add return type `?string` (adopted code or null) for observability. | Prevents backend attempting to clear a cookie it doesn't own cross-site | Low — callers in `UserController:493,587,1002` ignore return today; adding return is additive. |
| `...:108-118` `isValidActiveCurrency()` | Correct — `where code=upper and is_active=true exists` | **No change** — keep as single validation gate. Consider adding a small in-memory cache (optional) if called twice per request. | Keeps security guarantee | None |
| `...:120-123` `cookieName()` | Reads `currency.guest_cookie_name` | **No change**, but document that name must match frontend `guest_currency` constant. Add `guest_cookie_frontend_owned` config toggle here. | Naming consistency | None |
| New | — | Add `resolveGuestCurrency(?Request): ?string` helper that tries `header('X-Currency') ?? $request->cookie(name) ?? $request->header('X-Currency-Cookie-Fallback')` with shared validation (extract method). Used by `CurrencyService`. | Single resolution point | Low |

**Exact risk of encrypted→plaintext switch:** Existing browsers hold `guest_currency=eyJp...` (encrypted). After adding `guest_currency` to `EncryptCookies::$except`, Laravel will stop decrypting and `Request::cookie()` will return the raw `eyJp...` string. `isValidActiveCurrency('EYJ...')` fails → cleared/fallback to catalog on next `getEffectiveCode()` call, and next `select` overwrites with plaintext `EGP`. This is a **self-healing migration** — no data loss beyond one fallback. Document in changelog.

### 8.2 Config files

| File | Current | Required | Why | Risk |
|---|---|---|---|---|
| `config/currency.php:13-15` | Only name/lifetime/path; comment says "encrypted, signed" | Update comment to "plaintext, JS-readable, validated server-side when frontend-owned". Add keys: `'frontend_owned' => env('GUEST_CURRENCY_FRONTEND_OWNED', true)`, `'same_site' => env('GUEST_CURRENCY_SAME_SITE','Lax')`, `'secure' => env('GUEST_CURRENCY_SECURE', null)` (null = auto from `APP_URL`), `'http_only' => false`, `'encrypt' => false` (docs only). Keep old keys for BC. | Makes behaviour explicit & env-toggable | Low — keys are read-only config, no code path breaks if missing (default). |
| `config/cors.php:22,28` | `allowed_origins=['*']`, `supports_credentials=false` | `allowed_origins` → explicit list: `http://localhost:3000`, `http://localhost:5173`, plus prod `https://<your-frontend>`, `https://*.vercel.app` via `allowed_origins_patterns`; `supports_credentials => true`; `allowed_headers => ['*']` **must** include `X-Currency` (wildcard covers it, but pin explicitly); `exposed_headers` optionally `X-Adopted-Currency` | Credentialed cookie/header flows require this; `*` with credentials is rejected by browsers | Medium — misconfigured origins break all API calls; test with `curl -H Origin` + `Access-Control-Allow-Credentials` |
| `config/session.php:158,171,199` | `SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE` null, `same_site=lax` | **Leave session cookie as-is** — it is not the currency cookie. Only note: if you later share auth via cookie across subdomains, set `SESSION_DOMAIN=.meem.mohammedtareq.me` separately. Do not conflate with `guest_currency`. | Avoids scope creep | None |

### 8.3 Middleware

| File | Change | Reason | Risk |
|---|---|---|---|
| `app/Http/Middleware/EncryptCookies.php:7` | Add `'guest_currency'` to `$except` | So plaintext frontend cookie is not encrypted on response nor expected to decrypt on request. Without this, `getGuestCurrencyCode()` will see `eyJ...` or throw decrypt error for plaintext. | High if omitted (auth flows break silently). Must be deployed atomically with frontend switch to plaintext. Keep behind `GUEST_CURRENCY_FRONTEND_OWNED` flag if you use staged rollout. |
| `app/Http/Kernel.php:22` | No change — `EncryptCookies` stays on `api` group (needed to manage except list). Do not move currency cookie handling into `StartSession`. | Session not needed for guest currency | None |

### 8.4 Authentication middleware — nothing to change (fact)

Sanctum token auth is bearer-based; login hooks are in `UserController`. No session-cookie auth migration needed.

### 8.5 Login / logout flows

| File | Current | Required | Why | Risk |
|---|---|---|---|---|
| `packages/marvel/src/Http/Controllers/UserController.php:480 token()` `+ :587 verifyLoginOtp()` `+ :1002 socialLogin()` | Each calls `adoptGuestCurrencyOnLogin($user, $request)` then discards result; guest cookie already cleared server-side | Keep calls, but capture return (`$adopted = service->adoptGuestCurrencyOnLogin(...);`) and include adoption signal in JSON response: `data: { ..., adopted_currency: $adopted }` or a response header `X-Adopted-Currency: EGP`. Frontend uses this to clear its own cookie. Keep backend `clearGuestCurrencyCode()` as BC no-op under flag, so host-only deploys keep working. | If you don't signal, frontend cookie remains stale (harmless — preference now wins — but wastes a later adoption check). | Low — additive response field/header, no breaking. |
| `UserController.php:599 logout()` | ` $token->delete()` only | No currency handling. Consider **not** clearing `user_preferences` on logout — preference is sticky per user. Document that logout does not reset currency. | Keeps user expectation | None |
| `UserController.php::adminToken()` (staff) | Not audited in detail | Check if it also needs `adoptGuestCurrencyOnLogin()` if staff can be guest before admin login. | Consistency | Low |

### 8.6 Currency endpoints

| File | Current | Required | Risk |
|---|---|---|---|
| `app/Http/Controllers/Api/Currency/CurrencyController.php:38 select()` | `if user setUserPreference` then **always** `setGuestCurrencyCode` (encrypted) | Under frontend ownership: `if user setUserPreference`, then **do not** queue guest cookie when flag true. Instead return `adopted` hint and optionally `X-Guest-Currency: KWD` echo header for debugging. Keep `forgetEffectiveCode()`. Validation via `SelectCurrencyRequest` stays. | If both sides write cookies, Set-Cookie races. Gate with flag prevents it. |

### 8.7 Currency validation

- Keep `SelectCurrencyRequest.php:11` `Rule::exists(... is_active)` — this is the **write path** guard.
- Keep `isValidActiveCurrency()` as **read path** guard for cookie/header values (covers stale/inactive EGP that was valid yesterday).
- No change to `Currency`/`CurrencyRate` models.

### 8.8 ENV / config surface

| Key | Purpose | Default |
|---|---|---|
| `GUEST_CURRENCY_COOKIE` | name | `guest_currency` |
| `GUEST_CURRENCY_COOKIE_LIFETIME` | 525960 | 525960 |
| `GUEST_CURRENCY_COOKIE_PATH` | `/` | `/` |
| **New** `GUEST_CURRENCY_FRONTEND_OWNED` | 1/0 | `true` |
| **New** `GUEST_CURRENCY_SAME_SITE` | Lax/None | `Lax` |
| **New** `GUEST_CURRENCY_SECURE` | true/false/null | auto (`Str::startsWith(APP_URL,'https')`) |
| **New** `CORS_ALLOWED_ORIGINS` | comma list | `http://localhost:3000,http://localhost:5173` |
| **New** `CORS_SUPPORTS_CREDENTIALS` | true | `true` |

### 8.9 Tests — will break

| Test | Why breaks | Must change |
|---|---|---|
| `UserCurrencyPreferenceTest.php:52 guest_currency_cookie_can_be_queued_and_read` asserts `Cookie::queued('guest_currency')` and `getGuestCurrencyCode` via encrypted jar | After except list, `Cookie::queued` will be null when frontend-owned | Rewrite to set header + cookie plaintext on `Request`, assert `getGuestCurrencyCode` + `resolveGuestCurrency` works, and that `setGuestCurrencyCode` is no-op when flag true |
| `...:69 guest_currency_cookie_can_be_cleared` | Same — `Cookie::queued` check goes stale | Assert header fallback and that adopt no longer queues forget |
| `...:187 select_endpoint_sets_the_guest_currency_cookie_for_guests` `assertCookie` | No Set-Cookie will be emitted — frontend writes cookie | Change to `assertCookieMissing` when flag true, or gate; also assert response returns `adopted_currency` echo |
| `CurrencySelectionEnabledTest.php` | Uses `cookies->set('guest_currency')` + expects resolution — still valid for header path if tests set both | Update helper to set header `X-Currency` as well |

**Risk if not updated:** CI red on day 1.

---

## 9. Frontend Changes (Phase 6 — contract)

> No frontend file exists in this repo — this is a **contract** for the separate frontend repo.

### 9.1 Reading (how JS reads `guest_currency`)

```js
// Option A — js-cookie (recommended)
import Cookies from 'js-cookie';
const code = Cookies.get('guest_currency'); // "EGP" | undefined
// Option B — vanilla
function getGuestCurrency() {
  const m = document.cookie.match(/(?:^|;\s*)guest_currency=([^;]*)/);
  return m ? decodeURIComponent(m[1]).toUpperCase() : null;
}
// Always normalize: strtoupper + trim + validate ^[A-Z]{3}$  before use.
// If invalid, delete and fallback to catalog.
```

### 9.2 Writing (USD → EGP)

```js
import Cookies from 'js-cookie';

function setGuestCurrency(code) {
  const upper = String(code).trim().toUpperCase();
  // Validate locally for fast UI, but server is authoritative
  if (!/^[A-Z]{3}$/.test(upper)) throw new Error('Invalid code');

  // Environment-aware attributes:
  const isHttps = location.protocol === 'https:';
  const isLocalhost = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
  const shareOnSubdomain = false; // true only if frontend & backend share *.meem.mohammedtareq.me

  Cookies.set('guest_currency', upper, {
    expires: 365,            // ~525960 min; 365 is fine
    path: '/',
    sameSite: 'Lax',         // Lax always; None only for cross-site cookie (not used for plain cross-site)
    secure: isHttps,         // false on http://localhost
    domain: shareOnSubdomain ? '.meem.mohammedtareq.me' : undefined, // omit for host-only
  });

  // ALSO keep X-Currency header source of truth (for cross-site):
  // Send on every API request via axios/fetch interceptor (see 9.4)
}
```

For **cross-site unrelated** deployments, the cookie on the frontend domain is **UI-only**; the header is what actually reaches the backend. The cookie write above is still valuable for instant UI and for `localhost` shared-cookie path.

### 9.3 Deleting

```js
function clearGuestCurrency() {
  const shareOnSubdomain = false; // must match set call
  Cookies.remove('guest_currency', {
    path: '/',
    domain: shareOnSubdomain ? '.meem.mohammedtareq.me' : undefined,
  });
}
// Call on: logout (optional), or after login when data.adopted_currency is present,
// or when /api/v1/general/currencies/select returns 200 (preference is now sticky).
```

### 9.4 API requests — how the browser sends the cookie

```http
# Host-only localhost case — browser automatically adds cookie if withCredentials:
GET /api/v1/general/products HTTP/1.1                 # to http://localhost:8000
Host: localhost:8000
Origin: http://localhost:3000
Cookie: guest_currency=EGP
X-Currency: EGP                                       # always add header for cross-site readiness

# Cross-site case — Cookie header will be absent (different jar), header carries it:
GET /api/v1/general/products HTTP/1.1
Host: meem.mohammedtareq.me
Origin: https://meem-market-ecommerce.vercel.app
X-Currency: EGP                                       # backend reads this
Cookie: (maybe absent for guest_currency — that's fine)
```

**Client config (required):**

```js
// axios
import axios from 'axios';
const api = axios.create({
  baseURL: 'https://meem.mohammedtareq.me/api',
  withCredentials: true,           // ← REQUIRED for Cookie to be sent when shared
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
});
api.interceptors.request.use(cfg => {
  const c = Cookies.get('guest_currency');
  if (c) cfg.headers['X-Currency'] = String(c).toUpperCase();
  return cfg;
});

// fetch
fetch('https://meem.mohammedtareq.me/api/v1/general/products', {
  credentials: 'include',          // ← REQUIRED for Cookie path
  headers: { 'X-Currency': getGuestCurrency() ?? '' },
});
```

**Do not** manually build `Cookie: guest_currency=EGP` header in JS — browsers forbid `Cookie` request-header via JS (`Forbidden header name`). Let the browser’s own cookie jar send `Cookie:`; **use `X-Currency` as the JS-writable channel** for cross-site.

---

## 10. Backend Contract (Phase 7)

### 10.1 What the backend expects

```http
# Best (shared subdomains or localhost)
Cookie: guest_currency=EGP

# Cross-site fallback (mandatory when frontend ≠ backend origin)
X-Currency: EGP
# Alternative name X-Guest-Currency also fine — pick one and document.

# Auth (unchanged)
Authorization: Bearer <sanctum token>
```

### 10.2 Validation / normalization / fallback

| Case | Backend does | Effective currency |
|---|---|---|
| Missing cookie + missing header | `getGuestCurrencyCode()==null` → skip | catalog (`catalog_currency_code`) |
| Present, trimmed, uppercased, matches `currencies.code where is_active=true` | accept | that code (if `currency_selection_enabled=true`, else still catalog) |
| Lowercase `egp` | `strtoupper` → `EGP` → accept | EGP |
| Malformed `EG`, `E G P`, `12`, `EGP%00` | `isValidActiveCurrency` fails → clear + ignore | catalog |
| Unsupported `XXX` | fails → ignore (and clear if from cookie) | catalog |
| Inactive `KWD` (is_active=false) | fails → clear persisted preference/cookie | catalog |
| Encrypted legacy `eyJp...` (after migration) | fails validation (length≠3) → clear | catalog for one request, then overwritten by next valid frontend write |
| `currency_selection_enabled=false` | ignore guest entirely even if valid | catalog (today’s design) |

**Security rule:** Backend **never** trusts raw value for DB write without `exists(is_active)` check.

### 10.3 `CurrencyService::getEffectiveCode()` change

```php
// Pseudocode — additive, behind helper
$guestCode = $this->preferenceService->resolveGuestCurrency($request); // header → cookie
// then same validation/clear logic as today
```

Add `X-Currency` to CORS `allowed_headers` (wildcard already does).

---

## 11. Authentication/Login Impact (Phase 8)

### 11.1 Current `adoptGuestCurrencyOnLogin()` — annotated

```php
// UserCurrencyPreferenceService.php:86 (current)
if (!Schema::hasTable('user_preferences')) return;
if (getUserPreference(user) !== null) return;          // existing preference wins
guestCode = getGuestCurrencyCode(request);
if (guestCode null || !isValidActiveCurrency) return;  // invalid ignored
setUserPreference(user, guestCode);                    // adopt
clearGuestCurrencyCode(request);                       // delete host cookie via Set-Cookie
```

### 11.2 Verdict: **B. modified** (not removed, not unchanged)

**Reasons:**
- Adoption semantics are **correct** and should remain: first-time user without a preference inherits the guest’s last selected currency. Existing preference wins. Invalid never adopted.
- **Deletion** is the only part that must change: `clearGuestCurrencyCode()` via `Set-Cookie` cannot delete a cookie that lives on an unrelated frontend domain; it only deletes the backend host cookie. In host-only localhost mode it still works, so keep it gated. In cross-site mode, deletion must be **frontend-driven on signal**.
- Return value / signal is missing: frontend has no way to know adoption happened and should clear.

### 11.3 Target login behavior

```
Guest:  frontend cookie = EGP
        |
        v
POST /api/v1/token  (Cookie: guest_currency=EGP  OR  X-Currency: EGP)
        |
        v
Backend adoptGuestCurrencyOnLogin():
  if user has user_preferences.crow → keep it, return {adopted:false, current:userPref}
  else if guestCode valid+active → insert user_preferences(EGP), return {adopted:true, currency:EGP}
  else → return {adopted:false}
  // if frontendOwned==false: also Queue Forget (BC)
  // if frontendOwned==true:  do NOT queue forget — rely on frontend clear
        |
        v
Response JSON adds adopted_currency hint:
  { ..., data: { token, user }, meta: { adopted_currency: "EGP" } }
  // or header X-Adopted-Currency: EGP  plus cleared userPreference read via /me
        |
        v
Frontend on 200 + adopted:true → Cookies.remove('guest_currency')  (same domain/path/domain as set)
        |
        v
Future requests:  user preference = EGP (effective), guest cookie absent → no guest fallback needed
If frontend did NOT clear (bug), backend's effective resolution prefers user preference anyway → harmless
```

### 11.4 Edge cases

| Case | Backend | Frontend |
|---|---|---|
| Guest cookie missing at login | No adoption, `adopted:false` | Keep UI catalog |
| Guest header present but cookie absent (cross-site) | Adopt from header → `adopted:true` | Clear frontend cookie on header-based adoption too |
| User already has SAR, guest EGP | No override — user keeps SAR, `adopted:false`; **do not** clear guest cookie (lets guest selection survive if they log out and are guest again? Policy choice) — recommended: **keep guest cookie** when not adopted | Frontend keeps cookie; effective is SAR while authed |
| Invalid `XXX` | No adoption | Frontend should have validated before send; if still sent, backend clears backend-side cookie (host-only) and frontend should delete on next read of invalid |
| Concurrent tabs writing different codes | Last write wins before login — race is harmless (3-letter code) | No action |

---

## 12. Security Analysis (Phase 9)

| Topic | Current (HttpOnly encrypted) | Target (JS-readable plaintext) | Analysis |
|---|---|---|---|
| Confidentiality | Encrypts `EGP` with AES-256-CBC | Plaintext `EGP` | **No loss** — currency code is public, appears in product prices and URLs. Encryption is overkill. |
| Integrity / tampering | HMAC via `EncryptCookies` guarantees integrity; tampered cookie → `DecryptException` → rejected | No HMAC — but `isValidActiveCurrency()` rejects any non-active code | **Tampering is harmless** — worst case attacker forces `guest_currency=USD` → catalog fallback or valid USD. No privilege escalation. Signing would be cosmetic. |
| XSS | `HttpOnly` hides cookie from JS XSS | `HttpOnly=false` exposes cookie to XSS | **Acceptable** — attacker with XSS can already read prices, DOM, and set any cookie/header. Stealing `guest_currency` gives no session/token. Real XSS defense is CSP + sanitization, not hiding `EGP`. |
| Sensitive? | No | No | Guest currency is **preference**, not secret. |
| Encryption still useful? | No | Remove — add to `EncryptCookies::$except` | Encryption prevents JS read and inflates cookie size (~200 B → 3 B). Remove. |
| Signing useful? | Not needed for confidentiality; optional for integrity | Could add `guest_currency_sig` HMAC cheaply, but **unnecessary** — validation is authoritative and DB lookup is cheap. Reserve signing for future if you ever store entitlement-like values. | Don't over-engineer. |
| `SameSite` | implicit `Lax` | Keep `Lax`; `None` only if you ever need third-party iframe; `None` requires `Secure` | `Lax` mitigates CSRF — `POST /currencies/select` is idempotent preference write, still worth Lax. |
| `Secure` | `null` (not forced) | `false` on `http://localhost`, `true` on `https://` | Prevents cleartext leak on wire; set via env auto. |
| CSRF | `Lax` + `api` group not using `VerifyCsrfToken` (only `web`) — token auth is Bearer, so CSRF risk is low. Cookie here is not a session, so CSRF via hidden form could set currency via `POST /currencies/select` — harmless (just preference). | Same risk, unchanged | No action needed beyond `Lax`. |
| Logging | Avoid logging full cookie value at info (it is not secret, but still noise) | Keep masking in logs | No PII |

**Bottom line:** `HttpOnly=false` + `EncryptCookies::$except` + plaintext `E*/` is the **simplest secure architecture** for a non-sensitive preference. Do not add signing or encryption. Validate server-side, normalize uppercase, and keep `SameSite=Lax`.

---

## 13. API Contract (Phase 12 — real endpoints only)

### 13.1 `GET /api/v1/general/currencies` — public (no change)

| Field | Value |
|-------|-------|
| Method/URL | `GET /api/v1/general/currencies` (`routes/api.php:100`) |
| Auth | none |
| Cookie / Header | ignored (read path not needed) |
| Query | `limit` |
| Response | `200` list of `is_active=true` currencies, cached 4h |
| Side effects | none |

### 13.2 `POST /api/v1/general/currencies/select` — public (behaviour change)

| Field | Before (backend-owned) | After (frontend-owned) |
|-------|------------------------|------------------------|
| Method/URL | `POST /api/v1/general/currencies/select` | same |
| Auth | optional (`auth('sanctum')->user() ?? auth()->user()`) | same |
| Request | `{currency_code:"KWD"}` validated active | same |
| Cookie sent by client | maybe old `guest_currency` | `guest_currency=EGP` (if same-site/localhost) + `X-Currency: EGP` header |
| Server writes | `Set-Cookie: guest_currency=<encrypted>; HttpOnly` always | **No `Set-Cookie`** when `GUEST_CURRENCY_FRONTEND_OWNED=true` (frontend writes). If flag false, legacy `Set-Cookie` (BC). Response includes `{meta:{adopted_currency: ...}}` or `X-Adopted-Currency` header for observability. |
| Side effects | `user_preferences` upsert if authed; guest cookie queued; `forgetEffectiveCode()` | `user_preferences` same (authed); guest cookie **not** queued; `forgetEffectiveCode()` |
| Response | `200 {message:CURRENCY_SELECTED_SUCCESSFULLY, data:CurrencyResource}` | same shape, `data.code=KWD`, plus optional `meta.adopted_currency` |
| Errors | `422` unknown/inactive | same |

### 13.3 `GET /api/v1/general/products` / `cart` / `orders` — implicit effect

All pricing endpoints call `CurrencyService::getEffectiveCode()` which now reads **header fallback** in addition to cookie. No contract change — just different resolution source. Cache key `md5(fullUrl + '|currency:' + effective)` remains.

### 13.4 `POST /api/v1/token`, `POST /api/v1/verify-login-otp`, `POST /api/v1/social-login` — auth

| Field | Before | After |
|-------|--------|-------|
| Request Cookie/Header | `guest_currency` encrypted host-only | `guest_currency` plaintext or `X-Currency` |
| Server adoption | `adoptGuestCurrencyOnLogin()` → `Set-Cookie` delete | same adoption, **no `Set-Cookie` delete** when frontend-owned; return `adopted_currency` signal |
| Response JSON | `{token, user}` | `{token, user, meta:{adopted_currency: "EGP"|null}}` (additive) |
| Side effects | `user_preferences` inserted if empty & valid | same |

### 13.5 `POST /api/v1/logout` — no change (currency not cleared automatically)

---

## 14. Test Plan (Phase 10)

### 14.1 Existing tests that will break (must be reworked before green)

| Test | File | Break reason |
|---|---|---|
| `guest_currency_cookie_can_be_queued_and_read` | `UserCurrencyPreferenceTest.php:52` | asserts `Cookie::queued('guest_currency')` — will be null when frontend-owned |
| `guest_currency_cookie_can_be_cleared` | `:69` | same queued-cookie assertion |
| `select_endpoint_sets_the_guest_currency_cookie_for_guests` | `:187` (`assertCookie`) | no `Set-Cookie` when frontend-owned |
| Any test with `withoutMiddleware(EncryptCookies)` expecting encrypted round-trip | `:187` | flow no longer encrypted |

### 14.2 New / reworked test matrix

#### Guest — resolution

| # | Title | Setup | Expect |
|---|-------|-------|--------|
| G1 | No cookie, no header | no `guest_currency` cookie, no `X-Currency` | `effective=USD` (catalog), `getGuestCurrencyCode()==null`, `resolveGuestCurrency()==null` |
| G2 | Valid EGP via cookie | `Cookie: guest_currency=EGP` | `resolve=EGP`, `effective=EGP` when selection enabled |
| G3 | Valid USD via header (cross-site) | `X-Currency: USD`, no cookie | `resolve=USD` (header wins) |
| G4 | Lowercase `egp` | `X-Currency: egp` | `resolve=EGP` (uppercased) |
| G5 | Invalid `XXX` via cookie | `guest_currency=XXX` | `resolve==null` after validation, `effective=catalog`, preference not written |
| G6 | Inactive `KWD` (flip `is_active=false`) | `X-Currency: KWD` | `resolve==null`, `effective=catalog`, `isValidActiveCurrency` false |
| G7 | Malformed `12`, `EGP%00`, `E G P`, empty | header `12` | `resolve==null` |
| G8 | Header `EGP` + Cookie `SAR` simultaneously | both present | header wins (`EGP`) — document priority |

#### Cookie behaviour (environment-specific)

| # | Title | Check |
|---|-------|-------|
| C1 | Frontend can read cookie | JS `Cookies.get('guest_currency') === 'EGP'`; document.cookie includes it (httpOnly false) — assert via `EncryptCookies::$except` contains `guest_currency` |
| C2 | Backend receives cookie (host-only / localhost) | `Request::create('/', 'GET', [], ['guest_currency'=>'EGP'])` → `getGuestCurrencyCode()==EGP` |
| C3 | Backend receives header (cross-site) | `Request::create('/', 'GET', [], [], [], ['HTTP_X_CURRENCY'=>'EGP'])` → `resolveGuestCurrency()==EGP` |
| C4 | Currency change USD→EGP | Call `POST /api/v1/general/currencies/select {EGP}` as guest with flag true → `assertCookieMissing('guest_currency')` and next request with `X-Currency:EGP` resolves |
| C5 | Cookie deletion (frontend) | `clearGuestCurrency` helper → next request has no cookie/header → `effective=catalog` |
| C6 | Legacy encrypted cookie migration | Set cookie `guest_currency=eyJp...` (fake encrypted) with except list active → `resolve==null` → fallback, then frontend writes `EGP` → resolves |

#### Authentication

| # | Title | Expect |
|---|-------|--------|
| A1 | Guest EGP adopted on login (user has no pref) | `POST /api/v1/token` with `X-Currency: KWD` → `user_preferences=KWD`, response `meta.adopted_currency=KWD`, next `getEffectiveCode(user)==KWD` |
| A2 | Existing preference SAR wins | user pref `SAR`, guest `KWD` → after login pref remains `SAR`, `adopted=false` |
| A3 | Missing guest at login | no cookie/header → `adopted=false`, pref stays null |
| A4 | Invalid guest at login | `X-Currency: XXX` → `adopted=false`, pref stays null, no exception |
| A5 | Guest header adopted, frontend clears | After A1, JS removes `guest_currency`; subsequent guest requests (logged out) resolve to catalog (preference not leaked to next guest—it's per-user) |
| A6 | Social login adoption | same as A1 via `POST /social-login` |
| A7 | OTP login adoption | same via `verifyLoginOtp` |

#### Environments

| # | Env | Manual check |
|---|-----|--------------|
| E1 | `localhost:3000 → localhost:8000` | Set cookie on `localhost` (no Domain), fetch with `credentials:include`, `Origin:http://localhost:3000` → backend sees `Cookie: guest_currency=EGP` → `200 Allow-Credentials:true` |
| E2 | Shared subdomain `app.meem.mohammedtareq.me → api.meem.mohammedtareq.me` | Cookie `Domain=.meem.mohammedtareq.me; Secure` → visible on `api` host |
| E3 | Cross-site `vercel.app → meem.mohammedtareq.me` | Cookie on `vercel.app` NOT sent → backend sees only `X-Currency`; test with `Origin:https://<vercel>` and `Access-Control-Allow-Origin: https://<vercel>` |

---

## 15. Migration Plan (Phase 11 — ordered, with risks)

| Step | What | Files | Depends on | Risk | Rollback |
|------|------|-------|------------|------|----------|
| **1. Backend preparation — config & except list** | Add `config/currency.php` keys (`frontend_owned`, `same_site`, `secure`), add `guest_currency` to `EncryptCookies::$except`, make `getGuestCurrencyCode` read header fallback (`resolveGuestCurrency`). Keep `setGuestCurrencyCode`/`clearGuestCurrencyCode` behind flag as BC shims. Fix `CORS` to explicit origins + `supports_credentials:true` + `allowed_headers` includes `X-Currency`. | `config/currency.php:13`, `app/Http/Middleware/EncryptCookies.php:7`, `app/Services/Currency/UserCurrencyPreferenceService.php:44,57,75`, `config/cors.php:22,28`, `config/app.php:55`, `.env.example` | none | Medium — CORS misconfig breaks all calls; except list must ship with header logic or plaintext will throw decrypt error. Canary with flag `false` initially. | Set `GUEST_CURRENCY_FRONTEND_OWNED=false` and remove from `except` (requires deploy). |
| **2. Backend — login signal & CurrencyService** | `adoptGuestCurrencyOnLogin()` returns `?string` adopted code; `UserController::token/verifyLoginOtp/socialLogin` include `meta.adopted_currency` / `X-Adopted-Currency` header. `CurrencyService::getEffectiveCode()` calls `resolveGuestCurrency()` instead of `getGuestCurrencyCode()` directly. | `UserCurrencyPreferenceService.php:86`, `UserController.php:493,587,1002`, `CurrencyService.php:114` | Step 1 | Low — additive field, no BC break. | Remove meta field. |
| **3. Tests — make green for both modes** | Parameterize `UserCurrencyPreferenceTest`/`CurrencySelectionEnabledTest` for `frontendOwned=true/false`; gate `assertCookie` vs `assertCookieMissing`. Add header-priority tests (G1-G8, A1-A7). | `tests/Feature/Currency/*`, `CreatesTestTables.php:73` | Steps 1-2 | Low — CI-only | Revert test commits. |
| **4. Docs & contract** | Update `api-desc/currency/*` (api.md, backend.md, flow.md, change-response.md, database.md) to document `X-Currency` header, frontend ownership, CORS credentials, cookie attributes. | `api-desc/currency/*` | Steps 1-2 | None | Revert md. |
| **5. Frontend — cookie ownership** | Implement `get/set/clearGuestCurrency()` helpers (js-cookie) with env-aware Domain/Path/SameSite/Secure, write on currency select, read on boot. Add axios/fetch interceptor that always sets `X-Currency` header + `withCredentials:true`/`credentials:include`. On login response with `meta.adopted_currency`, clear frontend cookie. On currency select as authed, call `POST /api/v1/general/currencies/select` (still for `user_preferences` write) but **do not** expect `Set-Cookie`. | Frontend repo: `lib/currency.js`, `lib/api.js` (axios), `store/currency` | Steps 1-2 | Medium — Domain mismatch will silently drop cookie. Test cross-port locally first; for cross-site verify header actually arrives. | Frontend reverts to calling select and ignoring header (backend still reads cookie/header either way). |
| **6. Integration & environment validation** | Validate E1 (localhost cross-port) with `curl -v -H Origin:http://localhost:3000 -H X-Currency:EGP http://localhost:8000/api/v1/general/products` shows `Access-Control-Allow-Credentials:true`. Validate E3 cross-site via deployed preview. Old encrypted cookie migration: open site with stale `eyJ...` value, confirm fallback then overwrite. | — | Steps 1-5 | Medium — browser cache of `SameSite`/`Domain` can persist; clear cookies between runs. | Browser clear cookies. |
| **7. Regression** | Run full currency suite + `CurrencySelectionEnabledTest` + product cache tests (currency-aware). Run `POST /token` + `select` + `GET /products` e2e. | `tests/Feature/Currency/*`, `tests/Feature/Product*` | Step 6 | Low | — |
| **8. Production deployment** | Deploy backend with `GUEST_CURRENCY_FRONTEND_OWNED=true`, `CORS_ALLOWED_ORIGINS=https://<your-frontend>,https://*.vercel.app`, `GUEST_CURRENCY_SAME_SITE=Lax`, `GUEST_CURRENCY_SECURE=true` (https). Deploy frontend. | Render env `render.yaml` + env dashboard | Step 7 | Medium — ensure `APP_URL` is https; `Secure` without https will hide cookie. | Flip `GUEST_CURRENCY_FRONTEND_OWNED=false` and remove from `except` (needs redeploy); frontend fallback header keeps working. |
| **9. Cleanup (7 days later)** | Remove BC shim `setGuestCurrencyCode` queuing path, remove `GUEST_CURRENCY_FRONTEND_OWNED` flag if you never want to return. | same files | Step 8 | Low | — |

**Order matters:** Never deploy frontend cookie writer before backend is ready to read plaintext + header and has the except list — requests in the gap would either be encrypted→decrypt error or 422.

---

## 16. Rollback Plan

| Failure | Mitigation | How to revert |
|---|---|---|
| CORS `Allow-Origin:*` with credentials breaks in prod | Pre-set explicit origin list including `https://<frontend>` and `http://localhost:*` patterns in `allowed_origins_patterns`; staging smoke with `curl -H Origin:` before prod. | Re-deploy with `CORS_ALLOWED_ORIGINS` including the broken origin. |
| `EncryptCookies` except omission → plaintext decrypt error | Deploy except list in **same release** as frontend switch. | Set `GUEST_CURRENCY_FRONTEND_OWNED=false`, remove from `except`, redeploy — old encrypted cookies accepted again. |
| Frontend Domain rejection (`Domain=meem.mohammedtareq.me` from `vercel.app` dropped) | Detect drop (cookie not in `document.cookie` after set), fallback to header-only and run shared-subdomain path instead. | Change frontend `domain` to `undefined` (host-only) and rely on header. |
| Stale encrypted cookie causes one-request fallback | Acceptable — self-heals. Document as known migration one-time. | No action; next select overwrites. |
| Login adoption stops after flag flip | Flag keeps BC `clearGuestCurrencyCode` for host-only; header path adoption still works via `resolveGuestCurrency`. | Flip flag false, adoption via cookie resumes. |

---

## 17. Risks & Trade-offs

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Trying to share one cookie across unrelated domains (impossible) | High if ignored | Cookies silently dropped → backend never sees guest currency → permanent catalog fallback | Use header fallback for unrelated domains; do not set foreign Domain. |
| `Allow-Origin:*` with credentials | High | Browser blocks every credentialed request | Must list explicit origins. |
| Encrypted legacy cookies after except list | Guaranteed for 1 request | One fallback to catalog | Self-healing; document. |
| XSS-exposed plaintext | Low exploit | Wrong currency display | Acceptable; XSS severity already high via other vectors; keep CSP. |
| Double-write race (frontend + backend both Set-Cookie) | High if flag wrong | Two `Set-Cookie` headers race, browser last wins | Gate backend writer with flag true → no Set-Cookie. |
| `Secure` on http localhost | Medium | Cookie never set, appears broken in dev | Auto-detect `isHttps` on frontend, env `GUEST_CURRENCY_SECURE=null` auto off for localhost. |
| `SameSite=None` without `Secure` | Low | Rejected | Use `Lax` for everything except iframe; never use None on http. |
| Adding `X-Currency` header without CORS allow | High | Preflight 403 | Add `X-Currency` to `allowed_headers` (wildcard already, but pin). |
| Large cookie count (if other cookies added) | Low | Header size bloat | Keep value 3 bytes, single cookie. |

---

## 18. Final Recommendation

```text
Recommended Architecture:

  Frontend-owned guest_currency (plaintext, JS-readable, validation server-side)
  ├── localhost:     Cookie host-only (no Domain), Path=/, SameSite=Lax, Secure=false
  │                  + fetch/axios withCredentials + CORS explicit origin + Allow-Credentials
  │                  + X-Currency header as redundant signal
  ├── same-site subdomains (app.meem.mohammedtareq.me ↔ api.meem.mohammedtareq.me):
  │                  Cookie Domain=.meem.mohammedtareq.me, SameSite=Lax, Secure=true
  │                  + X-Currency header (cheap, keeps contract uniform)
  └── cross-site unrelated (vercel ↔ meem.mohammedtareq.me):
                     Cookie stays on frontend domain (UI only) — NOT sent to backend
                     Authority channel is X-Currency: EGP header (plaintext, header is JS-writable)
                     Backend reads header → cookie (priority header if you want cross-site to be header-authoritative)

Why:

  • Guest currency is public, tiny (3 bytes), and error-safe via isValidActiveCurrency().
  • Encryption/HttOnly adds cost (size, JS blindness) with no confidentiality benefit.
  • Frontend ownership gives instant UI (no round-trip POST required to store preference locally)
    and matches SPA expectations (store/currency.ts persists locally).
  • Header fallback is the ONLY spec-compliant way to send a guest hint cross-site when
    frontend and backend do not share an eTLD+1. Cookies cannot cross unrelated registrable domains.

Do NOT do:

  • Do NOT set Domain=meem.mohammedtareq.me from JS running on vercel.app — it will be discarded.
  • Do NOT set Domain=localhost — it is rejected; omit Domain for localhost.
  • Do NOT keep CORS allowed_origins=* with supports_credentials=true — browser will block.
  • Do NOT trust raw header/cookie without strtoupper + isValidActiveCurrency().
  • Do NOT keep guest_currency in EncryptCookies except list while still testing encrypted cohort.
  • Do NOT delete frontend-domain cookies via backend Set-Cookie — the Domain will mismatch; let frontend handle delete on adoption.

Backend changes (summary):

  • app/Services/Currency/UserCurrencyPreferenceService.php — add header fallback, make set/clear behind
    GUEST_CURRENCY_FRONTEND_OWNED flag, make adopt return adopted code.
  • app/Http/Middleware/EncryptCookies.php — except guest_currency.
  • app/Http/Controllers/Api/Currency/CurrencyController.php — stop queuing guest cookie when flag true.
  • app/Services/Currency/CurrencyService.php — use resolveGuestCurrency().
  • config/currency.php — add frontend_owned / same_site / secure keys.
  • config/cors.php — explicit allowed_origins + supports_credentials true + allowed_headers includes X-Currency.
  • .env / .env.example / render.yaml — add GUEST_CURRENCY_* and CORS_ALLOWED_ORIGINS.

Frontend changes (summary):

  • Implement get/set/clearGuestCurrency() helpers (js-cookie, env-aware Domain/SameSite/Secure).
  • Axios/fetch: always set withCredentials:true / credentials:include; inject X-Currency header
    from guest_currency on every /api request; do not try to set forbidden Cookie header.
  • On POST /api/v1/general/currencies/select success, also set local cookie (redundant safety).
  • On login response meta.adopted_currency, remove frontend cookie.
  • Store currency in local UI state as before (header is the sync channel).

Shared-cookie feasibility:

  • localhost → backend (same host, different port): YES — host-only, Lax, no Domain.
  • *.meem.mohammedtareq.me → *.meem.mohammedtareq.me (same eTLD+1): YES — Domain=.meem.mohammedtareq.me, Lax, Secure.
  • localhost or vercel.app → meem.mohammedtareq.me (unrelated): NO — impossible to share; use header.

Localhost strategy:

  No Domain, Path=/, SameSite=Lax, Secure=false, Max-Age 31557600, HttpOnly=false, plaintext.
  Frontend on http://localhost:3000 sets Cookies.set('guest_currency','EGP',{sameSite:'Lax'}).
  Every fetch to http://localhost:8000/api/* with credentials:include carries Cookie: guest_currency=EGP
  and Header: X-Currency: EGP. Validate via curl with Origin header.

Production strategy:

  If you can place the storefront at https://app.meem.mohammedtareq.me (or any *.meem.mohammedtareq.me):
    use Domain=.meem.mohammedtareq.me; Secure=true; Lax — single cookie shared bidirectionally.
  If the storefront must stay on https://*.vercel.app (unrelated):
    keep X-Currency header as primary; keep a frontend-domain cookie only for instant UI read;
    do not fight the browser — header is the correct contract.
```

---

## 19. Exact Files To Change

| File | Action |
|------|--------|
| `app/Services/Currency/UserCurrencyPreferenceService.php` | 🔴 Must — header fallback + flag-gated writer/clearer + return adopted code |
| `app/Http/Middleware/EncryptCookies.php` | 🔴 Must — `except=['guest_currency']` |
| `app/Services/Currency/CurrencyService.php` | 🔴 Must — call `resolveGuestCurrency()` |
| `app/Http/Controllers/Api/Currency/CurrencyController.php` | 🔴 Must — stop queuing guest cookie when flag true |
| `packages/marvel/src/Http/Controllers/UserController.php` | 🔴 Must — return adoption signal in `token`, `verifyLoginOtp`, `socialLogin` |
| `config/currency.php` | 🟡 Must — add `frontend_owned` / `same_site` / `secure` keys + docs |
| `config/cors.php` | 🔴 Must — `allowed_origins` explicit, `supports_credentials=true`, allowed headers |
| `.env.example` / `.env` / `render.yaml` env | 🟡 Must — add `GUEST_CURRENCY_FRONTEND_OWNED`, `CORS_ALLOWED_ORIGINS`, `GUEST_CURRENCY_SAME_SITE`, `GUEST_CURRENCY_SECURE` |
| `app/Http/Requests/SelectCurrencyRequest.php` | ⚪ No change |
| `tests/Feature/Currency/UserCurrencyPreferenceTest.php` | 🔴 Must for CI |
| `tests/Feature/Currency/CurrencySelectionEnabledTest.php` | 🟡 Must |
| `tests/Feature/Currency/CurrencyTestCase.php` | ⚪ Helper only |

**Frontend (separate repo, not in this tree):** `lib/currency.ts`, `lib/api.ts` (axios), `store/currency.*` — implement contract §9.

---

## 20. Exact Files Not To Change

| File | Why leave it |
|------|--------------|
| `config/session.php` | Unrelated — session cookie is not guest currency |
| `app/Http/Kernel.php` | No middleware group change beyond except list |
| `app/Http/Middleware/TrustProxies.php` | Not currency-related (stay `proxies='*'` per current) |
| `app/Models/Currency.php`, `app/Models/CurrencyRate.php` | Not cookie-related |
| `database/migrations/2026_08_11_000002_create_user_preferences_table.php` | Schema correct |
| `app/Models/UserPreference.php` | Keep as is |
| `app/Services/Currency/CurrencyConversionService.php`, `CurrencyRateService.php` | Pricing logic unchanged |
| `routes/api.php` | URL contract unchanged (unless you want a dedicated header-docs comment) |
| `config/app.php` | APP_URL changes are operational, not part of cookie logic |
| Generic `api-desc/*` besides `currency/*` | Avoid scope creep |

---

## 21. Implementation Checklist (pre-approve, then execute)

**Do not execute until this plan is approved.**

- [ ] Approve plan — choose subdomain strategy (shared `*.meem.mohammedtareq.me` vs. cross-site `vercel`) — informs `Domain` setting
- [ ] Branch `feat/frontend-owned-guest-currency`
- [ ] Implement §8.1 `UserCurrencyPreferenceService.php` (resolveGuestCurrency + flag)
- [ ] Implement §8.3 `EncryptCookies.php` except list
- [ ] Update `config/currency.php` + add env keys
- [ ] Fix `config/cors.php` + probe with `curl -H Origin:` in staging
- [ ] Wire `UserController.php` adoption signal
- [ ] Update `CurrencyController.php` writer gate
- [ ] Update `CurrencyService.php` to call helper
- [ ] Update tests (§14.1) and green CI with both flag states
- [ ] Update `api-desc/currency/*` docs
- [ ] Implement frontend helpers (§9.1-9.4) in frontend repo, env-aware cookie attrs
- [ ] Manual E1 loop: `localhost:3000` set `EGP` → reload → product cache key `|currency:EGP` → price `22.1` vs `SAR` `375.0` (helpers in `UserCurrencyPreferenceTest:212`)
- [ ] Manual E3 loop: `vercel` preview sets `X-Currency:EGP` → backend resolves via header → `200` with correct effective
- [ ] Login E2E: guest `EGP` → `POST /token` → `user_preferences=EGP` → `meta.adopted_currency=EGP` → frontend `Cookies.remove`
- [ ] Smoke: old encrypted `eyJ...` cookie → fallback catalog for one request → next select overwrites
- [ ] Staging deploy with `GUEST_CURRENCY_FRONTEND_OWNED=true`
- [ ] Production deploy + set `CORS_ALLOWED_ORIGINS`, `GUEST_CURRENCY_SECURE=true`
- [ ] Monitor `failed_jobs` (none expected), `laravel.log`, `Access-Control-Allow-Credentials` responses

---

## 22. Appendix — Evidence Snippets (facts)

```text
# Repo's only guest_cookie config
config/currency.php:13 'guest_cookie_name' => env('GUEST_CURRENCY_COOKIE','guest_currency')
config/currency.php:14 'guest_cookie_lifetime' => (int) env('GUEST_CURRENCY_COOKIE_LIFETIME',525960)
config/currency.php:15 'guest_cookie_path' => env('GUEST_CURRENCY_COOKIE_PATH','/')

# Cookie is encrypted (no except)
app/Http/Middleware/EncryptCookies.php:7  protected $except = [];

# Queue is always set, even for authed users
app/Http/Controllers/Api/Currency/CurrencyController.php:44  $preferenceService->setGuestCurrencyCode($currencyCode, $request);

# Adoption sites
packages/marvel/src/Http/Controllers/UserController.php:493,587,1002  adoptGuestCurrencyOnLogin

# CORS is open but not credentialed
config/cors.php:22 'allowed_origins'=>['*'], :28 'supports_credentials'=>false

# Effective resolution (guest is last fallback before catalog)
app/Services/Currency/CurrencyService.php:114  $guestCode = $this->preferenceService->getGuestCurrencyCode();
app/Services/Currency/CurrencyService.php:115  if ($guestCode!==null && !$this->preferenceService->isValidActiveCurrency) clear
```

---

*End of plan — `new/guest_currency_frontend_ownership_plan.md`.*

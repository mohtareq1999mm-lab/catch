# Guest Currency Frontend Ownership — Implementation-Ready Plan

**Status:** PLAN ONLY — 0 lines of code modified
**Date:** 2026-09-07 UTC
**Repository:** `meem` — Laravel 10 (`app/Services/Currency/UserCurrencyPreferenceService.php:12`)
**Backend host:** `meem.mohammedtareq.me` (requester) · `APP_URL=http://localhost` / `http://localhost:8000` in `.env` · `render.yaml` dynamic host
**Frontend hosts:** `http://localhost:3000` (dev) + prod `SHOP_URL=` empty (not declared in repo)
**Doc roots:** existing `api-desc/currency/` (12 files inc. `frontend.md:1`) — request says `api-docs/currency` (does not exist `api-docs: not found`); plan creates `api-docs/currency/README.md` as the implementation contract (see §15)

> **How to use this doc:** An AI or human implementer should be able to execute without re-deciding architecture. Read §1→§3 for intent, §4→§7 for all contracts, §8 for login signal, §13→§14 for CORS/cross-origin, §18→§21 for test / file-matrix / migration / rollback. Track acceptance in §22 checklist.

---

## 1. Executive Architecture Summary

| Layer | Current (backend-owned) | Target (frontend-owned) |
|-------|------------------------|-------------------------|
| **Owner** | `UserCurrencyPreferenceService::setGuestCurrencyCode()` queues `guest_currency=<encrypted>` via `Cookie::make(..., 525960, /)` (`UserCurrencyPreferenceService.php:57`). `EncryptCookies::$except=[]` (`EncryptCookies.php:7`). `HttpOnly=true`, no `Domain`, `SameSite=lax` (default), `Secure=null`. | **Frontend owns `guest_currency`** — plain `EGP`, `HttpOnly=false`, `Path=/`, `SameSite=Lax`, `Secure` auto (false on `http://localhost`, true on `https://`), ~365d, `Domain` omitted for `localhost` or `.meem.mohammedtareq.me` for shared subdomains. **No `EncryptCookies`**. |
| **Writer** | Always `POST /api/v1/general/currencies/select` (`CurrencyController.php:44`). | Frontend JS (`js-cookie` / `document.cookie`) writes before or immediately after `POST /select`. Backend stops queuing cookie when `GUEST_CURRENCY_FRONTEND_OWNED=true`. |
| **Transport to backend** | `Cookie: guest_currency=<encrypted>` (host `meem.mohammedtareq.me`) — browser adds automatically. | **Primary:** `X-Currency: EGP` request header (JS-writable). **Fallback:** `Cookie: guest_currency=EGP` when same-site/localhost (browser adds). Backend prize: `X-Currency` → cookie → catalog. |
| **Reader / validator** | `getGuestCurrencyCode()` decrypts, `strtoupper`, `CurrencyService::getEffectiveCode()` (`CurrencyService.php:82`) validates via `isValidActiveCurrency()`. | New `resolveGuestCurrency()` reads `X-Currency` header **or** cookie, `trim → strtoupper → /^[A-Z]{3}$/ → isValidActiveCurrency`. Authenticated preference `user_preferences` still checked **first** before any guest transport. |
| **Persistence for logged-in** | `user_preferences` row + immediate adoption at login (`UserController.php:493,587,1002`). Guest cookie cleared via `Cookie::forget`. | Same `user_preferences` table, adoption still at login but **signal returned** (`meta.adopted_currency`) so frontend deletes its own cookie. Backend no longer `Set-Cookie: Max-Age=0` when flag true (cannot delete frontend-domain jar). |
| **Authority** | Backend is authoritative (active check). | **Unchanged** — frontend value is hint only; backend ignores inactive/unknown. |

**One-line diagram:**

```
Frontend                            Backend API                     CurrencyService
  | owns guest_currency=EGP  -----> X-Currency: EGP  ------------> validate (is_active)
  | JS-readable, plaintext          Cookie: EGP (fallback)        user_pref > header > cookie > catalog
  | writes/deletes                  returns adopted_currency        effectiveCode
```

---

## 2. Current-State Audit (verified by file read 2026-09-07)

### 2.1 Files inspected (fact) and actual content

| File | Fact (as-read, not assumed) |
|------|-----------------------------|
| `app/Services/Currency/UserCurrencyPreferenceService.php:12` | `DEFAULT_COOKIE_NAME='guest_currency'`, `DEFAULT_COOKIE_LIFETIME=525960`, `DEFAULT_COOKIE_PATH='/'`; `getGuestCurrencyCode()` returns `null` if `$request->cookie()` empty, else `strtoupper`; `setGuestCurrencyCode()` → `Cookie::queue(Cookie::make(name, strtoupper, minutes=config(currency.guest_cookie_lifetime), path=config(currency.guest_cookie_path)))` — no `domain/secure/httpOnly/sameSite` args; `clearGuestCurrencyCode()` → `Cookie::forget`; `adoptGuestCurrencyOnLogin()` checks `Schema::hasTable`, `getUserPreference!==null → return`, reads guest, validates active, `setUserPreference` + `clearGuestCurrencyCode`, return `void`; `isValidActiveCurrency()` checks `currencies where code=strtoupper and is_active=true`. |
| `app/Services/Currency/CurrencyService.php:82` | `getEffectiveCode(?User $user=null)` memoizes, if `!isCurrencySelectionEnabled()` return `catalogCode`; else `$user ??= auth()->user() ?? auth('sanctum')->user()`; if `Schema::hasTable('user_preferences','currencies')` then `preferenceCode=getUserPreference`; if invalid → `clearUserPreference`; if non-null → `return preference`; then `guestCode=getGuestCurrencyCode()`; if invalid → `clearGuestCurrencyCode`; if non-null → `return guest`; fall back to `catalogCode`. |
| `app/Http/Controllers/Api/Currency/CurrencyController.php:32` | `select(SelectCurrencyRequest $request)` does `$code=strtoupper(validated currency_code)`, `$user=auth('sanctum')->user() ?? auth()->user()`, if `$user` then `setUserPreference`, **always** `setGuestCurrencyCode`, `forgetEffectiveCode`, returns `CurrencyResource` 200. |
| `app/Http/Middleware/EncryptCookies.php:7` | `protected $except=[]` — **every** cookie including `guest_currency` is encrypted. |
| `app/Http/Kernel.php:22` | `api` group includes `EncryptCookies` + `AddQueuedCookiesToResponse` — applies to `POST /api/v1/general/currencies/select` and `GET /api/v1/general/*`. |
| `config/currency.php:13` | `guest_cookie_name=>env(GUEST_CURRENCY_COOKIE, guest_currency)`, `guest_cookie_lifetime=>525960`, `guest_cookie_path=>/` — comment says "encrypted, signed" — **stale after migration**. |
| `config/cors.php:22` | `paths=['api/*','sanctum/csrf-cookie']`, `allowed_methods=['*']`, `allowed_origins=['*']`, `allowed_origins_patterns=[]`, `allowed_headers=['*']`, `supports_credentials=false` — wildcard with no credentials. |
| `config/session.php:158` | `domain=>env(SESSION_DOMAIN,null)`, `secure=>env(SESSION_SECURE_COOKIE)`, `same_site=>'lax'` — irrelevant to currency but shows pattern. |
| `routes/api.php:100` | `Route::post('currencies/select', [CurrencyController::class,'select'])` under `prefix v1/general`, middleware `api` + `throttle:public-api`, **public** (`auth` not required). |
| `app/Http/Requests/SelectCurrencyRequest.php:10` | `currency_code: required|string|max:3|Rule::exists('currencies','code')->where('is_active',true)`. |
| `packages/marvel/src/Http/Controllers/UserController.php:493,587,1002` | Three login paths call `adoptGuestCurrencyOnLogin($user,$request)` and ignore return — `token` (password), `verifyLoginOtp` (OTP), `socialLogin` (OAuth). |
| `tests/Feature/Currency/UserCurrencyPreferenceTest.php:52` | `guest_currency_cookie_can_be_queued_and_read` asserts `Cookie::queued('guest_currency')` and `getGuestCurrencyCode` via `Request::cookies->set`. `select_endpoint_sets_the_guest_currency_cookie_for_guests` (`:187`) does `withoutMiddleware(EncryptCookies)` + `assertCookie('guest_currency','KWD')`. |
| `api-desc/currency/frontend.md:1` | Existing storefront spec lists `publicCurrencyApi: list()/select()` and notes selection only effective when `currency_selection_enabled=true`. **Does not** document `X-Currency`. |
| `.env.example` / `.env` / `render.yaml` | `APP_URL=http://localhost` / `:8000`, `SHOP_URL=` empty, `SESSION_DRIVER=redis` (example) / `file` (local), `CORS` env not present. |

### 2.2 How `api-desc/` vs `api-docs/` differs

`api-desc/currency/` has 13 files (api, backend, flow, database, frontend, jira, qa, test-cases, etc.). `api-docs/` **does not exist** (`api-docs: not found` 2026-09-07). This plan treats `api-desc/currency/` as the existing contract source and creates `api-docs/currency/README.md` (new directory) as the frontend implementation contract per request §15, while also proposing an update to `api-desc/currency/frontend.md` for parity. No existing `api-docs/currency` file to merge.

### 2.3 Current resolution order (fact — before change)

```
CurrencyService::getEffectiveCode(null)
  1. memo hit? → return
  2. !isCurrencySelectionEnabled() → catalogCode (USD default)  [DISABLED path: ignores everything]
  3. Schema check → if missing tables → catalog
  4. getUserPreference(user)  [user = auth()->user() ?? auth('sanctum')->user()]
     if invalid active → clearUserPreference → null
     if non-null → return  ← AUTHENTICATED WINS BEFORE GUEST (fact)
  5. getGuestCurrencyCode()   [Request::cookie('guest_currency') decrypted]
     if invalid → clearGuestCurrencyCode → null
     if non-null → return  ← GUEST
  6. return catalogCode
```

Target (§4) inserts `X-Currency` between 4 and 5.

---

## 3. Target-State Architecture

```
                ┌─────────────────────────────────┐
                │  FRONTEND (source of intent)    │
                │  owns guest_currency cookie     │
                │  plaintext EGP, JS-readable     │
                │  HttpOnly=false, Path=/        │
                │  SameSite=Lax, Secure auto      │
                │  Domain=host-only or            │
                │  .meem.mohammedtareq.me         │
                │                                 │
                │  js-cookie + axios interceptor  │
                └──────────┬──────────────────────┘
                           │ X-Currency: EGP
                           │ + Cookie: guest_currency=EGP (same-site fallback)
                           │ + Authorization: Bearer <token> (if authed)
                           ▼
                ┌─────────────────────────────────┐
                │  BACKEND API (authoritative)    │
                │  reads X-Currency → cookie      │
                │  validates → is_active          │
                │  resolves effective currency    │
                │  selector: user_pref > header > │
                │  cookie > catalog               │
                │  returns CurrencyResource +     │
                │  meta.adopted_currency signal   │
                └──────────┬──────────────────────┘
                           │
                           ▼
                ┌─────────────────────────────────┐
                │  CurrencyService                │
                │  isCurrencySelectionEnabled?    │
                │  → effectiveCode                │
                └─────────────────────────────────┘
```

Invariants:
- Frontend value is **preference/intent**, never trusted blindly.
- Backend ignores inactive/unknown/empty/malformed, falls back.
- `user_preferences` row is source of truth for authenticated users.

---

## 4. Request Resolution Priority (exact algorithm)

### 4.1 Final priority

```
1. Authenticated user currency preference   [user_preferences.currency_code where is_active]
        ↓ (if missing or inactive → fall through)
2. X-Currency request header               [new primary guest transport]
        ↓ (if invalid/inactive/empty → fall through)
3. guest_currency cookie (legacy fallback) [Request::cookie('guest_currency')]
        ↓ (if invalid/inactive/empty → fall through)
4. catalog/default currency                [settings.options.catalog_currency_code → USD]
```

**Authenticated preference still wins before guest transport.** This matches current `CurrencyService.php:82` order (user before guest). The plan does **not** reorder authenticated above header — header is still guest intent, not user intent. For an authenticated user with `SAR` preference, `X-Currency: USD` is ignored (user already has SAR). This is intentional per §1 main objective bullet 5-6: backend persists currency for authenticated users first.

### 4.2 Normalization (single function — call it everywhere)

```php
function normalizeCurrencyInput(?string $raw): ?string {
    if ($raw === null) return null;
    $t = trim($raw);               // whitespace
    if ($t === '') return null;    // empty/missing
    $u = strtoupper($t);           // lowercase → uppercase
    if (!preg_match('/^[A-Z]{3}$/', $u)) return null; // malformed → null (includes 2-char, 4-char, numeric, space)
    return $u;                     // e.g. " egp " → "EGP"
}
```

Applied to **both** `X-Currency` header value and cookie value before validation.

### 4.3 Validation & behavior matrix

| Input | Raw | Normalized | Valid active? | Result | Clears? |
|-------|-----|------------|---------------|--------|---------|
| empty/missing header | `null` / `""` / `"   "` | `null` | — | skip, try cookie | no |
| lowercase | `egp` | `EGP` | yes if active | **EGP** | no |
| whitespace | `" EGP "` | `EGP` | yes | EGP | no |
| invalid code | `XXX` | `XXX` | `exists? false` | skip | no (header not persisted; cookie path reads `XXX` then `clearGuestCurrencyCode()` lazily once) |
| inactive | `KWD` where `is_active=false` | `KWD` | false | skip, clear cookie if from cookie source | `clearGuestCurrencyCode()` |
| malformed | `EG`, `EGP1`, `12`, `EG-P` | `null` | — | skip | no (header) / ignore (cookie) |
| header `USD` + cookie `SAR` | header wins | — | first valid wins → `USD` | cookie ignored | not cleared |
| authenticated user has `SAR`, header `USD`, cookie `EGP` | — | — | returns `SAR` (step 1) | header/cookie not consulted | none |

If **all** guest transports fail, return `catalogCode`. If `currency_selection_enabled=false`, return `catalogCode` **immediately** without reading any guest transport (existing gating).

### 4.4 Pseudocode (exact — implement in `UserCurrencyPreferenceService`)

```php
public function getHeaderCurrencyCode(?Request $request = null): ?string {
    $request ??= request();
    $raw = $request?->header('X-Currency'); // case-insensitive per Symfony
    $norm = $this->normalize($raw);
    return $norm; // may be null
}

private function normalize(?string $raw): ?string {
    if ($raw === null) return null;
    $t = trim($raw);
    if ($t === '') return null;
    $u = strtoupper($t);
    return preg_match('/^[A-Z]{3}$/', $u) ? $u : null;
}

public function resolveGuestCurrency(?Request $request = null): ?string {
    $request ??= request();
    // 1. header first (new)
    $h = $this->getHeaderCurrencyCode($request);
    if ($h !== null) return $h;
    // 2. cookie fallback (legacy + localhost shared)
    return $this->getGuestCurrencyCode($request);
}

public function getEffectiveGuestCode(?Request $request = null): ?string { // validated wrapper
    $code = $this->resolveGuestCurrency($request);
    if ($code !== null && !$this->isValidActiveCurrency($code)) {
        // if code came from cookie, clear — but don't clear header (stateless)
        if ($request?->cookie($this->cookieName()) !== null) $this->clearGuestCurrencyCode($request);
        return null;
    }
    return $code;
}
```

`CurrencyService::getEffectiveCode()` then calls `preferenceService->getEffectiveGuestCode()` instead of `getGuestCurrencyCode()` directly.

---

## 5. Backend Cookie Changes

### 5.1 New cookie spec

```
guest_currency=EGP
  Value:          uppercase 3-letter ISO 4217 (EGP, USD, SAR, KWD…) — validated.
  Path:           /
  Max-Age:        31536000 (365d) or 31557600 (525960m legacy) — either; keep 525960 for BC.
  SameSite:       Lax
  Secure:         true on https://*   (false on http://localhost:*)
  HttpOnly:       false               ← must be JS-readable
  Domain:         omitted              → host-only (localhost, single host)
                  OR  .meem.mohammedtareq.me → shared suffix (when shards share parent)
  Encryption:     none (plaintext)
  Lifetime:       ~1 year, ~sticky
```

### 5.2 `app/Http/Middleware/EncryptCookies.php` — exact change

```php
// Before
protected $except = [];

// After (frontend-owned)
protected $except = [
    'guest_currency',
];
```

**Why required:** Laravel's `EncryptCookies` encrypts every cookie not in `except` with AES-256-CBC + HMAC and decrypts on ingress. Current `guest_currency` is `guest_currency=eyJpdiI6...; HttpOnly`. If frontend writes `guest_currency=EGP` (plaintext `EGP`) and the middleware still tries to decrypt, two failures occur:

1. On request: `Request::cookie('guest_currency')` would attempt `decrypt('EGP')` → `DecryptException` (or return garbled), never matching `/^[A-Z]{3}$/`.
2. On response: any legacy backend-queued `Set-Cookie` would be re-encrypted to `eyJ...`, making it unreadable to JS.

Adding `guest_currency` to `$except` disables both directions — cookie is sent as plaintext `EGP`, read as `EGP`. This is the **single required middleware change**.

**Why not keep encryption:** Value is public, small, and must be readable. Encryption would hide it from `document.cookie` (`HttpOnly` aside) and would require JS to decrypt with APP_KEY (impossible/silly). Plaintext with server validation is correct for non-sensitive preference.

**Legacy cookie migration (one-request self-heal):** Browsers that still hold `guest_currency=eyJ...` (encrypted, ~200 chars) will send `eyJ...` on the next request. After `$except` is added, `normalize('eyJ...') → null` (fails `/^[A-Z]{3}$/`) → fallback to catalog, no adoption. Next `POST /select` or next frontend `setGuestCurrency(EGP)` overwrites with `EGP`. No manual purge required.

### 5.3 `config/currency.php` — exact additions

```php
return [
    'guest_cookie_name' => env('GUEST_CURRENCY_COOKIE', 'guest_currency'),
    'guest_cookie_lifetime' => (int) env('GUEST_CURRENCY_COOKIE_LIFETIME', 525960),
    'guest_cookie_path' => env('GUEST_CURRENCY_COOKIE_PATH', '/'),
    // New — all BC defaults keep old behaviour if env absent
    'guest_cookie_frontend_owned' => env('GUEST_CURRENCY_FRONTEND_OWNED', true),
    'guest_cookie_same_site' => env('GUEST_CURRENCY_SAME_SITE', 'Lax'), // Lax default
    'guest_cookie_secure' => env('GUEST_CURRENCY_SECURE', null), // null=auto from APP_URL is https
    'guest_cookie_http_only' => false,
    'guest_cookie_encrypt' => false,
];
```

Update header comment from "encrypted, signed" → "plaintext, JS-readable, validated server-side when frontend-owned".

### 5.4 `UserCurrencyPreferenceService.php` — cookie queue gating

```php
public function setGuestCurrencyCode(string $currencyCode, ?Request $request=null): void {
    if (config('currency.guest_cookie_frontend_owned', true)) {
        return; // frontend owns writing — no-op to avoid Set-Cookie race / wrong Domain
    }
    // legacy path kept for rollback / host-only deploy
    Cookie::queue(Cookie::make(name:$this->cookieName(), value:strtoupper(...), minutes:..., path:...));
}
public function clearGuestCurrencyCode(?Request $request=null): void {
    if (config('currency.guest_cookie_frontend_owned', true)) {
        // still clear backend host-only jar for cleanliness, but primary clear is frontend-driven
        // Option A: no-op; Option B: still queue forget for BC. Choose A (cleaner) — adoption signal drives frontend clear.
        return;
    }
    Cookie::queue(Cookie::forget(...));
}
```

Kept disabled by default (`GUEST_CURRENCY_FRONTEND_OWNED=true`). Flip to `false` for instant rollback.

---

## 6. Currency Select Endpoint — Current, Target Contract, and Why Not a Parallel API

### 6.1 Current (fact)

`POST /api/v1/general/currencies/select` — `routes/api.php:101` — `throttle:public-api` — public.
- Body: `{"currency_code":"KWD"}` (validated `Rule::exists currencies.code where is_active`).
- Controller: `strtoupper`, if `auth` then `setUserPreference`, always `setGuestCurrencyCode`, `forgetEffectiveCode`, return `CurrencyResource`.

### 6.2 Decision: reuse, do not invent parallel `/currencies/select-header` or `/preferences/guest`

**Rationale:** Existing endpoint already validates and is idempotent. Adding a second endpoint for header transport would duplicate validation, caching, and logging, and would require deprecating the first. Frontend header path works **implicitly** on every request — no dedicated select call is needed for the header to be effective — so the select endpoint can stay body-driven. The cleanest contract is: **keep the body as the explicit contract, add header as an implicit transport for all other endpoints.**

### 6.3 Final contract

```http
POST /api/v1/general/currencies/select HTTP/1.1
Host: meem.mohammedtareq.me    # or http://localhost:8000 in dev
Content-Type: application/json
Accept: application/json
X-Currency: KWD                # optional echo of intent — backend reads body, header is just supplemental
Authorization: Bearer <token>  # optional — present if authenticated
# If authenticated via cookie session (not Bearer), add Cookie: session=...
# Do NOT set Cookie: guest_currency=... manually — X-Currency is the contract.

{"currency_code":"KWD"}
```

**Validation:** body field `currency_code` required `string|max:3` exists active — same as today. Header value **not validated here**; it is validated on every read via `resolveGuestCurrency`. Body remains authoritative for this endpoint; header is allowed to match body but mismatch favours body.

**Response (target — existing envelope, additive `meta`):**

```json
{
  "status": 200,
  "message": "Currency updated successfully",
  "success": true,
  "data": {
    "id": 2,
    "code": "KWD",
    "name": "Kuwaiti Dinar",
    "symbol": "KD",
    "country_name": "Kuwait",
    "numeric_code": "414",
    "decimal_places": 3,
    "icon": "kw",
    "is_active": true,
    "sort_order": 2,
    "is_base": false,
    "is_catalog": false,
    "created_at": "2026-08-10T00:00:00+00:00"
  },
  "meta": {
    "effective_currency": "KWD",
    "preference_source": "user" 
  }
}
```

- For guest (no auth): `"preference_source":"guest"` (means header/cookie will drive next requests).
- `preference_source` values: `user` | `guest` | `catalog`.
- `effective_currency` is the post-write `CurrencyService::getEffectiveCode()` result — lets frontend reconcile without extra GET.

**No breaking change:** calling code that ignores `meta` continues to work. Add `X-Adopted-Currency: KWD` header as well if frontend prefers header contract (redundant safety).

**Headers returned (optional):**

```
X-Effective-Currency: KWD
X-Preference-Source: guest
```

Expose via `Access-Control-Expose-Headers: X-Effective-Currency, X-Preference-Source, X-Adopted-Currency`.

---

## 7. Frontend API Documentation — Implementation-Ready Contracts

> Source of truth for frontend dev. Currency list, reading, header injection, change flow, adoption, deletion, errors, and “do not” list are all here. This section is written to be copied as `api-docs/currency/README.md` (§15).

### 7.1 Currency list — `GET /api/v1/general/currencies`

| Field | Value |
|-------|-------|
| Method | `GET` |
| URL | `/api/v1/general/currencies` |
| Full local | `http://localhost:8000/api/v1/general/currencies` |
| Prod | `https://meem.mohammedtareq.me/api/v1/general/currencies` |
| Auth | none (`throttle:public-api`) |
| Headers | `Accept: application/json` (+ optional `X-Currency` — ignored here) |
| Query | `?limit=` (not used for this endpoint today — returns all active) |
| Cache | Server caches `currencies` tag 4h under `md5(fullUrl)`; frontend **may** cache 60s or until `select` — but **must not** hardcode list. |

Example:

```http
GET /api/v1/general/currencies HTTP/1.1
Host: meem.mohammedtareq.me
Accept: application/json

HTTP/1.1 200 OK
{"status":200,"message":"Data fetched successfully","success":true,"data":{ "data":[ {"id":1,"code":"USD",...}, {"id":2,"code":"KWD",...}, {"id":3,"code":"SAR",...}, {"code":"EGP",...} ], "total":4, ... }}
```

**When to call:** On app bootstrap (once), cache in `store/currency` (Zustand/Pinia). Refresh after `select` succeeds (the response already contains the selected `CurrencyResource`).

**How selected currency is determined (frontend):**
- If `user` is authed, preferred display is `GET /api/v1/me` → `user.currency_preference` (or via `GET /api/v1/general/settings` → `currency_selection_enabled` gating — if `false`, selected currency is **always catalog** and selector is hidden). Otherwise, `guest_currency` cookie/header (see §7.2).

### 7.2 Reading `guest_currency`

```js
// Uses project's existing cookie lib; if repo uses js-cookie, keep it; else vanilla.
import Cookies from 'js-cookie'; // if available — check package.json frontend (not in backend repo)

const METRIC = 'guest_currency';

function readGuestCurrency() {
  // 1. cookie (UI instant)
  let c = typeof Cookies !== 'undefined' ? Cookies.get(METRIC) : null;
  if (!c) {
    const m = document.cookie.match(/(?:^|;\s*)guest_currency=([^;]*)/);
    c = m ? decodeURIComponent(m[1]) : null;
  }
  if (!c) return null;
  const t = String(c).trim().toUpperCase();
  return /^[A-Z]{3}$/.test(t) ? t : null; // local format check only
}
// guest_currency=EGP  → selected guest currency is EGP
// Invalid/missing → null → fallback to catalog
```

Call `readGuestCurrency()` on bootstrap to hydrate store **before** first `GET /products`.

### 7.3 Cookie contract (what the cookie means)

| Cookie string | Meaning | Frontend action |
|---------------|---------|-----------------|
| `guest_currency=EGP` | guest last selected EGP | use `EGP` as candidate effective until authed preference supersedes |
| `guest_currency=USD` (uppercase) | same | — |
| `guest_currency=egp` (set once lowercase) | normalized to `EGP` on next write; currently read as `EGP` via local upper | rewrite as `EGP` |
| absent | no guest selection | fallback to catalog or header |
| `eyJ...` (legacy encrypted) | stale pre-migration | local read returns `null` (fails format) → ignored, catalog fallback, next user select overwrites with plaintext |

Store: client-side only, never sent manually via `Cookie:` header (browser adds it for same-site).

---

## 8. Frontend Request Header — Global Injection

### 8.1 Rule: every relevant API request MUST send `X-Currency`

Relevant = any request whose response is currency-aware (prices): `GET /api/v1/general/products*`, `GET /api/v1/general/products/{slug}`, `GET /api/v1/cart`, `POST /api/v1/cart/*`, `GET /api/v1/general/settings`, `POST /api/v1/general/currencies/select`, etc. Simplest: **send on all `/api/*` requests** (cheap, idempotent header).

**Do NOT** manually send `Cookie: guest_currency=EGP` — `Cookie` is a forbidden header (browser ignores `setRequestHeader('Cookie',...)`). Send `X-Currency`.

### 8.2 Axios interceptor (when axios is the http client)

```js
import axios from 'axios';
import Cookies from 'js-cookie';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'https://meem.mohammedtareq.me/api',
  withCredentials: false, // see §13 — Bearer auth today, keep false unless backend ever needs cookie auth
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
});

function currentXCurrency() {
  // priority: store state → cookie (same as backend priority but here just guest hint)
  const fromStore = store?.currency?.effective; // e.g. Zustand store after readGuestCurrency()
  const fromCookie = (() => {
    const c = Cookies.get('guest_currency') ?? document.cookie.match(/(?:^|;\s*)guest_currency=([^;]*)/)?.[1];
    if (!c) return null;
    const u = decodeURIComponent(c).trim().toUpperCase();
    return /^[A-Z]{3}$/.test(u) ? u : null;
  })();
  return (fromStore ?? fromCookie ?? null);
}

api.interceptors.request.use(cfg => {
  const cur = currentXCurrency();
  if (cur) cfg.headers['X-Currency'] = cur; // e.g. EGP
  // Also always include Authorization if authed
  return cfg;
});
export default api;
```

### 8.3 Fetch wrapper (if fetch)

```js
export function apiFetch(path, { headers={}, ...opts }={}) {
  const cur = currentXCurrency();
  const h = { Accept:'application/json', ...headers };
  if (cur) h['X-Currency'] = cur;
  const token = authStore?.token;
  if (token) h['Authorization'] = `Bearer ${token}`;
  return fetch(`${API_URL}${path}`, { headers: h, ...opts });
}
```

---

## 9. Example Frontend Requests (wire-real)

### 9.1 List currencies (public, no currency needed)

```http
GET /api/v1/general/currencies HTTP/1.1
Host: meem.mohammedtareq.me
Accept: application/json
```

Response: `200` list with `EGP` included.

### 9.2 Guest product list with EGP intent

```http
GET /api/v1/general/products?limit=15 HTTP/1.1
Host: meem.mohammedtareq.me
Accept: application/json
X-Currency: EGP
# Cookie: guest_currency=EGP  (only when same-site/localhost — cross-site will not include it and that's fine)
```

Backend resolves `EGP` → prices `100 USD` become `~152 EGP` (depends on rates), response header `X-Effective-Currency: EGP`.

### 9.3 Select currency (guest)

```http
POST /api/v1/general/currencies/select HTTP/1.1
Host: meem.mohammedtareq.me
Accept: application/json
Content-Type: application/json
X-Currency: USD

{"currency_code":"KWD"}

HTTP/1.1 200 OK
X-Effective-Currency: KWD
X-Preference-Source: guest
{"status":200,"message":"Currency updated successfully","success":true,"data":{"code":"KWD",...},"meta":{"effective_currency":"KWD","preference_source":"guest"}}
```

Guest frontend: on `200`, `Cookies.set('guest_currency','KWD', {expires:365, path:'/', sameSite:'Lax', secure:isHttps, domain: share? '.meem.mohammedtareq.me': undefined})` and update store to `KWD`. Subsequent gets send `X-Currency: KWD`.

### 9.4 Select as authenticated (writes `user_preferences`)

```http
POST /api/v1/general/currencies/select HTTP/1.1
Host: meem.mohammedtareq.me
Accept: application/json
Content-Type: application/json
X-Currency: KWD
Authorization: Bearer 5|abc...

{"currency_code":"KWD"}

HTTP/1.1 200 OK
{"status":200,"message":"Currency updated successfully","success":true,"data":{"code":"KWD",...},"meta":{"effective_currency":"KWD","preference_source":"user"}}
```

On auth select, also set guest cookie locally for consistency (so a later logout retains last choice if you want).

---

## 10. Currency Change Flow (chosen path: cookie-first, state + header, then confirm with backend)

**Choice rationale:** Write cookie first gives **instant UI** (optimistic) and guarantees the next API request (even if `POST /select` fails due to network) already carries `X-Currency` on retry. If backend rejects, revert. This avoids a loading void where UI waits for `POST` to succeed before reflecting selection, and avoids race where interceptor reads stale store.

```
User clicks USD in currency switcher
        │
        ▼
Frontend (synchronous, 0 network):
  1. normalize USD → USD (trim+upper, /^[A-Z]{3}$/)
  2. Cookies.set('guest_currency','USD', {expires:365, Path=/, SameSite=Lax, Secure=isHttps, Domain=…})
  3. store.setCurrency('USD') → re-render prices optimistically? Optional — prefer confirming with backend to avoid stale rates.
        │
        ▼
  4. api.post('/api/v1/general/currencies/select', {currency_code:'USD'})
     interceptor already sends X-Currency: USD
        │
        ├─► 200 {data:{code:'USD'}, meta:{effective_currency:'USD', preference_source: guest/user}}
        │      Frontend: confirm store = meta.effective_currency or data.code
        │                (server is authoritative — adopt its effective, not your optimistic)
        │                Invalidate product/cart caches (call GET /products again or bust HasCache tag)
        │
        └─► 422 {message:'...' }  (e.g. inactive code)
               Frontend: Cookies.remove('guest_currency') or revert to previous catalog
                         show toast "Invalid currency" from message.php key
                         store = previous
               X-Currency reverts on next request
```

**Do not** do backend-first then cookie (extra RTT before header is correct). Commit locally first, then let server correct.

---

## 11. Login Adoption Flow

### 11.1 Current backend (fact)

`UserController::token` (`:480:493`), `verifyLoginOtp` (`:572:587`), `socialLogin` (`:969:1002`) each call `adoptGuestCurrencyOnLogin($user,$request)` which, if `user_preferences` for `user_id` is empty and guest `EGP` is valid active, inserts `user_preferences` and clears guest cookie (via `Cookie::forget`). Return is `void`.

### 11.2 Target — explicit signal

```
Guest:  Cookies.get('guest_currency') = EGP         (or X-Currency: EGP header carries it)
        │
        ▼
POST /api/v1/token  {email,password}                # interceptor sends X-Currency: EGP
  also: Cookie: guest_currency=EGP (same-site) or not (cross-site — header covers it)
        │
        ▼
Backend authenticate → user id=42
        │
        ▼
adoptGuestCurrencyOnLogin(user, request):
  if !Schema::hasTable('user_preferences') → {adopted: null}
  if getUserPreference(user) !== null      → {adopted: null, current: SAR}
  else {
    code = resolveGuestCurrency()   ← header (EGP) or cookie (EGP)
    if code null or !isValidActive  → {adopted: null}
    else {
      setUserPreference(user, code)   → DB insert
      if (!frontendOwned) clearGuestCurrencyCode() else // no Set-Cookie
      return code
    }
  }
        │
        ▼
Response (additive — existing envelope + meta):
HTTP/1.1 200 OK
# when frontendOwned=true: NO Set-Cookie for guest_currency
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "5|abc...",
    "user": { "id":42, "email":"a@b" }
  },
  "meta": {
    "adopted_currency": "EGP",          // null if not adopted
    "effective_currency": "EGP",        // post-adopt getEffectiveCode(user)
    "preference_source": "user"         // user|guest|catalog
  }
}
# Also send headers for loggers:
X-Adopted-Currency: EGP
X-Effective-Currency: EGP

```

If `adoptGuestCurrencyOnLogin` already had a preference (`SAR`), `adopted_currency: null`, `effective_currency: SAR`.

### 11.3 Case table

| Case | Guest `X-Currency` / cookie | User preference before | Backend writes | `meta.adopted_currency` | Frontend action |
|------|------------------------------|------------------------|----------------|-------------------------|-----------------|
| 1. First-time user no pref, guest `EGP` valid | `EGP` | `null` | `user_preferences(42,EGP)` | `"EGP"` | `Cookies.remove('guest_currency')` + `store.set('EGP')` |
| 2. User already `SAR`, guest `USD` valid | `USD` | `SAR` | none | `null` (`effective SAR`) | **Keep** guest cookie (`USD`) — do not delete, do not overwrite store with `USD`; display is `SAR` while authed |
| 3. No guest at login | `null` | `null` | none | `null` | no cookie to clear, stay catalog |
| 4. Invalid `XXX` | `XXX` invalid active | `null` | none | `null` | frontend should not have persisted `XXX`; if it did, remove it on null adopt |
| 5. `currency_selection_enabled=false` | `EGP` | `null` or `SAR` | still creates `user_preferences` if you keep current select behaviour (stores but not effective) — adoption still creates row, effective stays catalog | `"EGP"` even though effective is catalog | clear cookie, but effective visible is catalog — optional: hide selector while disabled |

**Do NOT delete guest cookie after every login blindly** — only when `meta.adopted_currency !== null` (case 1) or when `meta.effective_currency` transitions from guest to user. Deleting in case 2 would lose a guest intent that should survive a later logout.

---

## 12. Frontend Cookie Deletion (exact rules)

| When to delete `guest_currency` | Handler | Why |
|--------------------------------|---------|-----|
| After `POST /api/v1/token` returns `meta.adopted_currency: "EGP"` (case 1) | `if (res.meta?.adopted_currency) Cookies.remove('guest_currency', {path:'/', domain: ...})` | Guest intent has been persisted as user preference — no longer needed |
| After `POST /verify-login-otp` with same `adopted_currency` | same | OTP login path |
| After `POST /social-login` with `adopted_currency` | same | social path |
| After `POST /api/v1/general/currencies/select` as **guest** succeeded — do **not** delete, just keep as `EGP` (still guest) | keep | still guest context |
| After logout — **do not** automatically delete unless you want logout to reset to catalog. Recommended: **keep** `guest_currency` after logout so a new guest session retains last choice; alternate policy: clear on logout to force catalog — pick one and document. | policy | stickiness vs. clean slate |
| On `X-Currency: XXX` rejected (422) — remove invalid value from cookie so next boot doesn't re-send `XXX` | `Cookies.remove` on 422 branch | prevent loop of invalid |

**Domain/path must match** the `set` call (`domain: .meem.mohammedtareq.me` if shared, else `undefined`). Mismatched removal silently no-ops.

---

## 13. Cross-Origin Architecture

### 13.1 Local development (`http://localhost:3000` → `http://localhost:8000`) — FACT

- Same host (`localhost`), different ports → **same-site** per Chrome (`localhost` is registrable host + scheme `http`).
- Cookie `guest_currency=EGP; Path=/; SameSite=Lax` **without `Domain`** is host-only and **is** sent cross-port. Set `Secure=false` (http).
- Request needs `fetch(..., {credentials:'include'})` / `axios {withCredentials:true}` for credentials layer (even if auth is Bearer, keep consistent). CORS must be `Allow-Origin: http://localhost:3000` (or 5173 for Vite) + `Allow-Credentials:true`. Browser rejects `Allow-Origin:*` with credentials.
- Test: `curl -H Origin:http://localhost:3000 http://localhost:8000/api/v1/general/products -H X-Currency:EGP -v` must show `Access-Control-Allow-Origin: http://localhost:3000` + `Access-Control-Allow-Credentials: true`.

### 13.2 Same parent domain (`https://app.meem.mohammedtareq.me` + `https://api.meem.mohammedtareq.me`)

- **Shared cookie possible.** Set `guest_currency` with `Domain=.meem.mohammedtareq.me; Path=/; SameSite=Lax; Secure=true`. Browser sends it to **both** shards.
- Still send `X-Currency: EGP` header redundantly (cheap; covers case where cookie jar disagrees).
- Move frontend to `*.meem.mohammedtareq.me` if you want the pure cookie path without header dependency.

### 13.3 Unrelated domains (`https://frontend.vercel.app` → `https://meem.mohammedtareq.me`) — the current likely production

**Why cookie cannot be shared (must-know browser rule):**
1. `Document` on `vercel.app` setting `document.cookie="guest_currency=EGP; Domain=meem.mohammedtareq.me"` is **discarded** — `Domain` must be a parent of the document’s registrable domain.
2. Backend `Set-Cookie: guest_currency=EGP; Domain=meem.mohammedtareq.me` is stored under the **backend jar**, not readable by `vercel.app` JS (even if `HttpOnly=false`, it’s not its document).
3. Therefore a **single shared cookie is impossible** for unrelated domains. Any plan that claims `Domain=frontend.vercel.app` **or** `Domain=meem.mohammedtareq.me` from the other side is invalid.

**Correct for unrelated:** Keep a **UI-only cookie** on `frontend.vercel.app` (`guest_currency=EGP`) for instant bootstrap, but the **authority channel to the API is always `X-Currency: EGP` header**. Backend never relies on receiving `Cookie: guest_currency` in this topology — it reads `X-Currency`. This is spec-compliant and the only valid design.

---

## 14. CORS — Exact Contract

### 14.1 Current (broken for credentials, fact)

```php
// config/cors.php:22
'paths'=>['api/*','sanctum/csrf-cookie'],
'allowed_methods'=>['*'],
'allowed_origins'=>['*'],
'allowed_origins_patterns'=>[],
'allowed_headers'=>['*'],
'supports_credentials'=>false,
```

With `supports_credentials=false`, cookies/`credentials:include` are stripped. With `*` origin + credentials=true, browsers reject. Vercel preview needs pattern `https://.*\.vercel\.app`.

### 14.2 Target (exact values to put in `.env` / `config/cors.php`)

```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:5173,https://app.meem.mohammedtareq.me,https://meem.mohammedtareq.me
CORS_ALLOWED_ORIGINS_PATTERNS=https://.*\.vercel\.app
CORS_SUPPORTS_CREDENTIALS=true
CORS_ALLOWED_HEADERS=Content-Type,Accept,Authorization,X-Requested-With,X-Currency,X-Adopted-Currency,X-Effective-Currency,X-Preference-Source
CORS_EXPOSED_HEADERS=X-Effective-Currency,X-Preference-Source,X-Adopted-Currency
```

In `config/cors.php`:

```php
'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')))),
'allowed_origins_patterns' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS_PATTERNS','')))) ?: ['#^https://.*\.vercel\.app$#'],
'allowed_headers' => array_map('trim', explode(',', env('CORS_ALLOWED_HEADERS','Content-Type,Accept,Authorization,X-Requested-With,X-Currency'))),
'exposed_headers' => array_map('trim', explode(',', env('CORS_EXPOSED_HEADERS','X-Effective-Currency,X-Preference-Source,X-Adopted-Currency'))),
'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),
```

Also add header `Vary: Origin` automatically.

### 14.3 Credentials vs. Bearer vs. X-Currency (explicit — they are distinct)

| Concept | Requires `credentials:include`? | Requires `Allow-Credentials:true`? | Used here? |
|---------|--------------------------------|-----------------------------------|-----------|
| `Cookie: guest_currency=EGP` (guest currency) | **Yes**, if you rely on Cookie transport (localhost/shared-subdomain). | **Yes**. | Only when same-site; cross-site uses header. |
| `Cookie: session=...` (Sanctum SPA cookie, if ever used) | Yes. | Yes. | **Not used today** — auth is `Authorization: Bearer <Sanctum token>` (header, not cookie). |
| `Authorization: Bearer <token>` | **No**. | **No.** | **Primary auth.** |
| `X-Currency: EGP` | **No** (custom header is header, not cookie). | **No**, but must be in `Allow-Headers`. If origin not in allowlist, the whole request fails before header even matters, so the CORS origin fix is still prerequisite. | **Primary guest-currency channel for cross-site.** |

**Do NOT flip `supports_credentials=true` unless you need Cookie transport** — but this plan **does** need it for localhost/shared-subdomain Cookie path. So set it true even though `X-Currency` alone doesn't require it. Failure mode if left false: `fetch(credentials:include)` silently drops cookies → `Cookie: guest_currency` fallback never arrives (header still arrives, so not fatal cross-site — but local parity broken).

---

## 15. API Documentation File — Where and What

- Request says `api-docs/currency` → `api-docs: not found`. Existing is `api-desc/currency/` (12 files). This plan creates **`api-docs/currency/README.md`** (new directory, frontend-contract) per request §15, and proposes to **copy it** to `api-desc/currency/frontend.md` for parity.
- Required contents (all included in this doc §7→§10): currency endpoints, request examples, response examples, headers, cookie behaviour, currency state management, currency change flow, login adoption flow, error cases, cross-origin notes, axios/fetch guidance, do-not-do rules.
- Env to generate exact request/response examples: run with actual seed `USD/KWD/SAR/EGP` from `CurrencyTestCase.php:111` (KWD rate `0.2210000000`, SAR `3.7500000000`) — examples in this plan reflect real resource shape (`CurrencyResource` 11 fields).

---

## 16. Frontend "Do Not Do" / "Do" Rules

```text
DO NOT:

- manually send the Cookie header         → browsers ignore setRequestHeader('Cookie'); use X-Currency.
- assume the currency cookie is HttpOnly  → it is readable; targeting HttpOnly will leave JS with null.
- encrypt guest_currency on the frontend  → plaintext only; backend validates, not decrypts.
- sign guest_currency                     → no HMAC needed; validation is authoritative. Reserve for future if needed.
- trust the cookie as authoritative       → always fallback to catalog if inactive; server is truth.
- assume every currency code is valid     → must be /^[A-Z]{3}$/ and active in DB; otherwise catalog.
- hardcode the active currency list       → GET /api/v1/general/currencies on bootstrap, refresh after select.
- overwrite authenticated user preference with guest preference → user preference wins; adopt only when empty.
- delete guest_currency after every login blindly → only when meta.adopted_currency !== null.
- create a second currency persistence mechanism unnecessarily → single source: X-Currency derived from store+cookie.
```

```text
DO:

- read guest_currency via js-cookie or document.cookie
- write guest_currency with Path=/, SameSite=Lax, Secure auto, Domain per §13, Max-Age 31536000
- normalize value: trim → uppercase → 3 letters before write; re-normalize on read
- send X-Currency on every /api/* request via Axios interceptor / fetch wrapper (§8)
- use backend currency list (GET /api/v1/general/currencies) as the allowed set
- trust backend validation — if backend returns X-Effective-Currency different from your X-Currency, reconcile store to it
- react to meta.adopted_currency (login/select) and meta.preference_source
- keep authenticated preference separate from guest preference in store (guest: cookies+header; user: user_preferences via /me)
- send withCredentials: true / credentials: include for same-site cookie parity (even if auth is Bearer)
```

---

## 17. Error Handling (frontend must implement)

| Backend status | Body (`api-desc/currency/api.md` envelope) | When it happens for currency | Frontend behaviour |
|---------------|--------------------------------------------|------------------------------|--------------------|
| `422` | `{"status":422,"message":"The selected currency code is invalid.","success":false,"errors":{"currency_code":["..."]}}` | `POST /select` body `currency_code` inactive/unknown/missing/max3 fail | **Do not** persist `XXX` to cookie. Revert: `Cookies.remove` or rewrite to previous valid or catalog. Show toast from `response.data.message` (i18n key). Keep store on previous `effective_currency`. |
| `422` (guest header only) | No explicit error — header path **never** 422s; invalid header silently falls back to catalog | `GET /products` with `X-Currency: XXX` | No error — prices come back in catalog (`USD`). Frontend may notice `X-Effective-Currency: USD != X-Currency: XXX` and replace cookie with `USD` or remove `XXX`. Do not persist `XXX`. |
| `401` | `{"status":401,"message":"Unauthenticated.","success":false}` | `POST /select` never; authed `POST /select` with expired token | Route is public, so 401 only if caller added `auth:sanctum` middleware mistakenly — just re-auth. |
| `403` | — | Admin routes only | N/A |
| `429` | `throttle:public-api` | hammering `/select` | Back off, show "too fast". |
| `5xx` | — | rate missing/no base currency | Show generic retry, keep previous currency. Log to Sentry. |

**Key policy:** On any `422` for `select`, **invalidate the cookie** that mirrors the bad value (remove `XXX`), so the next app reload doesn't re-send `X-Currency: XXX` → fallback loop.

**Header-triggered invalid:** No 422, but best to surface: if backend echoes `X-Effective-Currency: USD` while `X-Currency: XXX` was sent, treat as soft-invalid → correct cookie.

---

## 18. Testing Plan

### 18.1 Backend (PHP — `tests/Feature/Currency/*`)

Rework existing + new:

| # | Test | Setup | Assert |
|---|------|-------|--------|
| B1 | `guest_currency_cookie_can_be_queued_and_read` → rework | `Request::create` with `Cookie: EGP` + `X-Currency: SAR` | `resolveGuestCurrency()==SAR` (header wins), `getGuestCurrencyCode()==EGP` (raw cookie unchanged) |
| B2 | `guest_currency_cookie_can_be_cleared` → header-priority test | `X-Currency: EGP` + `Cookie: EGP` + flag true | `setGuestCurrencyCode` queues 0 cookies, `clearGuestCurrencyCode` queues 0 |
| B3 | `header_currency_normalized_lowercase_and_whitespace` | `X-Currency:  egp ` | `resolve==EGP`, `effective==EGP` |
| B4 | `invalid_XCurrency_falls_back_to_cookie` | `X-Currency: XXX` + `Cookie: EGP` valid | `resolve==XXX` → validated null → fallback `Cookie EGP` → `effective EGP` |
| B5 | `inactive_XCurrency_ignored` | make `EGP` is_active false + `X-Currency: EGP` + `Cookie: KWD` valid | resolves via header `EGP` → inactive → fallback KWD |
| B6 | `malformed_header_ignored` | `X-Currency: 12` + `Cookie absent` | null → catalog |
| B7 | `header_precedence_over_cookie` | both present valid | header wins |
| B8 | `authenticated_preference_precedence_over_header_and_cookie` | user pref `SAR`, header `EGP`, cookie `KWD` | `effective==SAR` |
| B9 | `login_adopts_header_when_no_pref` | user `null` pref, `X-Currency: KWD` → `POST /token` | `user_preferences==KWD` + `meta.adopted_currency==KWD` |
| B10 | `login_does_not_override_existing` | user `SAR` + header `KWD` | `SAR` unchanged, `adopted==null` |
| B11 | `login_does_not_queue_guest_cookie_when_frontend_owned` | authed `select` with flag true | `assertCookieMissing('guest_currency')` |
| B12 | `login_signal_contains_effective_and_source` | `POST /select` guest + authed variants | `meta.effective_currency` + `preference_source` header present |
| B13 | `guest_XCurrency_affects_product_cache_key` | seed `KWD`/`SAR` as in `UserCurrencyPreferenceTest:212` (22.1 vs 375.0) | `GET /general/products` with `X-Currency: KWD` → 22.1, then `SAR` → 375.0, tags `products_index` keys `_KWD`/`_SAR` distinct |
| B14 | `legacy_encrypted_cookie_migrates` | `Cookie: eyJp...fake` + `X-Currency: EGP` | invalid cookie ignored, header `EGP` wins (self-heal) |

Keep `CurrencySelectionEnabledTest.php:30` gating tests — when `currency_selection_enabled=false`, ensure header/cookie both ignored.

### 18.2 Frontend (JS — `__tests__/currency.*`)

| # | Scope | Check |
|---|-------|-------|
| F1 | read cookie | `set Cookies.set('guest_currency','EGP')` → `readGuestCurrency()==EGP`; with no cookie → `null` |
| F2 | set cookie | `setGuestCurrency('egp')` writes `EGP` with `Path=/, SameSite=Lax`; on https adds `Secure`; on localhost omits `Domain`; on shared subdomain sets `Domain=.meem.mohammedtareq.me` |
| F3 | delete cookie | `clearGuestCurrency()` → `document.cookie` no longer contains `guest_currency`; next `read==null` |
| F4 | initial resolution | bootstrap reads guest `EGP` → store `EGP` before first `GET /currencies`; if `currency_selection_enabled=false` shows catalog badge and hides selector |
| F5 | API header injection | axios interceptor on `GET /products` sends `X-Currency: EGP`; fetch wrapper does same |
| F6 | currency switching USD→EGP | click switcher → cookie `USD`, store `USD`, `POST /select {currency_code:USD}` + header `USD` → on 200 store `USD` (meta.effective), on 422 revert to previous |
| F7 | login adoption (case 1) | set `guest_currency=EGP`, call `POST /token` with `X-Currency:EGP` → mock 200 `{meta:{adopted_currency:'EGP'}}` → assert `Cookies.remove` called, store now `EGP` from user |
| F8 | login not override (case 2) | user pref `SAR`, guest `USD` → mock 200 `{meta:{adopted_currency:null, effective_currency:'SAR'}}` → assert cookie **not** removed, store `SAR` |
| F9 | invalid currency response | mock `422` for `POST /select {"currency_code":"XXX"}` → assert cookie `XXX` removed, toast shown, store reverts |
| F10 | logout behaviour | configurable: after logout guest cookie stays `EGP` (sticky) or cleared — test per chosen policy |
| F11 | refresh persistence | set `guest_currency=EGP`, reload → `readGuestCurrency()` still `EGP` |
| F12 | malformed cookie | set `guest_currency=12` (2 chars) → `read==null` → ignored |
| F13 | API failure during change | mock `500` on `POST /select` → cookie reverted, toast "try again" |
| F14 | withCredentials | ensure axios `withCredentials:false` (Bearer) vs true (if you later add cookie auth) — assert header still sent regardless |

---

## 19. Exact Files To Change (and Files That Must Not Change)

| File | Change | Why | Layer |
|------|--------|-----|-------|
| `app/Services/Currency/UserCurrencyPreferenceService.php` | Add `normalize()`, `getHeaderCurrencyCode(?Request)`, `resolveGuestCurrency()`, `getEffectiveGuestCode()`; gate `setGuestCurrencyCode`/`clearGuestCurrencyCode` with `config(currency.guest_cookie_frontend_owned)`; change `adoptGuestCurrencyOnLogin(): ?string` to return adopted code & prefer header | Core guest resolution + adoption signal | Backend |
| `app/Services/Currency/CurrencyService.php` | `getEffectiveCode()` call `preferenceService->getEffectiveGuestCode()` / `resolveGuestCurrency()` instead of `getGuestCurrencyCode()`; add header read path; keep gating & `clear*` logic header-aware | Wires new priority | Backend |
| `app/Http/Middleware/EncryptCookies.php` | `protected $except=['guest_currency']` | Plaintext JS-readable | Backend |
| `app/Http/Controllers/Api/Currency/CurrencyController.php` | `select()` only `setUserPreference` when authed; **skip** `setGuestCurrencyCode` when `frontend_owned=true`; add `meta` + `X-Effective-Currency`/`X-Preference-Source` to response; also set exposed headers | Frontend owns cookie | Backend |
| `packages/marvel/src/Http/Controllers/UserController.php` | `token()`/`verifyLoginOtp()`/`socialLogin()` capture `adopted = adoptGuestCurrencyOnLogin()` and include `meta.adopted_currency`, `meta.effective_currency`, `meta.preference_source` in JSON | Adoption signal | Backend |
| `config/currency.php` | Add `guest_cookie_frontend_owned`, `guest_cookie_same_site`, `guest_cookie_secure`, `guest_cookie_http_only`, `guest_cookie_encrypt` keys; update comment | Config | Backend |
| `config/cors.php` | `allowed_origins` → explicit list from `CORS_ALLOWED_ORIGINS`; `allowed_origins_patterns` → `https://.*\.vercel\.app`; `supports_credentials=>env(...)` true; `allowed_headers` includes `X-Currency`; `exposed_headers` includes adoption/effective headers | CORS | Backend |
| `.env.example` / `.env` / `render.yaml` | Add `GUEST_CURRENCY_FRONTEND_OWNED=true`, `GUEST_CURRENCY_SAME_SITE=Lax`, `GUEST_CURRENCY_SECURE=`, `CORS_ALLOWED_ORIGINS`, `CORS_ALLOWED_ORIGINS_PATTERNS`, `CORS_SUPPORTS_CREDENTIALS` | Env | Backend |
| `tests/Feature/Currency/UserCurrencyPreferenceTest.php` | Parameterize for `frontendOwned true/false`; replace `assertCookie` with `assertCookieMissing` when true; add header-precedence/inactive/malformed/login adoption-signal tests | Tests | Backend |
| `tests/Feature/Currency/CurrencySelectionEnabledTest.php` | Add header ignored when `currency_selection_enabled=false` plus cross-precedence tests | Tests | Backend |
| `api-desc/currency/frontend.md` | Patch §Notes to add `X-Currency` header contract (keep while `api-docs/currency/` is the new source) | Docs | Backend/Docs |
| `api-docs/currency/README.md` | **Create** — full frontend implementation contract (see §7→§10 of this plan) | Docs | Backend/Docs (serves frontend) |
| Frontend `lib/currency.ts` | Implement `read/set/clearGuestCurrency()` helpers env-aware | — | **Frontend** |
| Frontend `lib/api.ts` or `lib/axios.ts` / `lib/fetch.ts` | Interceptor that adds `X-Currency` on every `/api/*` | — | **Frontend** |
| Frontend `store/currency.ts` | Hydrate from `readGuestCurrency()` on bootstrap, reconcile with `meta.effective_currency` | — | **Frontend** |
| Frontend `components/CurrencySwitcher.vue` / `.tsx` | Cookie-first optimistic update → `POST /select` → reconcile | — | **Frontend** |

**Files that MUST NOT change (scope lock):**

| File | Reason |
|------|--------|
| `config/session.php` | session cookie unrelated; don't change `SESSION_DOMAIN`/`SESSION_SECURE` unless sub-domain session sharing is a separate ticket |
| `app/Http/Kernel.php` | keep middleware groups; only `EncryptCookies` except changes |
| `app/Http/Middleware/TrustProxies.php` | not currency |
| `app/Models/Currency.php`, `CurrencyRate.php`, `CurrencyRateService.php` | data layer not cookie |
| `database/migrations/2026_08_11_000002_create_user_preferences_table.php` | schema OK |
| `app/Http/Requests/SelectCurrencyRequest.php` | validation OK (body) |
| All `api-desc/currency/` files except `frontend.md` | avoid docs drift; new contract is `api-docs/currency/` |
| `render.yaml` service shape beyond env adds | don't change `runtime:docker` or `healthCheckPath` |

---

## 20. Migration Sequence (phases — exact changes + deps + acceptance)

| Phase | Files | Exact changes | Depends on | Acceptance criteria |
|-------|-------|---------------|------------|---------------------|
| **Phase 1 — Backend contract (no behaviour yet)** | `config/currency.php`, `.env.example`, `config/cors.php` (draft) | Add `GUEST_CURRENCY_FRONTEND_OWNED` flag default `false` (off), plus `same_site/secure` keys. Keep `EncryptCookies` unchanged. | none | `php artisan config:show currency` shows flag `false`; `POST /select` still queues cookie; `GET /general/currencies` still returns 200. |
| **Phase 2 — Backend cookie ownership flip (core)** | `UserCurrencyPreferenceService.php`, `EncryptCookies.php`, `CurrencyService.php` | Add `resolveGuestCurrency()`, `getHeaderCurrencyCode()`, `normalize()`; gate `set/clearGuestCurrencyCode` with flag; make `adoptGuestCurrencyOnLogin():?string` prefer header; add `guest_currency` to `EncryptCookies::$except` (guard: only active when flag true, else conditional). | Phase 1 (flag exists) | With flag false: identical to old — `Set-Cookie` still emitted. Flag true: `POST /select` guest returns no cookie (`assertCookieMissing`), `X-Currency: egp` → `EGP` effective, `eyJ...` legacy fallback → catalog. |
| **Phase 3 — Currency resolver header support** | `CurrencyService.php` | Switch `getEffectiveCode()` to `resolveGuestCurrency()`/`getEffectiveGuestCode()` (header→cookie). Keep `isCurrencySelectionEnabled` gating. | Phase 2 | Unit: B3–B8 green; `GET /products` with `X-Currency:EGP` vs `KWD` shows `22.1` vs `375.0` (products_cached_per_effective_currency analogue). |
| **Phase 4 — Login adoption signal** | `UserController.php` ×3 methods | Include `meta.adopted_currency`, `meta.effective_currency`, `meta.preference_source` in JSON + `X-Adopted-Currency` header. `adoptGuestCurrencyOnLogin` returns adopted code. | Phase 2 | Login with `X-Currency:KWD` no prior pref → `user_preferences=KWD` + `meta.adopted_currency=KWD`; with existing `SAR` → not overwritten. |
| **Phase 5 — Backend tests** | `tests/Feature/Currency/*` | Parameterized tests B1–B14 green at both flag states; `CurrencySelectionEnabledTest` gated. | Phases 2-4 | `php artisan test --filter=UserCurrencyPreference` 12/12 green. |
| **Phase 6 — API documentation** | `api-docs/currency/README.md` (create), `api-desc/currency/frontend.md` (patch) | Write §7→§12 of this plan as the contract — exact paths, headers, cookie attrs, flows, errors, do-not list. | Phase 2-3 | Frontend lead can implement without asking backend. |
| **Phase 7 — Frontend cookie ownership** | `lib/currency.ts` | `read/set/clearGuestCurrency()` with env-aware `Domain/SameSite/Secure` (js-cookie) | Phase 6 | `document.cookie` shows `guest_currency=EGP; Path=/; SameSite=Lax` → `read==EGP`. |
| **Phase 8 — Frontend X-Currency injection** | `lib/api.ts` | Axios interceptor / fetch wrapper adds `X-Currency` on every `/api/*` | Phase 7 | Network tab: every `/api/v1/general/products` carries `X-Currency: EGP`; do not manually set `Cookie:`. |
| **Phase 9 — Frontend currency selector** | `components/CurrencySwitcher.vue` + `store/currency.ts` | Bootstrap hydrate from cookie → before first `GET /products`; selector list from `GET /currencies`; on click cookie-first (`Cookies.set`) + store + `POST /select` → reconcile with `meta.effective_currency`. | Phase 8 | Click USD → cookie `USD`, header `USD`, 200, products re-fetched in `USD`. |
| **Phase 10 — Login adoption handling** | `lib/auth.ts` | After `POST /token` etc., if `meta.adopted_currency` non-null → `Cookies.remove('guest_currency')`; else keep. | Phase 4+7 | Case1 clears, Case2 keeps. |
| **Phase 11 — Integration testing** | E2E `__tests__/` | F1–F14 frontend + requests from §9 examples; cross-port localhost matrix | Phase 9-10 | All E1 matrix rows pass (localhost same-site vs cross-site header-only). |
| **Phase 12 — Production configuration** | `.env` / Render dashboard / `render.yaml` env | Flip `GUEST_CURRENCY_FRONTEND_OWNED=true`; set `CORS_ALLOWED_ORIGINS=https://app.meem.mohammedtareq.me,https://<prod-frontend>`; `CORS_ALLOWED_ORIGINS_PATTERNS=https://.*\.vercel\.app`; `CORS_SUPPORTS_CREDENTIALS=true`; `GUEST_CURRENCY_SECURE=true` (https) / `false` (http local); no `SESSION_DOMAIN` change | Phase 11 | `curl -H Origin:https://<prod-frontend> -H X-Currency:EGP https://meem.mohammedtareq.me/api/v1/general/products` returns `X-Effective-Currency: EGP` + `Access-Control-Allow-Credentials:true`. |
| **Phase 13 — Legacy cleanup (7d after)** | `UserCurrencyPreferenceService.php` | Remove flag fallback path (`if frontendOwned return`) if rollback never needed; delete `Set-Cookie` shim. | Phase 12 | Flag `GUEST_CURRENCY_FRONTEND_OWNED` can stay `true` permanently. |

**Order is critical:** Never deploy frontend writer before backend plaintext reader (`EncryptCookies` except + `resolveGuestCurrency`) exists.

---

## 21. Rollback Strategy

| Scenario | Action | Guest pref | User pref | Currency selection | Auth | Cached prices |
|----------|--------|-----------|-----------|-------------------|------|---------------|
| Backend writer race after flip | Set `GUEST_CURRENCY_FRONTEND_OWNED=false` and redeploy (one var). Backend resumes queuing encrypted `guest_currency`. Frontend may still write plaintext; backend will decrypt if it re-encrypts — but plaintext with encryption on will throw — so frontend should also flip local `FRONTEND_OWNED` flag off (no write). | Old encrypted cookie value re-read correctly; plaintext values are ignored (fail format) until overwritten by `POST /select` which now sets encrypted again. | untouched — table `user_preferences` is not cleared on rollback. | still works (body validation). | re-cached under `currency:EGP` etc. regardless — no corruption. |
| Need full return to backend-owned | Deploy revert of `EncryptCookies` except removal + `resolveGuestCurrency` addition (kept header path is additive, no harm). | Keep `user_preferences` — adoption history preserved. | same | same | `HasCache` `currencies/products` flushed on demand via `POST /select`'s `forgetEffectiveCode`. |
| CORS regression (`Allow-Origin:*` with creds) | Put old `allowed_origins=['*']` + `supports_credentials=false` — immediate fix but breaks cookie fallback; header path still works. | no cookie needed for header path | same | header path unaffected | — |
| Corrupted guest cookie in the wild | `normalize` + `isValidActiveCurrency` rejects → catalog fallback; next valid write heals. | self-heals | — | — | — |

Feature flag `GUEST_CURRENCY_FRONTEND_OWNED` is the single kill-switch — no data migration to undo.

---

## 22. Acceptance Criteria (done means ✅)

| Area | Criterion |
|------|-----------|
| Cookie readable | `document.cookie` after storefront load contains `guest_currency=EGP` after any `select` (not HttpOnly, plaintext, `Path=/`, `SameSite=Lax`). |
| Header sent | Every `/api/*` fetch via interceptor carries `X-Currency: <current>` (Network tab). |
| Resolution | `GET /products` with `X-Currency: EGP` → server `X-Effective-Currency: EGP`; with `X-Currency: egp` (lowercase) → `EGP`; with `X-Currency:  XXX` → `USD` (catalog); with header `EGP` + cookie `SAR` → header wins (when both present). |
| Auth pref wins | Auth user `SAR` + header `EGP` → `X-Effective-Currency: SAR`; unauth guest `EGP` via header → `EGP`. |
| Select | `POST /select {currency_code: KWD}` (public) as guest with flag true writes **no** `Set-Cookie`, returns `meta.{effective_currency:"KWD",preference_source:"guest"}`. |
| Adoption Case1 | Guest `EGP`, user null pref → `POST /token` with `X-Currency:EGP` → DB `user_preferences=EGP`, `meta.adopted_currency="EGP"` → frontend deletes guest cookie. |
| Adoption Case2 | Guest `USD`, user `EGP` → login → `adopted_currency:null`, preference still `EGP`, guest cookie `(USD)` **kept**. |
| Invalid | `POST /select {currency_code:"XXX"}` → `422` and `guest_currency` **not** persisted as `XXX`; `GET /products` with `X-Currency: XXX` → no 422, falls back to catalog, frontend corrects cookie. |
| CORS | `OPTIONS /api/v1/general/currencies` with `Origin:https://<prod>` and `Origin:https://preview-123.vercel.app` both get `Access-Control-Allow-Origin: <Origin>` + `Allow-Credentials:true` + `Allow-Headers: X-Currency` + no `*` rejection. |
| Tests | `php artisan test --filter=Currency` green at `frontendOwned true/false`; new B1-B14 + `CurrencySelectionEnabledTest` pass. |
| Docs | `api-docs/currency/README.md` renders exact request/response blocks and `do-not` list; a new frontend dev can implement without asking backend. |
| Migration | One-request `eyJ...` → catalog → healed before second request — no user sees 500. |

---

## 23. Risks & Edge Cases (open)

- `SENDO`: `SESSION_DRIVER=redis` (example) vs `file` (local) — no effect on currency fallback, but if you ever switch to cookie-session auth, re-check CORS credentials.
- Cache key `md5(fullUrl + '|currency:' + effective)` is header-aware only if `getEffectiveCode` is header-aware — already covered by Phase 3.
- `withCredentials:false` (Bearer) is fine for `X-Currency`; if you later add Sanctum cookie session, flip to `withCredentials:true` and keep CORS `supports_credentials=true` (already).
- Wild Vercel previews: regex `https://.*\.vercel\.app` in `allowed_origins_patterns` — do not list `https://*.vercel.app` in `allowed_origins` (wildcard needs pattern). Test with `curl -H Origin:https://fee123-my-app.vercel.app`.

---

*End — `new/guest_currency_implementation_ready_plan.md` (plan only, ready for branch `feat/frontend-owned-guest-currency`).*

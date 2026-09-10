# Currency Flow — Current Real Implementation Audit

**Date:** 2026-09-07 UTC  |  **Mode:** READ-ONLY — 0 files modified
**Scope:** `D:\work\meem` backend only — frontend is a separate SPA (`resources/js/app.js:1` is `require('./bootstrap')` only; no SPA built here; `SHOP_URL=` empty, no frontend checkout code in this repo)
**Question being answered:** “If the user changes currency ONCE, why would I need to manually put currency in every endpoint request? Is the cookie + browser already enough?”

---

## 0. TL;DR — One Paragraph

**You do NOT need to manually put `X-Currency: USD` on every endpoint today, because the backend does not read `X-Currency` at all** (`X-Currency` 0 matches in 1748 `*.php` files 2026-09-07). The **current source of truth for a guest is the `guest_currency` cookie** (`app/Services/Currency/UserCurrencyPreferenceService.php:14,57`) and for an authed user it is the **`user_preferences` DB row** (`app/Services/Currency/CurrencyService.php:82`). The browser carries the guest context automatically: after `POST /api/v1/general/currencies/select` sets `Set-Cookie: guest_currency=<encrypted>` (`CurrencyController.php:44`), **every later `GET /api/v1/general/products|cart|orders|settings` to `meem.mohammedtareq.me` automatically sends `Cookie: guest_currency=<encrypted>`** — no header, no interceptor. `X-Currency` is therefore **not necessary for same-site** and is not centralized anywhere. It would only become necessary if you must support **cross-site `frontend.vercel.app → api.meem.mohammedtareq.me` fetches where `SameSite=Lax` blocks the cookie on XHR** — and the fix belongs in one `axios/fetch` wrapper (1 file), not in every endpoint.

---

## 1. Complete Currency Flow — From Selector Click to Prices

### Where the user selects (frontend — as documented, not as frontend code you can `Read` in this repo)

- UI is the storefront currency selector (`api-desc/currency/frontend.md:1` — `publicCurrencyApi.select(currency_code)` → `POST /api/v1/general/currencies/select`). This repo has no Vue component you can open (`resources/js` is 2 files: `app.js` + `bootstrap.js:1` with `window.axios = require('axios')` + `X-Requested-With`). Real frontend SPA lives outside `D:\work\meem` — verified: `D:\work\frontend` sibling does not exist (`Test-Path ..\frontend → False`), and `resources/views` is empty for currency.

### Frontend function called (contract, per `api-desc/currency/frontend.md`)

```js
// contract — not in this repo
publicCurrencyApi.select('KWD') === POST /api/v1/general/currencies/select {currency_code:'KWD'}
```

### State/store updated

- No Redux/Pinia store visible in this repo. `package.json: axios ^0.19` but `resources/js/bootstrap.js:4` only sets `axios.defaults.headers.common['X-Requested-With']='XMLHttpRequest'` — **no `X-Currency` interceptor, no `guest_currency` read**. So in the *current* architecture the **only** frontend “store” is whatever the external SPA keeps in memory / `localStorage` plus the browser cookie jar.

### Cookie created/updated — YES, every select

- `CurrencyController.php:32` `select(SelectCurrencyRequest $request)` does:

```php
$currencyCode = strtoupper($request->validated()['currency_code']); // SelectCurrencyRequest.php:10 exists active
$user = auth('sanctum')->user() ?? auth()->user();
if ($user) $preferenceService->setUserPreference($user, $currencyCode); // user_preferences
$preferenceService->setGuestCurrencyCode($currencyCode, $request); // ← ALWAYS, even when authed
app(CurrencyService::class)->forgetEffectiveCode();
return $this->apiResponse(CURRENCY_SELECTED_SUCCESSFULLY, 200, true, new CurrencyResource($currency));
```

- `UserCurrencyPreferenceService.php:57`:

```php
Cookie::queue(Cookie::make(
    name: $this->cookieName(), // 'guest_currency' — config/currency.php:13
    value: strtoupper($currencyCode),
    minutes: config('currency.guest_cookie_lifetime', 525960), // ≈ 365d
    path: config('currency.guest_cookie_path', '/'),
));
// → defaults: Domain=null (host-only), Secure=null, HttpOnly=true, SameSite=Lax (Laravel CookieJar default)
```

- Encryption: `app/Http/Middleware/EncryptCookies.php:7` `protected $except = []` — **every** cookie including `guest_currency` is `EncryptCookies`ed (AES-256-CBC, signed). What the browser sees:

```
Set-Cookie: guest_currency=eyJpdiI6...; Path=/; HttpOnly; Max-Age=31536000 (host-only)
```

- Value in jar: 3-letter uppercase ISO (e.g. `KWD`, `USD`, `SAR`, `EGP` — validated `SelectCurrencyRequest.php:10`).

### Frontend does it call API immediately after changing?

- Yes — the selector’s single call **is** the persistence. No second call needed. The `200` response carries `CurrencyResource` (`api-desc/currency/api.md:12a`) — frontend can hydrate `is_base/is_catalog` but does NOT need to re-`GET /currencies` to know the chosen code.

### Backend does with that request (5 lines)

1. Validate `currency_code` exists `where is_active=true`.
2. `strtoupper` + `setUserPreference` if Bearer authed (upsert `user_preferences`:`user_id`→`currency_code`).
3. Queue encrypted `guest_currency` cookie (always).
4. `forgetEffectiveCode()` (`CurrencyService.php:82` memo bust).
5. Return `200 {status, message: "Currency updated successfully", success:true, data: CurrencyResource}` — no `Set-Cookie` in JSON, cookie is in `Set-Cookie` header.

### How backend determines effective currency (every later request — not just `/select`)

`CurrencyService.php:82` `getEffectiveCode(?User $user=null)` — **the single resolver** (called from `ProductController.php:152`, `ConvertsProductPrice.php:20`, `OrderCreationService`, cart, etc.):

```php
public function getEffectiveCode(?User $user = null): string {
    if ($this->effectiveCode !== null) return $this->effectiveCode;
    if (!$this->isCurrencySelectionEnabled()) return $this->effectiveCode = $this->getCatalogCode(); // gate
    $user ??= auth()->user() ?? auth('sanctum')->user();
    if (Schema::hasTable('user_preferences','currencies')) {
        $preferenceCode = $this->preferenceService->getUserPreference($user);
        if ($preferenceCode !== null && !$this->preferenceService->isValidActiveCurrency($preferenceCode)) {
            if ($user) $this->preferenceService->clearUserPreference($user);
            $preferenceCode = null;
        }
        if ($preferenceCode !== null) return $this->effectiveCode = $preferenceCode; // ← authed wins
        $guestCode = $this->preferenceService->getGuestCurrencyCode(); // ← Request::cookie('guest_currency') decrypted
        if ($guestCode !== null && !$this->preferenceService->isValidActiveCurrency($guestCode)) {
            $this->preferenceService->clearGuestCurrencyCode(); $guestCode = null;
        }
        if ($guestCode !== null) return $this->effectiveCode = $guestCode;
    }
    return $this->effectiveCode = $this->getCatalogCode();
}
```

- Gate: `isCurrencySelectionEnabled()` reads `settings.options.currency_selection_enabled` (default `false`). When `false`, **every** resolver returns `catalogCode` even if cookie is `KWD` — selector should be hidden (per `api-desc/currency/frontend.md`).

### What backend returns for the *select* itself

```http
HTTP/1.1 200 OK
Set-Cookie: guest_currency=eyJp...; Path=/; HttpOnly
Content-Type: application/json

{"status":200,"message":"Currency updated successfully","success":true,"data":{
  "id":2,"code":"KWD","name":"Kuwaiti Dinar","symbol":"KD",
  "country_name":"Kuwait","numeric_code":"414","decimal_places":3,"icon":"kw",
  "is_active":true,"sort_order":2,"is_base":false,"is_catalog":false,
  "created_at":"2026-08-10T00:00:00+00:00"
}}
```

No `meta.adopted_currency` today (that’s the *plan*).

### How frontend receives result / what causes re-pricing

- `200` resolves the `axios` promise. Frontend SPA sets `store.currency = data.code` (in external SPA). Next `GET /products` re-fetches and, because the **cookie jar now holds `guest_currency=KWD`**, the product response already comes back converted — `ConvertsProductPrice.php:20` reads `getEffectiveCode()` → `CurrencyConversionService` picks rate `0.2210000000` for `KWD` vs `3.7500` for `SAR`.

---

## 2. What Happens AFTER — Every Later Endpoint

### Centralized mechanism (the one that already exists)

> **The browser Cookie header is the centralized mechanism.** No JS, no interceptor, no store sync is required for the *transport* after the single `POST /select`. The browser attaches `Cookie: guest_currency=<encrypted>` to **every** `api/*` request whose URL host matches the cookie’s host (host-only `meem.mohammedtareq.me` or `localhost`). Laravel’s `EncryptCookies` + `AddQueuedCookiesToResponse` in `Kernel.php:22` `api` group decrypts it before any controller runs.

### Per-endpoint trace (all use the same resolver — no per-endpoint plumbing)

| Next call | Does frontend auto-tell backend USD? | How (file) | Cookie or header actually sent |
|-----------|--------------------------------------|------------|-------------------------------|
| `GET /api/v1/general/products` (`routes/api.php:100`) — `CurrencyController::index` / `ProductController::index` → `ConvertsProductPrice` | **Yes** | Browser `Cookie: guest_currency=<encrypted USD>` + `CurrencyService::getEffectiveCode()` (`CurrencyService.php:82`) | `Cookie` (no JS header). If authed, also `Authorization: Bearer <token>` but currency comes from `user_preferences` row (no cookie needed). |
| `GET /api/v1/general/products/{slug}` | **Yes** | same resolver | same |
| `GET /api/v1/general/cart` / `POST /cart` / `GET /checkout/promotions` (authed group `routes/api.php:107` `auth:sanctum`) | **Yes** — but now source is `user_preferences` DB row (row was created by the same `POST /select` or adopted at login), not cookie | `UserCurrencyPreferenceService::getUserPreference(auth user)` → `CurrencyService.php:82` | Cookie still sent but ignored because `preferenceCode !== null` → returns preference first. |
| `GET /api/v1/orders` / `GET /api/v1/general/settings` | **Yes** | same — `settings` also drives `isCurrencySelectionEnabled` gate | same |
| `POST /api/v1/general/checkout` | **Yes** | same — resolver runs inside `OrderCreationService` to snapshot `catalog_currency_code/currency_rate` onto `orders` row | same |

**There is no `axios.interceptors.request.use(cfg.headers['X-Currency']=...)` in this repo.** Verified: `resources/js/bootstrap.js` only sets `X-Requested-With`; `package.json` no `js-cookie`; `ctx_search X-Currency 1748 *.php → 0`. So the *only* auto-carry is the **cookie jar**.

---

## 3. Answer to Your Exact Question

> “If the user changes currency ONCE, why would I need to manually put currency in every endpoint request?”

**Your concern is valid for the CURRENT real implementation — you do NOT need to manually put currency in every endpoint.** One `POST /api/v1/general/currencies/select {currency_code:'USD'}` is sufficient; the browser remembers via `Set-Cookie` and replays it.

### Scenario A — USD then GET /products

- User clicks `USD` → `POST /api/v1/general/currencies/select {currency_code:'USD'}` → `200 Set-Cookie: guest_currency=eyJ... (USD)` + `data.code='USD'`.
- Next `GET /api/v1/general/products` — browser automatically adds:

```http
GET /api/v1/general/products HTTP/1.1
Host: meem.mohammedtareq.me
Cookie: guest_currency=eyJp... (USD)
Accept: application/json
```

- `EncryptCookies` decrypts → `Request::cookie('guest_currency')='USD'` → `getGuestCurrencyCode()` → `strtoupper='USD'` → `isValidActiveCurrency('USD')` true → `getEffectiveCode()` returns `USD` → `ProductController` converts `100 USD==100 USD` (or KWD→USD via rate). **Zero manual header.**

### Scenario B — USD then GET /cart (guest)

- Identical: `Cookie: guest_currency=eyJ... (USD)` still attached. If `cart` is the authed `GET /api/v1/general/cart` (actually `Marvel` cart is authed), but assuming guest cart, same cookie wins.

### Scenario C — USD then GET /orders (authed)

- Authed `GET /api/v1/orders` sends:

```http
GET /api/v1/orders HTTP/1.1
Host: meem.mohammedtareq.me
Authorization: Bearer <token>
Cookie: guest_currency=eyJ... (USD)   # still there, but ignored
```

- `CurrencyService.php:82` → `$user=auth('sanctum')->user()` non-null → `getUserPreference(user)` → if row exists (created at `select` time) returns that row (e.g. `USD`), otherwise falls back to cookie `USD`. Either way `USD`. **No header.**

**Centralized mechanism:** the **encrypted host-only cookie + Laravel’s automatic `Request::cookie()` + `CurrencyService::getEffectiveCode()`**. No interceptor.

**When you WOULD need per-request headers:** only when the above cookie is *not delivered* (see §4 — cross-site `vercel.app → meem.mohammedtareq.me` with `SameSite=Lax` blocks XHR cookie). Then a single global Axios wrapper (1 file) that sets `X-Currency` **once** replaces per-endpoint manual work — not per-endpoint.

---

## 4. Is X-Currency Necessary? — Definitive, Evidence-Based

| Question | Fact (as-read 2026-09-07) | Evidence |
|----------|---------------------------|----------|
| Is X-Currency already implemented? | **NO** — backend does not read it, frontend does not send it | `X-Currency` 0 matches in 1748 `*.php` files; `resources/js/bootstrap.js:4` only `X-Requested-With`; `package.json` no `currency store` |
| Where is it read? | Nowhere in this repo — only in your two `new/` planning docs (`new/guest_currency_frontend_ownership_plan.md`, `api-docs/currency/README.md`) | ctx_search |
| Where would it be generated? | Proposed `UserCurrencyPreferenceService::resolveGuestCurrency()` reading `Request::header('X-Currency')` — **not yet coded** | plan `new/guest_currency_implementation_ready_plan.md:78` |
| Is it injected globally? | Not today. Plan proposes `axios.interceptors.request.use` (`new/guest_currency_implementation_ready_plan.md:118`) | plan |
| Is the cookie itself enough? | **Yes for same-site, NO for cross-site** — `SameSite=Lax` (Laravel default) cookies are **not** sent on cross-site `fetch/XHR` (only top-level navigations). | `config/session.php` `same_site=>lax`; Cookie defaults same; `config/cors.php:28` `supports_credentials=false` also blocks credentialed CORS if you tried to force `credentials:include`. |
| Does Laravel read the cookie automatically? | **Yes** — `EncryptCookies` (in `Kernel.php:22` `api` group) decrypts, then `Request::cookie('guest_currency')` is available in any controller/service without extra middleware | `UserCurrencyPreferenceService.php:44` |
| Does any backend middleware resolve currency from cookie? | **Not middleware — service.** `CurrencyService::getEffectiveCode()` does the `getUserPreference` then `getGuestCurrencyCode` reads `Request::cookie` | `CurrencyService.php:82` |
| Does any middleware resolve from header? | **No** — no `X-Currency` middleware exists (`app/Http/Middleware`: `Authenticate`, `ChannelMiddleware`, `CheckLangMiddleware`, `EncryptCookies` only). | `app/Http/Kernel.php:22` |
| Which has priority if both existed? | Today: no header, so cookie only. In the **plan** the proposed priority is `user pref > X-Currency > cookie > catalog` (`new/guest_currency_implementation_ready_plan.md:62`) | plan |
| What if cookie and header disagree? | Today: header is ignored — cookie wins (only transport). In plan: header wins for guest. | plan §4 |
| What if neither exists? | `catalogCode` (`settings.options.catalog_currency_code` → `USD`) | `CurrencyService.php:82` fallthrough |
| What if `currency_selection_enabled=false`? | All guest transports ignored — `catalogCode` | `CurrencyService.php:82` gate |

**Definitive answer:**

> **NO — you do NOT need X-Currency for the current architecture if frontend and API are same-site (or same-host localhost with no Domain, or `*.meem.mohammedtareq.me` with `Domain=.meem.mohammedtareq.me`).** The `guest_currency` cookie already makes one `POST /select` persist across every later `GET /products|cart|orders` automatically via `Cookie:`.

> **YES — you DO need X-Currency (or a `SameSite=None; Secure` cookie, which is worse) if frontend `https://*.vercel.app` talks to `https://meem.mohammedtareq.me/api/*`** where `vercel.app` ≠ `meem.mohammedtareq.me` (unrelated registrable domains). `Domain=meem.mohammedtareq.me` cannot be set from `vercel.app` (`Set-Cookie` rejected), and even if set from the API, `Lax` blocks its send on cross-site XHR. The cookie will appear to “disappear” on `GET /products`. The header is then the only data-channel — and it is added **once** in a global interceptor, not per endpoint.

**Why need it after selecting once, if cookie should remember?** Because **cross-site browsers treat `localhost:3000→localhost:8000` as same-site (so it works in dev) but `vercel.app→meem.mohammedtareq.me` as cross-site (so it fails in prod even though it worked locally).** The “once” is remembered in the correct jar (`vercel.app` jar), but that jar is not sent to `meem.mohammedtareq.me`. So the user’s one selection is remembered locally (frontend) but never arrives.

---

## 5. Currency Selection Endpoint — Real Request/Response

### Real endpoint (no header contract)

```
POST /api/v1/general/currencies/select              // routes/api.php:101,  throttle:public-api,  public
```

### Real request (guest, fresh — cookie optional)

```http
POST /api/v1/general/currencies/select HTTP/1.1
Host: meem.mohammedtareq.me
Accept: application/json
Content-Type: application/json
Cookie: guest_currency=eyJp...(optional old, if any)

{"currency_code":"KWD"}
```

Headers: `Accept` optional, `Content-Type: application/json` required for JSON body, `Authorization: Bearer <token>` optional (if authed) — no `X-Currency` required or read.

Body: single field `currency_code` (`SelectCurrencyRequest.php:10` — required string max 3 exists `currencies.code` where `is_active=true`). Lowercase `kwd` is accepted (controller `strtoupper` before lookup).

Cookies sent: maybe old `guest_currency` (ignored — new value overwrites).

### Real response (200)

```http
HTTP/1.1 200 OK
Set-Cookie: guest_currency=eyJpdiI6Iklr...; Path=/; HttpOnly; Max-Age=31536000
Content-Type: application/json

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
  }
}
```

No `meta.adopted_currency` today (plan additive). Errors: `422 {success:false, errors:{currency_code:[...]}}` for inactive/unknown/missing.

### What changes after

- DB: if `Authorization` present → `user_preferences` upsert (`user_id → KWD`).
- Jar: `guest_currency` becomes `KWD` (overwrites old). No `user_preferences` for guest.
- Server memo: `CurrencyService::forgetEffectiveCode()` — next `getEffectiveCode()` recomputes.
- Product cache: not flushed here (`invalidatePriceCaches` only on currency/rate admin), but per-currency cache keys `md5(fullUrl + '|currency:' + code)` diverge, so new currency misses and refills.

---

## 6. The Cookie — Exact Implementation

| Question | Answer | Evidence |
|----------|--------|----------|
| **Name** | `guest_currency` | `UserCurrencyPreferenceService.php:14` `DEFAULT_COOKIE_NAME`; `config/currency.php:13` `GUEST_CURRENCY_COOKIE` |
| **Where created** | `CurrencyController.php:44` `setGuestCurrencyCode()` **every** `POST /select` (even when authed) | `CurrencyController.php:32` |
| **Where updated** | Same line — same endpoint, any later currency change overwrites | same |
| **Where read** | `UserCurrencyPreferenceService.php:44` `getGuestCurrencyCode()` (`$request->cookie(name)`) → `CurrencyService.php:82` `guestCode = getGuestCurrencyCode()` | `CurrencyService.php:82` |
| **Where deleted** | `UserCurrencyPreferenceService.php:75` `clearGuestCurrencyCode()` → `Cookie::forget` called (a) inside `adoptGuestCurrencyOnLogin()` after copying guest → `user_preferences` (`:106`), and (b) inside `CurrencyService.php:118` when `guestCode` fails `isValidActiveCurrency` (inactive). | `UserCurrencyPreferenceService.php:86`, `CurrencyService.php:82` |
| **HttpOnly** | **Yes** — `Cookie::make()` default `httpOnly=true` (not overridden). `document.cookie` cannot see it. | `UserCurrencyPreferenceService.php:57` omits `httpOnly` arg → Laravel `CookieJar::make(..., httpOnly=true)` |
| **Path** | `/` | `config/currency.php:15` |
| **Domain** | **null** (host-only) — not passed → cookie is `host-only meem.mohammedtareq.me`, not `Domain=.meem.mohammedtareq.me` | same — `Cookie::make` `domain=null` |
| **SameSite** | **Lax** (Laravel default) — not passed | same — `CookieJar::make(..., sameSite='Lax')` |
| **Secure** | **null** (not forced) — not passed; inherits request scheme: `https→Secure` via `TrustProxies` `'+'` headers, `http→` not | same |
| **Expiration** | `525960` minutes = `365` days (approx 1 year) → `Max-Age=31536000`, `Expires` 1 year | `UserCurrencyPreferenceService.php:14,57` |
| **Browser auto-sends to API?** | **Yes for same-site**, **No for cross-site XHR when `Lax`** — see §4. | HTTP Cookie `host+Lax` rules |
| **Frontend JS reads it?** | **No** — `HttpOnly` hides it; `new/guest_currency_frontend_ownership_plan.md` notes JS cannot read | verified `except=[]` |
| **Backend reads it?** | **Yes** — every request via `CurrencyService::getEffectiveCode()` branch | `CurrencyService.php:82` |
| **Is the cookie itself already enough for subsequent requests?** | **Yes — for same-site.** One `POST /select` → cookie jar → next `GET /products`, `GET /cart`, `GET /orders` each carry `Cookie: guest_currency` automatically. **No header, no interceptor, no per-endpoint param.** | above |

---

## 7. Backend Currency Resolution — Exact Order (from code, not assumption)

**Single entry point:** `CurrencyService.php:82` `getEffectiveCode(?User $user=null)` → every currency-aware read funnels here. No currency middleware, no `X-Currency` parser, no repository — just this service + preference service.

```text
Incoming HTTP request
  ↓
Kernel.php:22  api group: EncryptCookies decrypts → Request::cookies has guest_currency=USD (if sent)
  ↓
auth()->user() ?? auth('sanctum')->user()  (null for guest, User model for Bearer)
  ↓
CurrencyService::getEffectiveCode(user)
  1. memo hit → return (per-request memo; busted by forgetEffectiveCode)
  2. if (!isCurrencySelectionEnabled()) → catalog  (settings.options.currency_selection_enabled ?? false)
  3. if tables missing → catalog
  4. getUserPreference(user)  → if present+active → RETURN  (authenticated wins — no fallback)
     if present but inactive → clearUserPreference → treat as null (fall through)
  5. getGuestCurrencyCode()  → if present+active → RETURN  (guest)
     if present but inactive → clearGuestCurrencyCode + Queue Forget → null
  6. return catalogCode (settings.options.catalog_currency_code ?? USD)
```

**Actual order (proven):**

```
isCurrencySelectionEnabled? false → catalog
  ↓ true
Authenticated User Preference   (user_preferences.currency_code)
  ↓ miss
Guest Cookie                  (guest_currency)
  ↓ miss
Default/Catalog               (catalog_currency_code)
```

- **No `X-Currency` header in this chain** (`X-Currency` 0 hits). The “`X-Currency`” layer would slot between *Authenticated* and *Guest Cookie* (as in the plan’s `user pref > header > cookie > catalog`), but today it does not exist.
- Components: `CurrencyService` + `UserCurrencyPreferenceService` (services) + `Settings` + `UserPreference` + `Currency` models; no `CurrencyRepository`, no `ResolveCurrencyMiddleware`.

---

## 8. Frontend API Architecture — Central Layer?

**Does `api.get('/products')` automatically become `GET /products` with `X-Currency: USD` without per-call headers?**

**No — and it does not need to today, because the cookie travels instead. There is also no wrapper that would add a header.**

Evidence:

- `resources/js/bootstrap.js:4` is the **only** JS that touches HTTP in this repo:

```js
window.axios = require('axios');
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
```

No `axios.create`, no `interceptors`, no `fetch`, no `apiClient`, no `request middleware`, no `hooks`.

- `package.json: axios ^0.19, lodash, laravel-mix, vue-template-compiler` — no `js-cookie`, no `currency store` lib.
- `resources/js/app.js:1` is just `require('./bootstrap');`
- `api-desc/currency/frontend.md:1` `publicCurrencyApi: { list(), select() }` is documentation, not code — no implementation in this repo.
- Sibling `D:\work\frontend` not found; no external SPA code was inspected (per §1, frontend lives elsewhere).

So `api.get('/products')` today is just `axios.get('/api/v1/general/products')` → browser adds `Cookie: guest_currency=USD` **automatically** (no JS header). If you wanted `X-Currency` to auto-travel, you **would** add one global `axios.interceptors.request.use` (≈10 lines) — the plan’s `lib/api.ts` — but no one has.

---

## 9. Everywhere Currency Is Currently Transported (as-read)

| String / symbol | Appearances | Category | How it moves today |
|-----------------|-------------|----------|--------------------|
| `X-Currency` / `X-Effective-Currency` | **0** in `*.php`, 0 in `resources/js/*` | request/response header | **not transported today** (only in `new/` planning docs) |
| `guest_currency` | `UserCurrencyPreferenceService.php:14,57,75,86` + `config/currency.php:13` + `tests/Feature/Currency/*` (8 hits) | cookie (encrypted host-only) | `Set-Cookie` from `POST /select` → browser `Cookie:` replay |
| `currency_code` | `UserPreference.php: currency_code`, `CurrencyController.php:34`, `SelectCurrencyRequest.php:10`, `CurrencyService::getEffectiveCode`, `OrderCreationService` snapshot `currency_code/base_currency_code/catalog_currency_code` | request body (`POST /select`) + DB (`user_preferences`, `orders`) | `POST /api/v1/general/currencies/select {currency_code}` → DB `user_preferences`/`orders` |
| `currencyCode` (JS camelCase) | 0 in this repo | frontend state | not in this repo |
| `selectedCurrency/currentCurrency/currency store/selector` | only in `api-desc/currency/` docs (human), 0 in `*.vue/*.js` | docs / SPA state (external) | not in this backend |

---

## 10. Simple Final Explanation

### Current flow (one diagram)

```text
User clicks KWD in selector (frontend SPA, external)
        ↓
POST /api/v1/general/currencies/select  {currency_code: "KWD"}
        ↓
Backend: validate active, upsert user_preferences (if authed), ALWAYS queue
         Set-Cookie: guest_currency=eyJ... (KWD)  +  {data: KWD}
        ↓
Browser stores host-only Lax encrypted cookie
        ↓
Next API request (ANY currency-aware endpoint)
GET /api/v1/general/products  ──►  Browser adds  Cookie: guest_currency=eyJ...  automatically
GET /api/v1/general/cart      ──►  Cookie: ...  (or Authorization: Bearer + cookie ignored if authed pref exists)
GET /api/v1/general/orders    ──►  same
        ↓
Backend CurrencyService::getEffectiveCode()
  user pref? → use it
  else guest cookie? → decrypt → validate → use it
  else → catalog (USD)
        ↓
Response: product `current_price`, `currency:KWD` etc. already in the chosen currency
```

### What happens when I change currency once?

You `POST /select` once. Server writes one `user_preferences` row if authed and always writes one encrypted `guest_currency` cookie. The `200` comes back. You are done — no loop, no extra calls.

### What happens on the next API request?

The browser replays the cookie. Backend decrypts and resolves without the frontend doing anything. `GET /products`, `GET /cart`, `GET /orders` all see `KWD` automatically. No extra param, no header.

### Do I need to manually add currency to every endpoint? — NO (today)

**NO.** The existing mechanism that already handles it is the **`guest_currency` cookie jar** (guest) and the **`user_preferences` DB row** (authed), both read centrally by `CurrencyService::getEffectiveCode()` (guest via `Request::cookie`, authed via `auth()->user()`). You `POST /select` once; the next 100 `GET`s are already in that currency.

### If YES (when it *would* be true)

If frontend `https://*.vercel.app` fetches `https://meem.mohammedtareq.me/api/*`, `SameSite=Lax` **blocks** the cookie on XHR/fetch — the `Cookie:` never arrives even though it’s in the jar. Then you **do** need a header, but only in **one** centralized place:

```js
// lib/api.ts — the ONE place
import axios from 'axios'; import Cookies from 'js-cookie';
const api = axios.create({ baseURL: 'https://meem.mohammedtareq.me/api' });
api.interceptors.request.use(c => { c.headers['X-Currency'] = Cookies.get('guest_currency'); return c; });
```

Not per endpoint.

### If NO (today’s truth — same-site)

Show the existing mechanism: `Set-Cookie` (from `CurrencyController.php:44` via `UserCurrencyPreferenceService.php:57`) → browser Jar (`Path=/, host-only, Lax`) → every later `Cookie:` → `EncryptCookies` → `CurrencyService.php:82` → prices/carts/orders all use that code. Demo: after `kwd` select, `Cookie: guest_currency=<enc(kwd)>` is on `GET /products`, `GET /cart`, `GET /orders` — run DevTools → Network → Request Headers → `Cookie:`.

---

## 11. What Is Actually Wrong, If Anything (and What Would Need to Change — Plan Only)

### What is already fine

- Guest single-select persistence works for **same-site** (`localhost:3000→localhost:8000` same host; `*.meem.mohammedtareq.me` with `Domain=.meem.mohammedtareq.me` would share). No interceptor needed.
- Auth flow is correct: `POST /select` as authed writes `user_preferences`; login `adoptGuestCurrencyOnLogin()` (`UserController.php:493,587,1002`) copies guest→user only if row empty and guest valid, then clears cookie — so guest `USD` does not overwrite existing `SAR`.
- Inactive-code self-heals: `CurrencyService.php:82` clears stale preference/cookie and falls back.

### What is wrong

1. **Frontend cannot read the guest choice.** `HttpOnly=true` (`UserCurrencyPreferenceService.php:57` default) hides `guest_currency` from `document.cookie` — the selector cannot show “you are on KWD” without re-`GET /general/currencies` and guessing. External SPA must keep a second `localStorage` duplicate.
2. **Backend writes the cookie on behalf of frontend.** `POST /select` always queues a cookie even when authed — frontend never “owns” it; cross-site it can’t be read/set reliably.
3. **Cross-site breaks silently.** `Lax` + host-only + `supports_credentials=false` (`config/cors.php:22`) means `frontend.vercel.app → api.meem.mohammedtareq.me` XHR drops the cookie — products revert to catalog (`USD`) even though DevTools → Application shows `guest_currency` exists (on the wrong jar). No error, just wrong prices. The only hint is Network → `Cookie:` missing on the API request.
4. **No global header fallback exists today** — so fixing (3) requires frontend code (the “one place”).
5. **`GET /general/currencies` cache key `md5(fullUrl)`** does not include guest currency (products do) — but currencies list is not currency-sensitive, so fine. No bug there.

### What would need to change — PLAN ONLY, NO CODE (references the two planning docs you already have)

- Keep plan `new/guest_currency_implementation_ready_plan.md:1` (§5, §8) as the execution contract: add `guest_currency` to `EncryptCookies::$except=['guest_currency']` (`EncryptCookies.php:7`), make it plaintext `Value=EGP, HttpOnly=false, SameSite=Lax, Secure=false on localhost / true on https` (`config/currency.php` new keys), add `GUEST_CURRENCY_FRONTEND_OWNED` flag gating `setGuestCurrencyCode`/`clearGuestCurrencyCode`, and make backend read `X-Currency` header first (`resolveGuestCurrency()` → header `trim→upper→/^[A-Z]{3}$/→isValidActive` → cookie fallback: `new/guest_currency_implementation_ready_plan.md:78`). No per-endpoint change — one interceptor in `frontend/lib/api.ts` (`new/guest_currency_implementation_ready_plan.md:118`) sets `X-Currency: <from store or cookie>` on every `/api/*`.
- CORS: `config/cors.php:22` `allowed_origins` explicit (`http://localhost:3000, https://app.meem.mohammedtareq.me`), add `allowed_origins_patterns=['#^https://.*\\.vercel\\.app$#']` — `https://*.vercel.app` in `allowed_origins` is **invalid** (`*` needs patterns). Flip `supports_credentials=true` only if you keep the cookie path; `X-Currency` alone works with `supports_credentials=false` but then cookie fallback never arrives (acceptable once header is primary).
- Docs: `api-docs/currency/README.md:1` already contains the frontend wiring (§3 cookie helpers with `js-cookie` + §4 interceptor) — reuse it as the spec for the external SPA PR.

---

## Appendix — File Index (clicked, not assumed)

| File | Why seen |
|------|----------|
| `app/Services/Currency/UserCurrencyPreferenceService.php:14,44,57,86,108` | cookie name/lifetime, read/write/delete/adopt/validate |
| `app/Services/Currency/CurrencyService.php:82` | single effective resolver + gate |
| `app/Http/Controllers/Api/Currency/CurrencyController.php:32,44` | sole writer of guest cookie + user pref |
| `app/Http/Middleware/EncryptCookies.php:7` | `except=[]` → encrypted HttpOnly |
| `app/Http/Kernel.php:22` | `api` group carries EncryptCookies |
| `config/currency.php:13` | name/lifetime/path + “encrypted, signed” comment |
| `config/cors.php:22` | `['*']` + `supports_credentials=false` |
| `routes/api.php:100` | `POST currencies/select` public `throttle:public-api` |
| `packages/marvel/src/Http/Controllers/UserController.php:493,587,1002` | `adoptGuestCurrencyOnLogin` callers |
| `resources/js/bootstrap.js:4` | only `X-Requested-With`, no interceptor |
| `api-desc/currency/frontend.md:1` | selector contract `POST /select {currency_code}` |

*No `api.get('/products', {headers:{'X-Currency'}})` anywhere — the “centralized mechanism” today is the Cookie jar, not a header.*

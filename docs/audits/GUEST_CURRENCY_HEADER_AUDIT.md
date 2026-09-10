# Audit — Guest Currency Transport `guest_currency` cookie → `X-Currency: SAR`

> **Read-only audit. No files modified. All flows derived from repository code at `D:\work\meem` on branch `main` (commit with `config/currency.php` SameSite=None, `BrandProductResource.php` ConvertsProductPrice).**

---

## 1. Current Architecture (derived)

**Single source of truth:** `app/Services/Currency/CurrencyService.php:19` (singleton via `AppServiceProvider.php:26`). All pricing/conversion funnels through it. No controller/resource duplicates conversion.

```
Illuminate\Http\Request
  ↓ HandleCors (global app/Http/Kernel.php:23)
  ↓ api middleware: EncryptCookies (except guest_currency) + AddQueuedCookiesToResponse + ChannelMiddleware + CheckLangMiddleware
  ↓ Route::prefix('api/v1/general') routes/api.php:34 / RestAPIServiceProvider.php:31 (prefix api/v1 group api)
  ↓ Controller (CurrencyController::select, ProductController::index/getProductBySlug, BrandController::getBrandBySlug/getBrandsProductsByQtySet, CartResource)
  ↓ CurrencyService::getEffectiveCode(?User) / getEffectiveCurrency() / convertPrice()
    ├─ UserCurrencyPreferenceService.php:13 getUserPreference(User) → user_preferences.currency_code
    └─ UserCurrencyPreferenceService.php:37 getGuestCurrencyCode(Request) → request->cookie('guest_currency')
  ↓ CurrencyConversionService.php:13 / CurrencyService::convertPrice() (catalog→effective via currency_rates)
  ↓ ProductPricingService.php:30 calculateProductPricing() (discount) + ConvertsProductPrice trait (catalog→effective)
  ↓ Resource: ProductResource/Mini uses ConvertsProductPrice::convertCatalogPrice + effectiveCurrency()
  ↓ Response JSON {price, current_price, currency: {id,code,name,symbol,icon}}
```

**Key invariants from code:**
- `CurrencyService::getEffectiveCode()` (`CurrencyService.php:72`) memoizes (`$effectiveCode`), checks `isCurrencySelectionEnabled()` (`settings.options.currency_selection_enabled`), then `user_preferences` → `guest_cookie` → `catalog_code`. Priority is `user > guest > catalog` (lines 90-120).
- Validation/normalization in `UserCurrencyPreferenceService::normalizeGuestValue()` (`UserCurrencyPreferenceService.php:150`) and `isValidActiveCurrency()` (exists `currencies.code` where `is_active=true`).
- `CurrencyService` never reads `X-Currency` today; `UserCurrencyPreferenceService` never reads header.

**Files:**
- `config/currency.php` (guest cookie name/lifetime/path/httpOnly/sameSite/secure)
- `config/cors.php` (paths `api/*`, `supports_credentials`, `allowed_origins`, `allowed_headers`)
- `app/Services/Currency/CurrencyService.php`, `CurrencyConversionService.php`, `CurrencyRateService.php`, `UserCurrencyPreferenceService.php`
- `app/Http/Middleware/EncryptCookies.php:11` (`except=['guest_currency']`)
- `app/Http/Controllers/Api/Currency/CurrencyController.php:24` (`select`)
- `app/Http/Requests/SelectCurrencyRequest.php:11` (`currency_code` required|string|size:3|exists)
- `app/Http/Controllers/Api/General/BrandController.php:14`, `App/Services/General/BrandService.php:20`, `App/Http/Resources/Brand/BrandProductResource.php:10`, `App/Http/Resources/Product/ConvertsProductPrice.php:6`
- `app/Http/Controllers/Api/General/ProductController.php:51` (working reference)
- `tests/Feature/Currency/*` (15 files)

---

## 2. Current Guest Flow

```
Browser (guest, no Sanctum token)
  → Frontend sets cookie document.cookie = "guest_currency=KWD; Path=/; SameSite=Lax" (or via POST /currencies/select Set-Cookie)
  → Every API request: Browser auto-sends Cookie: guest_currency=KWD (only if SameSite policy + Secure + credentials allow)
  → Laravel Decrypt: EncryptCookies excepts guest_currency → raw "KWD" stays plaintext
  → BrandController::getBrandBySlug($slug) → BrandService::getBrandBySlug() → Brand::search('slug') → load(products with channel filter + reviews_avg) → ProductService::enrichCollectionWithPricing()
  → Resource BrandProductResource::toArray() (after fix) → ConvertsProductPrice::convertCatalogPrice(price, CATALOG→EFFECTIVE) where EFFECTIVE = CurrencyService::getEffectiveCode(null)
    → UserCurrencyPreferenceService::getGuestCurrencyCode(request) → normalizeGuestValue("KWD") → "KWD" (if /^[A-Z]{3}$/ else try decryptLegacy, else null)
    → isValidActiveCurrency("KWD")? yes → return "KWD"
  → convertPrice(100, USD, KWD) = 22.1 , currency = getEffectiveCurrency() → {id,code,symbol,name,icon}
```

If cookie missing/invalid/inactive → `getGuestCurrencyCode` null → fallback `getCatalogCode()` (USD). `CurrencyService` memoizes per singleton; `ProductController` uses `currencyAwareCacheKey` (`ProductController.php:150`), `BrandController` now also (`BrandController.php:62`).

---

## 3. Current Authenticated Flow

```
Browser (Authorization: Bearer <sanctum> + Cookie: guest_currency=KWD)
  → CurrencyController::select (login) or any product/brand request with auth
  → CurrencyService::getEffectiveCode($user) where $user = auth('sanctum')->user()
    1. if !isCurrencySelectionEnabled() → catalog (ignores pref+cookie)
    2. $pref = UserCurrencyPreferenceService::getUserPreference($user) // user_preferences.value
       if pref && !isValidActiveCurrency(pref) → clearUserPreference → null
       if pref valid → return pref (immediate return, guest ignored)
    3. $guest = getGuestCurrencyCode(request) (same as guest flow)
       if valid → return guest
    4. return catalog
```

**Priority confirmed in code:** `UserCurrencyPreferenceService.php` precedence is `user_preferences` line 98 → `guest_cookie` line 107 → `catalog`. Verified in `UserCurrencyPreferenceTest.php:48` `effective_currency_prefers_the_user_preference_over_the_guest_cookie` (`SAR` pref > `KWD` cookie). `adoptGuestCurrencyOnLogin()` (`UserCurrencyPreferenceService.php:95`) only copies guest→user if user has no pref and guest valid, and intentionally leaves cookie intact for Frontend logout fallback (`tests/Feature/Currency/GuestCurrencyCookieTest.php:88`).

---

## 4. Desired Header Flow (proposed, not yet implemented)

**Guest:**
```
Frontend (knows selected SAR in memory)
  → GET /api/v1/products  X-Currency: SAR
  → CurrencyService::getEffectiveCode() reads X-Currency header (validated active) → SAR → convert
```
**Authenticated:**
```
Frontend → X-Currency: SAR + Authorization
  → CurrencyService → user_preferences.currency_code (=KWD) exists valid → return KWD (header ignored)
  → else fallback to X-Currency if valid, else catalog
```
This matches existing priority (`user > guest/header > catalog`) — no redesign needed, just replace cookie source with header source inside the same `CurrencyService` branch. No `X-Guest-ID`, no localStorage second source, no controller/resource changes beyond resource already fixed.

---

## 5. What Already Works (Reuse)

| Requirement | Evidence | Reuse |
|---|---|---|
| Central `CurrencyService` | `app/Services/Currency/CurrencyService.php:19` singleton, memoized `getEffectiveCode/EffectiveCurrency/convertPrice/isCurrencySelectionEnabled/forgetEffectiveCode` | Keep 100% |
| Guest resolution | `UserCurrencyPreferenceService::getGuestCurrencyCode()` + `normalizeGuestValue()` + `isValidActiveCurrency()` | Keep logic, change source from cookie → header |
| Auth pref | `getUserPreference/setUserPreference/clear` + `user_preferences` table | Keep 100% |
| Auth overrides guest | `CurrencyService.php:98` early return on valid `pref` | Already correct |
| Validation | `SelectCurrencyRequest.php:30` size:3 + exists `is_active` | Keep, already handles JSON+FormData via `prepareForValidation` |
| Active check | `isValidActiveCurrency()` | Keep |
| Conversion | `CurrencyService::convertPrice()` + `CurrencyConversionService` + `ConvertsProductPrice::convertCatalogPrice()` | Keep, `BrandProductResource` already now uses it |
| Metadata | `ConvertsProductPrice::effectiveCurrency()` → `{id,code,name,symbol,country_name,icon}` | Keep |
| Brand pricing enrichment | `BrandService.php:34` `enrichCollectionWithPricing()` + `BrandProductResource` now Converts | Keep |
| Brand cache currency-aware | `BrandController.php:62` `currencyAwareCacheKey` (fixed) | Keep |
| CORS env-driven, no `*` with credentials | `config/cors.php:30` closure filters `*` when `supports_credentials` | Keep, add `X-Currency` |
| Tests | `tests/Feature/Currency/*` 15 files, `ProductCurrencyTest`, `GuestCurrencyCookieTest`, `CurrencyCorsAndBrandIntegrationTest.php:1` | Keep, add header variants |

---

## 6. What Is Broken (exactly)

| # | Problem | Root file/method |
|---|---|---|
| 1 | Guest transport is cookie → fails cross-site `http://localhost:3000` → `https://meem.mohammedtareq.me` unless `SameSite=None; Secure` (current config fixes but cookie is still fragile, needs `credentials:include` + preflight, browser rejects `*` + credentials). | `config/currency.php:50` `same_site=lax` default, `config/cors.php` previously `*` + `supports_credentials=false` (now fixed but still cookie-dependent) |
| 2 | `GET /api/v1/general/brands/{slug}` historically not currency-aware (missing `ConvertsProductPrice`, `md5(fullUrl)` cache not currency-aware) — fixed in `BrandProductResource.php:10` + `BrandController.php:62` but still cookie-sourced. | `BrandProductResource.php:12` old `roundMoney`, `BrandController.php:46` old cache key |
| 3 | Header `X-Currency` not read anywhere | `CurrencyService.php:72` has no `getHeaderCurrencyCode()`, `UserCurrencyPreferenceService.php` only reads cookie |
| 4 | CORS does not allow `X-Currency` header | `config/cors.php:55` default `allowed_headers` lacked `X-Currency` (now added `Content-Type,Accept,Authorization,lang,x-channel` but not `X-Currency`) |
| 5 | `SelectCurrencyRequest` + `CurrencyController::select` still writes cookie (`setGuestCurrencyCode`) | `CurrencyController.php:32` `setGuestCurrencyCode`, `UserCurrencyPreferenceService.php:31` `Cookie::queue` |
| 6 | Cookie config still present though decision is to remove | `config/currency.php` entire guest_cookie_* block |
| 7 | Tests still assert cookie plaintext/`HttpOnly`/`SameSite` | `GuestCurrencyCookieTest.php:30`, `CurrencyCorsAndBrandIntegrationTest.php:68` |

---

## 7. Root Cause per Problem

1. **Cookie cross-site:** Browser spec: `Lax` cookies not sent on cross-site `fetch(credentials:include)`. Required `None; Secure` + `Allow-Credentials:true` + explicit `Allow-Origin`. Previous `config/cors.php:15` defaulted `allowed_origins=*` with `supports_credentials=false` → `*` + no credentials → cookie not attached. Even after fix, cookie remains cookie-based and is the designated removal.
2. **Brand not converting:** `BrandProductResource.php:12` did not `use ConvertsProductPrice`; it returned catalog `price` directly. `BrandController::getBrandsProductsByQtySet` cached with `md5(fullUrl)` ignoring `|currency:`.
3. **Header not supported:** No `X-Currency` read path; `CurrencyService.php:72` only calls `getGuestCurrencyCode()`.
4. **CORS header:** `allowed_headers` whitelist missing `X-Currency` → preflight `Access-Control-Request-Headers: X-Currency` → 403 preflight.
5. **Select still cookie:** `CurrencyController::select` is guest+auth endpoint; it was designed to `setGuestCurrencyCode` for cookie persistence.

---

## 8. Minimum Changes Required (cookie → `X-Currency`)

1. **Add header source to `CurrencyService`** — add `getHeaderCurrencyCode(?Request)` reading `X-Currency` (case-insensitive, trim, strtoupper, `isValidActiveCurrency`), and replace `getGuestCurrencyCode()` branch with it (or keep both with header preferred and cookie deprecated — but spec says remove cookie completely, so replace).
2. **Update `UserCurrencyPreferenceService`** — rename `getGuestCurrencyCode` → `getHeaderCurrencyCode` (or add new, deprecate old), change source from `$request->cookie()` to `$request->header('X-Currency')`, keep `normalizeGuestValue` logic but rename to `normalizeHeaderValue` (still supports `^[A-Z]{3}$` + active check, drop decryptLegacy). Keep `setGuestCurrencyCode` only if `select` still needs to set something; otherwise remove cookie write entirely.
3. **CORS:** add `X-Currency` to `allowed_headers` (env `CORS_ALLOWED_HEADERS`), keep `supports_credentials` filtering of `*`, ensure `CORS_ALLOWED_ORIGINS` env is explicit.
4. **Select endpoint:** Decide per Phase 5 — keep `POST /general/currencies/select` to persist `user_preferences` for auth (still needed), but remove `setGuestCurrencyCode` cookie write; for guests it becomes no-op or just validates header value and returns `CurrencyResource`. Keep JSON+FormData support.
5. **Brand:** already fixed (keep `ConvertsProductPrice` + currency-aware cache); no further change except ensuring it goes through `CurrencyService` (already does — no direct header read).
6. **Config:** either remove `config/currency.php` guest_cookie_* keys or keep as no-op for BC (spec says remove completely → delete block). Same for `EncryptCookies.php:11` `except`.
7. **Tests:** add header tests, update cookie tests to header.

**Do NOT:** touch `CurrencyConversionService`, `ProductPricingService`, `ProductService`, `CartResource` (already uses `CurrencyService`), `ChannelMiddleware`, session/auth cookie infra.

---

## 9. Files That Will Change

**MUST CHANGE:**
```
app/Services/Currency/CurrencyService.php              // replace guest_cookie branch with X-Currency header branch
app/Services/Currency/UserCurrencyPreferenceService.php // getGuestCurrencyCode → getHeaderCurrencyCode, setGuestCurrencyCode removal, normalize rename, drop decryptLegacy
config/cors.php                                        // allowed_headers add X-Currency
config/currency.php                                    // remove guest_cookie_* block (or deprecate)
app/Http/Controllers/Api/Currency/CurrencyController.php // select(): remove setGuestCurrencyCode cookie write, keep setUserPreference + forgetEffectiveCode
```

**MAY CHANGE (optional cleanup):**
```
app/Http/Middleware/EncryptCookies.php // remove 'guest_currency' from $except (only if config removed)
tests/Feature/Currency/CurrencyCorsAndBrandIntegrationTest.php
tests/Feature/Currency/GuestCurrencyCookieTest.php // rename to GuestCurrencyHeaderTest, assert header not cookie
tests/Feature/Currency/UserCurrencyPreferenceTest.php // guest cookie tests → header tests
.env.example // update CORS_ALLOWED_HEADERS to include X-Currency, remove GUEST_CURRENCY_COOKIE_* docs
```

**DO NOT CHANGE:**
```
app/Services/Currency/CurrencyService.php logic except source line (keep priority, conversion, caching)
app/Services/Currency/CurrencyConversionService.php
app/Services/Currency/CurrencyRateService.php
app/Traits/HasCache.php
app/Http/Resources/Product/ConvertsProductPrice.php
app/Http/Resources/Product/ProductResource.php / ProductMiniResource.php
app/Http/Resources/Brand/BrandResource.php
app/Services/General/BrandService.php (already enriches)
app/Services/General/ProductService.php
Marvel\Database\Models\Brand / Product / Currency / CurrencyRate
Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse / StartSession / Auth
```

---

## 10. Frontend Changes

**Frontend not in repo** (`glob frontend/*` empty, `package.json` is Laravel Mix + Vue template compiler for admin, not storefront). So contract is for external `http://localhost:3000`.

**After backend change, Frontend does:**
```js
// 1. Owns selected currency in memory (or existing state), no cookie ownership, no localStorage needed for currency (spec: do NOT add localStorage)
let selected = 'SAR'; // from user picker

// 2. Every API request adds header centrally (axios interceptor / fetch wrapper) — not per-call
axios.defaults.headers.common['X-Currency'] = selected;
// or
fetch('https://meem.mohammedtareq.me/api/v1/products', {
  headers: { 'X-Currency': selected, 'Accept':'application/json' }
  // credentials:include NO LONGER REQUIRED for currency (but still needed for auth Sanctum)
});

// 3. Authenticated preference still overrides header — no FE logic change; backend ignores header when user_preferences exists

// 4. Select endpoint still useful for auth persistence (optional):
await fetch('/api/v1/general/currencies/select', {
  method:'POST', headers:{'Content-Type':'application/json','X-Currency':selected}, body: JSON.stringify({currency_code:selected})
});
// → sets user_preferences for logged-in user; guest header is enough, cookie not needed
```

Central interceptor avoids modifying every service.

---

## 11. Backend Changes (detail)

* **CurrencyService.php:90** — replace:
```php
$guestCode = $this->preferenceService->getGuestCurrencyCode();
```
with
```php
$guestCode = $this->preferenceService->getHeaderCurrencyCode(); // reads X-Currency
```
* **UserCurrencyPreferenceService.php:37** — change `getGuestCurrencyCode()` to:
```php
public function getHeaderCurrencyCode(?Request $r=null): ?string {
  $r ??= request();
  $raw = $r->header('X-Currency') ?? $r->header('x-currency');
  return $this->normalizeHeaderValue($raw);
}
```
Keep `normalizeHeaderValue` = current `normalizeGuestValue` minus `decryptLegacy` (still `^[A-Z]{3}$` + `isValidActiveCurrency` guard). Remove `setGuestCurrencyCode`/`clearGuestCurrencyCode`/`decryptLegacyGuestValue`/`cookieName()` if fully removing cookie; or keep for BC but unused.

* **CurrencyController.php:24** `select()` — keep:
```php
$code = strtoupper($request->validated()['currency_code']);
if($user) $prefService->setUserPreference($user,$code);
app(CurrencyService::class)->forgetEffectiveCode();
return new CurrencyResource(Currency::where('code',$code)->first());
```
Remove `setGuestCurrencyCode` line.

* **config/cors.php:55** — `allowed_headers` default include `X-Currency` (add to env `CORS_ALLOWED_HEADERS`).

* **config/currency.php** — delete guest_cookie_* keys (5 keys + comments).

---

## 12. API Contract (final)

**Every request (guest or auth):**
```http
GET /api/v1/products HTTP/1.1
Host: meem.mohammedtareq.me
Accept: application/json
X-Currency: SAR
```
```http
GET /api/v1/general/brands/nike HTTP/1.1
X-Currency: SAR
```
Response (unchanged shape, now header-driven):
```json
{
  "id": 5, "name": "Nike",
  "products": [{ "id":1, "price":375.0, "price_after_discount":375.0, "currency":{"id":3,"code":"SAR","name":{"en":"Saudi Riyal"},"symbol":{"en":"ر.س"},"icon":"sa"}}]
}
```

**Select (still needed for auth persistence, optional for guest):**
```http
POST /api/v1/general/currencies/select HTTP/1.1
Content-Type: application/json
X-Currency: SAR   # optional, not required
Accept: application/json

{"currency_code":"SAR"}
```
*Validation:* `SelectCurrencyRequest.php:30` `required|string|size:3|exists:currencies,code where is_active=true` → 200 ` {data:{code:"SAR"}} ` or 422. For auth, writes `user_preferences`; for guest, no cookie, just validates and returns resource; effective currency on next request comes from `X-Currency` header (not from select’s side effect). FormData (`multipart/form-data` with `currency_code=SAR`) still works via same `prepareForValidation`.

---

## 13. CORS Configuration

**Current:** `config/cors.php` already env-driven, filters `*` when `supports_credentials`, `paths=["api/*"]`, `HandleCors` global. `allowed_headers` currently missing `X-Currency`.

**Minimum change:**
```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,https://your-production-frontend.example.com
CORS_SUPPORTS_CREDENTIALS=false  # can be false now (X-Currency not cookie), keep true only if still using Sanctum cookies for auth
CORS_ALLOWED_HEADERS=Content-Type,Accept,Authorization,lang,x-channel,X-Currency
# or explicitly add X-Currency to existing list
```

Do not set `CORS_ALLOWED_ORIGINS=*` when `CORS_SUPPORTS_CREDENTIALS=true`. `OPTIONS` preflight handled automatically by `HandleCors` for `api/*`; verify `Access-Control-Allow-Headers` contains `X-Currency`, `Access-Control-Allow-Origin` echoes requesting origin, `Access-Control-Allow-Credentials` matches config.

---

## 14. Testing Plan

**Existing:** `GuestCurrencyCookieTest.php:30` (plaintext, legacy decrypt, fallback), `UserCurrencyPreferenceTest.php:48` (pref>guest, guest fallback), `ProductCurrencyTest.php:1` (convert 100 USD→22.1 KWD, metadata), `CurrencyCorsAndBrandIntegrationTest.php:1` (15 tests covering CORS explicit origin, preflight, brand conversion, cache).

**Add/Update:**

| # | Test | Existing? | New assertion |
|---|---|---|---|
| 1 | Guest `X-Currency: AED` | No | `call('GET', '/general/brands/nike', [], [], [], ['HTTP_X_CURRENCY'=>'AED'])` → price converted |
| 2 | Guest `X-Currency: SAR` | Partial (cookie SAR) | Same but header `SAR` → 375 |
| 3 | Missing header | Yes (fallback USD) | No header → catalog `USD` |
| 4 | Unknown `XXX` | Yes (cookie XXX fallback) | Header `XXX` → fallback catalog, not 500; select `POST XXX` → 422 |
| 5 | Inactive currency | Yes | Header inactive → fallback |
| 6 | Auth pref overrides header | Yes (cookie) | Auth `KWD` pref + header `SAR` → `KWD` |
| 7 | `CurrencyService` central | Yes | Unit: `getEffectiveCode()` with header |
| 8 | Brand products convert | Yes (cookie) | Update to header via `HTTP_X_CURRENCY` |
| 9 | Brand metadata | Yes | Same |
| 10 | CORS explicit origin | Yes | Keep, add header `X-Currency` in `Access-Control-Request-Headers` |
| 11 | CORS allows `X-Currency` | No | Preflight `Access-Control-Request-Headers: X-Currency` → `Allow-Headers` contains `X-Currency` |
| 12 | No wildcard with credentials | Yes | Keep |
| 13 | OPTIONS preflight | Yes | Keep, ensure `X-Currency` allowed |
| 14 | No cookie required | No | Assert `guest_currency` cookie not set on `select` or product response |
| 15 | Logout not affect | Yes | Keep, ensure `X-Currency` still works post-logout (no guest identifier) |

Update cookie tests to assert header path and that `Cookie::queued('guest_currency')` is null.

---

## 15. Risk Assessment

| Change | Risk | Why |
|---|---|---|
| `CurrencyService` source → header | **Medium** | Touches every pricing path; but isolated to one method, priority unchanged, validated. |
| `UserCurrencyPreferenceService` rename/remove cookie methods | **Low** | Cookie methods unused after; shared `isValidActiveCurrency` kept. |
| `config/currency.php` removal | **Low** | Only cookie keys; no other feature reads them after removal. |
| `CurrencyController::select` remove cookie write | **Low** | Guest header is stateless; auth pref still written. |
| `config/cors.php` add `X-Currency` | **Low** | Additive whitelist, no `*` risk. |
| `EncryptCookies` remove except | **Low** | Only affects `guest_currency`; auth/session cookies still encrypted. |
| `Brand*` already fixed | **Low** | No new change. |

Overall risk **Medium** (single central read path) but mitigated by existing priority tests.

---

## 16. Implementation Difficulty

| Change | Difficulty | Why |
|---|---|---|
| Header read + validation | **Easy** | 10 lines, reuse `normalize` + `isValidActiveCurrency` |
| CORS add `X-Currency` | **Easy** | One env string |
| Config cleanup | **Easy** | Delete block |
| Select remove cookie | **Easy** | Delete one line |
| Tests header | **Easy** | Replace `withCookie` with `['HTTP_X_CURRENCY'=>...]` |
| EncryptCookies cleanup | **Easy** | One line |

All **Easy** (no DB, no new service, no pricing formula).

---

## 17. Recommended Implementation Order

1. **CORS** — add `X-Currency` to `allowed_headers` (safe, no logic change, unblocks preflight).
2. **UserCurrencyPreferenceService** — add `getHeaderCurrencyCode()` + `normalizeHeaderValue()`, keep `getGuestCurrencyCode()` temporarily for BC.
3. **CurrencyService** — switch `getEffectiveCode()` to `getHeaderCurrencyCode()` (or try header then cookie for transitional).
4. **CurrencyController::select** — remove `setGuestCurrencyCode()` cookie write (keep `setUserPreference`).
5. **Config & EncryptCookies** — remove guest cookie keys and `except` entry (after header proven).
6. **Tests** — update `GuestCurrencyCookieTest` → `GuestCurrencyHeaderTest`, `CurrencyCorsAndBrandIntegrationTest` to use `HTTP_X_CURRENCY`, assert no `Set-Cookie: guest_currency`.
7. **Verify** — run `tests/Feature/Currency/*` (183) + brand/product suites; manual `curl -H "X-Currency: SAR"` vs no header vs auth pref override.

---

### No redesign confirmed

All changes stay inside `CurrencyService`/`UserCurrencyPreferenceService`; controllers/resources keep calling `CurrencyService`; `X-Guest-ID`/localStorage/new conversion service not introduced.

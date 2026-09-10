# Backend - Currency Feature

## Controllers

### Admin CurrencyController — `packages/marvel/src/Http/Controllers/CurrencyController.php`

Namespace `Marvel\Http\Controllers`, extends `CoreController`, uses `ApiResponse` trait. Constructor-injected `CurrencyService`.

**Middleware (permissions):**

| Method | Permission |
|--------|-----------|
| `index`, `show` | `Permission::VIEW_CURRENCIES` (`view-currencies`) |
| `store` | `Permission::CREATE_CURRENCY` (`create-currency`) |
| `update` | `Permission::UPDATE_CURRENCY` (`update-currency`) |
| `destroy` | `Permission::DELETE_CURRENCY` (`delete-currency`) |
| `setBase` | `Permission::SET_BASE_CURRENCY` (`set-base-currency`) |
| `setCatalog` | `Permission::SET_CATALOG_CURRENCY` (`set-catalog-currency`) |
| `setRateMode` | `Permission::UPDATE_CURRENCY` (`update-currency`) |

**index(Request)**
1. `limit = max(1, min((int)$request->query('limit', 15), 100))`.
2. Filters:
   - `search` — `LIKE` across `code`, `numeric_code`, `name->en`, `name->ar`, `symbol->en`, `symbol->ar`, `country_name->en`, `country_name->ar`.
   - `code` — exact code filter.
   - `is_active` — boolean filter (`filter_var(..., FILTER_VALIDATE_BOOLEAN)`; absent → no filter).
   - `sort_order` — exact integer filter.
3. `Currency::query()` with the above `when(...)` clauses `.orderBy('sort_order')->orderBy('code')->paginate($limit)`.
4. Transforms via `CurrencyResource::collection(...)`, extracts pagination metadata into the `data` object.
5. Returns `apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])`.

**show(int $id)**
- `Currency::withTrashed()->find($id)`; missing → `apiResponse(CURRENCY_NOT_FOUND, 404, false)`.
- Returns `CurrencyResource::make($currency)`.

**store(StoreCurrencyRequest)**
- `$this->currencyService->storeCurrency($request->validated())` → 200 `CURRENCY_CREATED_SUCCESSFULLY`.

**update(UpdateCurrencyRequest, int $id)**
- `Currency::withTrashed()->find($id)`; missing → 404.
- `currencyService->updateCurrency(...)` → 200 `CURRENCY_UPDATED_SUCCESSFULLY`.

**destroy(int $id)**
- `Currency::withTrashed()->find($id)`; missing → 404.
- `currencyService->deleteCurrency($currency)`:
  - `CurrencyInUseException` with `reason === REASON_BASE_CURRENCY` → **409** `CANNOT_DELETE_BASE_CURRENCY`.
  - otherwise → **409** `CANNOT_DELETE_CURRENCY_IN_USE`.
- Success → 200 `CURRENCY_DELETED_SUCCESSFULLY`.

**setBase(int $id)**
- `Currency::withTrashed()->find($id)`; missing → 404.
- `currencyService->setBaseCurrency($currency)`:
  - `CurrencyInactiveException` → **422** `CURRENCY_INACTIVE`.
  - `CurrencyRateNotFoundException` → **422** `EXCHANGE_RATE_NOT_FOUND`.
- Success → 200 `SET_BASE_CURRENCY_SUCCESSFULLY` + `CurrencyResource`.

**setCatalog(int $id)**
- `Currency::withTrashed()->find($id)`; missing → 404.
- `currencyService->setCatalogCurrency($currency)`:
  - `CurrencyInactiveException` → **422** `CURRENCY_INACTIVE`.
  - `CurrencyRateNotFoundException` → **422** `EXCHANGE_RATE_NOT_FOUND`.
- Success → 200 `SET_CATALOG_CURRENCY_SUCCESSFULLY` + `CurrencyResource`.

**setRateMode(int $id, UpdateCurrencyRateModeRequest)**
- `Currency::withTrashed()->find($id)`; missing → 404.
- `currencyService->setRateMode($currency, RateMode::from(mode), manual_rate)` with **provider-driven AUTO**: if `mode=auto` and no fresh `provider_rate` (`provider_rate_at` ≤72h or missing), automatically fetches `ExchangeRateProviderInterface::getLatestRates(anchor, [code])` (HTTP before DB lock), validates `>0` `decimal(20,10)` `≤1_000_000` and anomaly `>30%` (initial `provider_rate=null` allowed), then locks rows, freshness check, `source`/`provider` update, `effective_rate_updated_at`, `LogActivityJob rateModeChanged`.
  - Invalid `mode`/`manual_rate` → 422.
  - `MANUAL→AUTO` with missing/stale/anomalous provider rate (including auto-fetch failure) → 422 `EXCHANGE_RATE_NOT_FOUND`.
- Success → 200 `CURRENCY_UPDATED_SUCCESSFULLY` + extended `CurrencyResource` (`effective_rate, rate_mode, manual_rate, provider_rate, provider, last_synced_at, provider_rate_at, effective_rate_updated_at, is_rate_stale`).

### Admin RateController — `packages/marvel/src/Http/Controllers/CurrencyRateController.php`

Namespace `Marvel\Http\Controllers`, extends `CoreController`, uses `ApiResponse`. Injected `CurrencyRateService`.

**Middleware (permissions):**

| Method | Permission |
|--------|-----------|
| `index`, `show` | `Permission::VIEW_EXCHANGE_RATES` |
| `store` | `Permission::CREATE_EXCHANGE_RATE` |
| `update` | `Permission::UPDATE_EXCHANGE_RATE` |
| `destroy` | `Permission::DELETE_EXCHANGE_RATE` |

> `destroy` now correctly uses `DELETE_EXCHANGE_RATE` (2026-09-08 fix) and returns **409** if the row is the current effective rate (`CurrencyInUseException::isOnlyEffectiveRate()`).

- `index` → `currencyRateService->list($currencyId, $effectiveDate, $dateFrom, $dateTo, $code, $limit)`, `CurrencyRateResource::collection` (Marvel resource includes nested `currency` when loaded), pagination metadata. Query params: `limit`, `currency_id`, `effective_date`, `date_from`, `date_to`, `code`.
- `show(int $id)` → `CurrencyRate::with('currency')->find($id)`; missing → 404.
- `store` → `currencyRateService->store($validated)` (upsert, `source=manual`, today's → `MANUAL`) → `CURRENCY_RATE_CREATED_SUCCESSFULLY`.
- `update(int $id)` → `CurrencyRate::find($id)`; missing → 404; `currencyRateService->update(...)` (`source=manual`) → `CURRENCY_RATE_UPDATED_SUCCESSFULLY`.
- `destroy(int $id)` → `CurrencyRate::find($id)`; missing → 404; `currencyRateService->delete(...)` → `CURRENCY_RATE_DELETED_SUCCESSFULLY` or **409** if current effective rate.

### Public Controller — `app/Http/Controllers/Api/Currency/CurrencyController.php`

Namespace `App\Http\Controllers\Api\Currency`, extends `App\Http\Controllers\Controller`, uses `ApiResponse` + `HasCache`.

- `index()` → `Currency::active()->orderBy('sort_order')->orderBy('code')->get()`.
- Caches `CurrencyResource::collection(...)` under `FrontendResource::CURRENCIES->value` tag, key `md5(request()->fullUrl())`, TTL 4h.
- Returns `apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $cached)`. Public `CurrencyResource` now exposes `effective_rate` (canonical) in addition to `is_base/is_catalog`.
- `select(SelectCurrencyRequest)` → `POST /api/v1/general/currencies/select`:
  1. `strtoupper` the validated `currency_code`.
  2. `$user = auth('sanctum')->user() ?? auth()->user()`; if present, `UserCurrencyPreferenceService::setUserPreference($user, $code)` + `adoptGuestCurrencyOnLogin` for `X-Currency` header.
  3. `CurrencyService::forgetEffectiveCode()`; guest transport is now `X-Currency` header (`UserCurrencyPreferenceService::getHeaderCurrencyCode()` with `normalizeHeaderValue()` `/^[A-Z]{3}$/`), legacy `guest_currency` cookie is deprecated (`CORS_ALLOWED_HEADERS` includes `X-Currency`).
  4. Returns `apiResponse(CURRENCY_SELECTED_SUCCESSFULLY, 200, true, new CurrencyResource($currency))`.

> **Gating:** the stored preference/header only affects `getEffectiveCode()` when `isCurrencySelectionEnabled()` is `true`. Otherwise the effective currency always resolves to the catalog code.

## Services

### CurrencyService (singleton) — `app/Services/Currency/CurrencyService.php`

Registered as singleton in `AppServiceProvider::register()` (line 26). Uses `HasCache` trait for tag-based settings/cache flushing. Memoizes base/catalog codes + currencies and an in-memory rate cache.

- `getBaseCode()` / `getCatalogCode()` — from settings options, uppercase, default `config('shop.default_currency', 'USD')`.
- `getBaseCurrency()` / `getCatalogCurrency()` — query `Currency` by code.
- `isCurrencySelectionEnabled()` — memoized bool from `settings.options.currency_selection_enabled` (default `false`).
- `getEffectiveCode(?User)` / `getEffectiveCurrency()` — when selection is **disabled** returns the catalog code (ignores stored preference/header); when **enabled** resolves `user preference > X-Currency header > catalog code` (via `UserCurrencyPreferenceService`), validating/clearing stale codes and falling back to catalog when preference tables are absent.
- `forgetEffectiveCode()` — resets `effectiveCode`, `effectiveCurrency` and the `currencySelectionEnabled` memo (called on settings update, `select`, set-base/set-catalog, rate writes). `forgetRateCache()` clears `$rateCache` after any effective-rate mutation (sync, mode switch, rate write).
- `convert(amount, from, to, ?date)` — delegates to `CurrencyConversionService`.
- `convertPrice(amount, from, to, ?date)` — float conversion rounded to 2 decimals; identity short-circuit.
- `storeCurrency(array)` — `Currency::create` + `invalidatePriceCaches()`.
- `updateCurrency(Currency, array)` — update + cache invalidation + `fresh()`.
- `deleteCurrency(Currency)` — base-currency check first, then `rates()->exists()`, throws `CurrencyInUseException`; soft delete + cache invalidation.
- `setBaseCurrency(Currency)` — transaction + row lock on settings; rejects inactive; requires a rate `<= today`; writes `base_currency_code` and `currency` options; flushes cached settings via `flushTag(FrontendResource::SETTINGS->value)`; resets memoized base; flushes price caches including settings tag.
- `setCatalogCurrency(Currency)` — transaction + row lock on settings; rejects inactive; requires a rate `<= today`; writes **only** `catalog_currency_code` option (base + `currency` untouched); flushes cached settings; resets memoized catalog; flushes price caches.
- `setRateMode(Currency, RateMode, manualRate)` — transaction + `lockForUpdate` on `currencies` and today's `currency_rates`; validates `manualRate` (`normalizePositiveRate` regex `^\d+(\.\d{1,10})?$` `>0` `≤1_000_000`) or requires fresh `provider_rate` (`provider_rate_at` ≤72h) for AUTO; upserts today's `currency_rates` with `source`/`provider`; updates `rate_mode`/`manual_rate`/`effective_rate_updated_at`; `invalidatePriceCaches()` + `LogActivityJob rateModeChanged` with `auth('sanctum')`.
- `invalidatePriceCaches(bool $flushSettings = false)` — flushes tag set: `currencies`, `products`, all product strategy tags (`products_{type}`), and optionally `settings`, then `HomeService::clearCache()` and `forgetRateCache()`.
- `resolveRate(code, date)` — private; per-key array cache; latest `effective_date <= date` via `whereDate`; throws `CurrencyRateNotFoundException`.

### CurrencyConversionService — `app/Services/Currency/CurrencyConversionService.php`

- `const SCALE = 6`.
- `convert(amount, from, to, ?date)`:
  - Uppercases codes; `date ?? now()->toDateString()`.
  - Identity (`from === to`) → `CurrencyConversionResult(amount, round(amount,2), rate:'1', ...)` — no DB query.
  - `sourceRate = resolveRate(from)`, `targetRate = resolveRate(to)`.
  - `converted = bcdiv(bcmul(amount, targetRate, 6), sourceRate, 6)`; `convertedAmount = round((float)$converted, 2)`.
  - `rate = bcdiv(targetRate, sourceRate, 6)`.
- `resolveRate(code, date)` — same latest-`<=`-date lookup; throws `CurrencyRateNotFoundException`.

### CurrencyRateService — `app/Services/Currency/CurrencyRateService.php`

- `store(array)` — transactional `lockForUpdate` on `currencies` and `currency_rates` unique key, upsert with `source=manual, provider=null`; if `effective_date` is today sets `currencies.rate_mode=manual, manual_rate=exchange_rate`; then `invalidatePriceCaches()`.
- `update(CurrencyRate, array)` — same transactional MANUAL semantics.
- `delete(CurrencyRate)` — transactional, protects current effective rate (`effective_date <= today` latest row → 409 `CANNOT_DELETE_CURRENCY_IN_USE` via `CurrencyInUseException::isOnlyEffectiveRate()`); otherwise hard delete; invalidates caches.
- `list(?currencyId, ?effectiveDate, ?dateFrom, ?dateTo, ?code, limit)` — `with('currency')`, filters (`currency_id`, `whereDate effective_date`, `whereDate >= date_from`, `whereDate <= date_to`, `whereHas currency.code = code`), `orderByDesc('effective_date')`, `orderByDesc('id')`, paginate.

### UserCurrencyPreferenceService — `app/Services/Currency/UserCurrencyPreferenceService.php`

- `setUserPreference(User, string $code)` — store the user's currency preference (`user_preferences` table).
- `getUserPreference(?User)` — read stored preference (per-user).
- `clearUserPreference(User)` / `clearGuestCurrencyCode()` — clear stale/invalid selections.
- `getHeaderCurrencyCode(?Request)` — read and normalize `X-Currency` header (`normalizeHeaderValue` `/^[A-Z]{3}$/`), canonical guest transport (cookie deprecated).
- `adoptGuestCurrencyOnLogin(User, ?Request)` — adopts header value if user has no preference.
- `isValidActiveCurrency(string $code)` — verify the code exists and is active.

## DTO — `app/DTOs/CurrencyConversionResult.php`

Immutable readonly object: `amount`, `convertedAmount`, `rate`, `effectiveDate`, `fromCode`, `toCode`, `sourceRate`, `targetRate`.

## Exceptions

| Class | Trigger | Reason/Message |
|-------|---------|----------------|
| `CurrencyInUseException` | Delete base or referenced currency | `REASON_BASE_CURRENCY` / `REASON_REFERENCED_BY_RATES` |
| `CurrencyInactiveException` | Set inactive base | `Inactive currency [X] cannot be set as the base currency.` |
| `CurrencyRateNotFoundException` | No rate `<=` date | `No exchange rate found for currency [X] on or before [Y].` |

`CurrencyInactiveException` and `CurrencyRateNotFoundException` are also thrown by `setCatalogCurrency` (inactive catalog currency / missing rate), surfacing as `CURRENCY_INACTIVE` / `EXCHANGE_RATE_NOT_FOUND`.

## Models

### Currency — `app/Models/Currency.php`
`HasTranslations` (`name`, `symbol`, `country_name`), `SoftDeletes`. Fillable: code, name, symbol, country_name, numeric_code, decimal_places, icon, is_active, sort_order, `rate_mode, manual_rate, provider_rate, provider, last_synced_at, provider_rate_at, effective_rate_updated_at`. Casts: `is_active=>bool, decimal_places=>int, sort_order=>int, rate_mode=>RateMode, manual_rate=>string, provider_rate=>string, last_synced_at=>datetime, provider_rate_at=>datetime, effective_rate_updated_at=>datetime`. Helpers: `isBaseCurrency()`, `isCatalogCurrency()`, `effectiveRate()`, `isRateStale()` (stale if `last_synced_at` null or >12h). Relation: `rates()`.

### CurrencyRate — `app/Models/CurrencyRate.php`
Fillable: currency_id, exchange_rate, effective_date, `source, provider`. Casts: `exchange_rate => string`, `effective_date => date`, `source=>RateSource`. Scope `effectiveOn($date)`: `whereDate('effective_date', '<=', $date)->orderByDesc('effective_date')`. Relation: `currency()`.

## API Resources

### CurrencyResource — `packages/marvel/src/Http/Resources/Currency/CurrencyResource.php` (admin, Marvel)
Fields: id, code, name, symbol, country_name (translated `getTranslations`), numeric_code, decimal_places (int), icon, is_active (bool), sort_order (int), `is_base`, `is_catalog`, `effective_rate`, `rate_mode` (`manual|auto`), `manual_rate` (string|null), `provider_rate` (string|null), `provider` (string|null), `last_synced_at` (ISO8601|null), `provider_rate_at` (ISO8601|null), `effective_rate_updated_at` (ISO8601|null), `is_rate_stale` (bool), created_at, updated_at.
App public resource `app/Http/Resources/Currency/CurrencyResource.php` exposes `effective_rate` plus `is_base/is_catalog` minimally; admin resource is the full contract.

### CurrencyRateResource — `packages/marvel/src/Http/Resources/Currency/CurrencyRateResource.php` (Marvel)
Fields: id, `currency` (nested `id,code,name,symbol` when `whenLoaded`), `exchange_rate`, `effective_date` (`toDateString()`), `source` (`legacy|manual|provider`), `provider` (string|null), created_at, updated_at. The `currency` relation is eager-loaded for filtering/ordering and now serialized. App flat resource remains without nested `currency`.

## Form Requests

| Request | Rules |
|---------|-------|
| `StoreCurrencyRequest` | code size:3 + regex + unique (uppercased), translatable arrays with unique translation, decimal_places 0–4 |
| `UpdateCurrencyRequest` | same fields, all `sometimes`/`nullable` |
| `StoreCurrencyRateRequest` | currency_id exists, exchange_rate numeric gt:0, effective_date date |
| `UpdateCurrencyRateRequest` | exchange_rate required numeric gt:0 |
| `UpdateCurrencyRateModeRequest` | `mode` required `in:auto,manual` (lowercased), `manual_rate` required when `mode=manual`, `regex:/^\d+(\.\d{1,10})?$/`, `gt:0` |
| `SelectCurrencyRequest` | currency_code required, string, max:3, exists in `currencies` with `is_active = true` |

## Enums & Constants

- `App\Enums\FrontendResource::CURRENCIES` = `'currencies'` — public cache tag.
- `App\Enums\RateMode` (`manual`, `auto`) and `App\Enums\RateSource` (`legacy`, `manual`, `provider`) — new 2026-09-08.
- `Marvel\Enums\Permission` lines 92–100: `VIEW_CURRENCIES`, `CREATE_CURRENCY`, `UPDATE_CURRENCY`, `DELETE_CURRENCY`, `VIEW_EXCHANGE_RATES`, `CREATE_EXCHANGE_RATE`, `UPDATE_EXCHANGE_RATE`, `DELETE_EXCHANGE_RATE`, `SET_BASE_CURRENCY`, `SET_CATALOG_CURRENCY`.
- `packages/marvel/config/constants.php` lines 525–566 — message keys for all currency operations/errors (incl. `SET_CATALOG_CURRENCY_SUCCESSFULLY`).

## Routes

- Admin: `packages/marvel/src/Rest/Routes.php` (apiResource with `whereNumber('currency')` / `whereNumber('currency_rate')`, `set-base` + `set-catalog` + `PATCH currencies/{id}/rate-mode` with `whereNumber('id')`), all inside the `auth:sanctum` + `throttle:admin` group.
- Public: `routes/api.php` lines 100–101 under `v1/general` prefix (`GET currencies`, `POST currencies/select`).
- Console: `app/Console/Kernel.php` — `currency:sync-rates` `everySixHours UTC withoutOverlapping(60) onOneServer when(currency.enabled)`; OS `* * * * * schedule:run` unchanged.
- Config: `config/currency.php` — `anchor=USD`, `enabled=false`, `provider=exchange_rate_api`, `timeout/connect/retries`, `sync stale 12h / provider_max_age 72h`, `validation precision 10 / hard_max 1_000_000 / max_change 30%`.

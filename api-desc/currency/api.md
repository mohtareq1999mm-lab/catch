# API Documentation - Currency Feature

All admin endpoints require `auth:sanctum` and `permission:*`, prefix `/api/v1`. Public endpoint prefix `/api/v1/general`.

Response envelope (via `Marvel\Traits\ApiResponse`):
```json
{ "status": 200, "message": "translated", "success": true, "data": { ... } }
```
Error envelope: `{ "status": 4xx, "message": "translated", "success": false }`.

---

## 1. List Currencies (Admin)

**GET** `/api/v1/currencies`

### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `limit` | int | No | Per page (default 15, max 100, min 1) |
| `page` | int | No | Page number |
| `search` | string | No | Free-text search across `code`, `numeric_code`, `name`, `symbol`, `country_name` (en & ar translations) |
| `code` | string | No | Exact code filter (e.g. `USD`) |
| `is_active` | bool | No | Filter by `true`/`false` (`1`/`0`) |
| `sort_order` | int | No | Filter by exact `sort_order` value |

### Response

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "code": "USD",
        "name": "US Dollar",
        "symbol": "$",
        "country_name": "United States",
        "numeric_code": "840",
        "decimal_places": 2,
        "icon": "us",
        "is_active": true,
        "sort_order": 1,
        "is_base": true,
        "is_catalog": true,
        "effective_rate": "1.0000000000",
        "rate_mode": "manual",
        "manual_rate": "1.0000000000",
        "provider_rate": null,
        "provider": null,
        "last_synced_at": null,
        "provider_rate_at": null,
        "effective_rate_updated_at": "2026-08-10T00:00:00+00:00",
        "is_rate_stale": false,
        "created_at": "2026-08-10T00:00:00+00:00"
}
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 1,
    "path": "http://localhost/api/v1/currencies",
    "per_page": 15,
    "total": 3,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/currencies?page=1",
    "first_page_url": "http://localhost/api/v1/currencies?page=1"
}
}
```

> **New fields (2026-09-08):** `effective_rate` (canonical active rate, `manual`→`manual_rate` else `provider_rate`), `rate_mode` (`manual|auto`), `manual_rate`, `provider_rate`, `provider` (`exchange_rate_api`), `last_synced_at`, `provider_rate_at`, `effective_rate_updated_at`, `is_rate_stale` (true if `last_synced_at` is null or >12h ago). `manual_rate`/`provider_rate` are `decimal(20,10)` strings. Frontend MUST use `effective_rate` and MUST NOT branch on mode.

> **Pagination envelope note:** this is a **custom flattened envelope** produced by `CurrencyController::index()` (not Laravel's default `{data, meta, links}`). Keys inside `data`: `data`, `page`, `current_page`, `from`, `to`, `last_page`, `path`, `per_page`, `total`, `next_page_url`, `prev_page_url`, `last_page_url`, `first_page_url`. The same shape is used by `CurrencyRateController::index()`.

---

## 2. Create Currency (Admin)

**POST** `/api/v1/currencies`

### Request Body

```json
{
  "code": "EGP",
  "name": { "en": "Egyptian Pound", "ar": "جنيه مصري" },
  "symbol": { "en": "E£", "ar": "ج.م" },
  "country_name": { "en": "Egypt", "ar": "مصر" },
  "numeric_code": "818",
  "decimal_places": 2,
  "icon": "eg",
  "is_active": true,
  "sort_order": 7
}
```

### Validation Rules (`StoreCurrencyRequest`)

| Field | Rules |
|-------|-------|
| `code` | required, string, **size:3**, regex `/^[A-Za-z]{3}$/`, unique `currencies.code` (case-insensitive via uppercase merge) |
| `name` | required, array |
| `name.*` | required, string, max:255, unique translation |
| `symbol` | required, array |
| `symbol.*` | required, string, max:255, unique translation |
| `country_name` | required, array |
| `country_name.*` | required, string, max:255, unique translation |
| `numeric_code` | nullable, string, max:3 |
| `decimal_places` | nullable, integer, min:0, max:4 |
| `icon` | nullable, string, max:255 |
| `is_active` | nullable, boolean |
| `sort_order` | nullable, integer |

`prepareForValidation()` uppercases `code` before validation.

### Success Response (200)
Same structure as list item, `message` = `CURRENCY_CREATED_SUCCESSFULLY` (`Currency created successfully`).

### Errors
| Status | Condition |
|--------|-----------|
| 422 | Validation failures (e.g. code length/format, duplicate code, invalid decimal_places) |
| 401 | No token |
| 403 | Missing `create-currency` permission |

---

## 3. Show Currency (Admin)

**GET** `/api/v1/currencies/{currency}`

- `{currency}` constrained numeric (route `whereNumber('currency')`).
- Looks up with `withTrashed()` (a soft-deleted currency can still be viewed).
- Missing / non-numeric → 404 (`CURRENCY_NOT_FOUND`).

### Response (200)
Same structure as list item, `is_base` / `is_catalog` reflect current settings.

---

## 4. Update Currency (Admin)

**PUT** `/api/v1/currencies/{currency}`

### Request Body (all optional — `UpdateCurrencyRequest`)

| Field | Rules |
|-------|-------|
| `name` | sometimes, array |
| `symbol` | nullable, array |
| `country_name` | nullable, array |
| `numeric_code` | nullable, string, max:3 |
| `decimal_places` | nullable, integer, min:0, max:4 |
| `icon` | nullable, string, max:255 |
| `is_active` | nullable, boolean |
| `sort_order` | nullable, integer |

### Response (200)
`message` = `CURRENCY_UPDATED_SUCCESSFULLY`. Missing → 404.

---

## 5. Delete Currency (Admin)

**DELETE** `/api/v1/currencies/{currency}`

### Guards (in `CurrencyService::deleteCurrency`)

1. If `code === baseCode` → `CurrencyInUseException(REASON_BASE_CURRENCY)` → **409** `CANNOT_DELETE_BASE_CURRENCY`.
2. If `rates()->exists()` → `CurrencyInUseException(REASON_REFERENCED_BY_RATES)` → **409** `CANNOT_DELETE_CURRENCY_IN_USE`.
3. Otherwise soft delete (`deleted_at` set) → **200** `CURRENCY_DELETED_SUCCESSFULLY`.

### Errors
| Status | Condition |
|--------|-----------|
| 409 | Base currency or currency referenced by rates |
| 404 | Currency not found |

---

## 6. Set Base Currency (Admin)

**POST** `/api/v1/currencies/{id}/set-base`

`{id}` constrained numeric.

### Flow (`CurrencyService::setBaseCurrency`)
1. `DB::transaction`, lock settings row.
2. Reject inactive currency → **422** `CURRENCY_INACTIVE`.
3. Require an exchange rate `effective_date <= today` → **422** `EXCHANGE_RATE_NOT_FOUND`.
4. Update settings options: `base_currency_code` = code, `currency` = code; clear cached settings.
5. Reset memoized base in singleton, flush price caches.

### Response (200)
`message` = `SET_BASE_CURRENCY_SUCCESSFULLY`, data = `CurrencyResource`.

---

## 6a. Set Catalog Currency (Admin)

**POST** `/api/v1/currencies/{id}/set-catalog`

`{id}` constrained numeric (`whereNumber('id')`).

### Description
Marks the given currency as the **catalog currency** for the storefront. Only the `catalog_currency_code` setting option is updated; the base currency and the `currency` option are left untouched. This is the single source used for product-price conversion and display when `currency_selection_enabled` is `false`. Requires the currency to be active and to have an exchange rate whose `effective_date <= today`.

### Permission
`set-catalog-currency` (Spatie). Admin route group `auth:sanctum` + `throttle:admin`.

### Flow (`CurrencyService::setCatalogCurrency`)
1. `DB::transaction`, lock settings row.
2. Reject inactive currency → **422** `CURRENCY_INACTIVE`.
3. Require an exchange rate `effective_date <= today` → **422** `EXCHANGE_RATE_NOT_FOUND`.
4. Update settings options: `catalog_currency_code` = code only. **`base_currency_code` and `currency` are left untouched.**
5. Clear cached settings (tag flush), reset memoized catalog code, flush price caches.

### Response (200)
`message` = `SET_CATALOG_CURRENCY_SUCCESSFULLY`, data = `CurrencyResource`.

### Errors
| Status | Condition |
|--------|-----------|
| 422 | Inactive currency / missing rate |
| 404 | Currency not found |

---

## 6b. Set Currency Rate Mode (Admin)

**PATCH** `/api/v1/currencies/{id}/rate-mode` — `{id}` numeric, `whereNumber('id')`.

### Description
Switches the currency's effective-rate ownership. Business logic lives in `CurrencyService::setRateMode()`. When `mode=manual`, today's effective `currency_rates` row becomes `source=manual` and `currencies.manual_rate` is the effective rate. When `mode=auto`, the stored `provider_rate` must exist and be fresh (`provider_rate_at` ≤72h old); today's row becomes `source=provider` and `effective_rate = provider_rate`. Audited via `LogActivityJob rateModeChanged` with `activity.currency_rate_mode_changed`.

### Permission
`update-currency` (Spatie, `throttle:admin` + `auth:sanctum`).

### Request Body (`UpdateCurrencyRateModeRequest`)

| Field | Rules |
|-------|-------|
| `mode` | required, `in:auto,manual` (lowercased in `prepareForValidation`) |
| `manual_rate` | required when `mode=manual`, nullable otherwise, `regex:/^\d+(\.\d{1,10})?$/`, `gt:0`, must fit `decimal(20,10)` and `≤1_000_000` |

### Success Response (200)
`message` = `CURRENCY_UPDATED_SUCCESSFULLY`, `data` = `CurrencyResource` (same shape as List Currencies, with updated `rate_mode` etc.).

### Business Rule — AUTO is provider-driven
When `mode=auto` and no fresh `provider_rate` (`provider_rate_at` ≤72h) exists, the system automatically fetches the latest rate from the configured provider (`ExchangeRate-API Pro` normalized to `CURRENCY_RATE_ANCHOR`) before activation. Valid fresh rate → `rate_mode=auto, manual_rate=null, source=provider`. Missing/stale/anomalous (`>30%` change) → **422** `EXCHANGE_RATE_NOT_FOUND` / validation error. No manual `manual_rate` required for AUTO.

### Errors
| Status | Condition |
|--------|-----------|
| 422 | Invalid `mode`, missing/invalid `manual_rate`, `MANUAL→AUTO` with missing/stale/anomalous provider rate (including auto-fetch failure) → `EXCHANGE_RATE_NOT_FOUND` |
| 401 | No token |
| 403 | Missing `update-currency` |
| 404 | Currency not found |

---

## 7. List Exchange Rates (Admin)

### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `limit` | int | No | Per page (default 15, max 100) |
| `currency_id` | int | No | Filter by currency |
| `effective_date` | date | No | Filter exact date (Y-m-d) |
| `date_from` | date | No | Filter `effective_date >= date` (Y-m-d) |
| `date_to` | date | No | Filter `effective_date <= date` (Y-m-d) |
| `code` | string | No | Filter by currency code (e.g. `USD`) |

### Response

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "currency_id": 2,
        "exchange_rate": "0.2210000000",
        "effective_date": "2026-08-10",
        "source": "legacy",
        "provider": null,
        "created_at": "2026-08-10T00:00:00+00:00"
}
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 3,
    "last_page": 1,
    "path": "http://localhost/api/v1/currency-rates",
    "per_page": 15,
    "total": 3,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/currency-rates?page=1",
    "first_page_url": "http://localhost/api/v1/currency-rates?page=1"
}
}
```

> Same custom flattened pagination envelope as currencies. New fields `source` (`legacy|manual|provider`) and `provider` (`exchange_rate_api` or null) added 2026-09-08. Existing `exchange_rate` semantics unchanged: `1 anchor(USD)=X target`.

---

## 8. Create Exchange Rate (Admin)

**POST** `/api/v1/currency-rates`

### Request Body

```json
{ "currency_id": 2, "exchange_rate": "0.2500000000", "effective_date": "2026-08-10" }
```

### Validation (`StoreCurrencyRateRequest`)

| Field | Rules |
|-------|-------|
| `currency_id` | required, integer, exists `currencies.id` |
| `exchange_rate` | required, numeric, `gt:0` |
| `effective_date` | required, date |

### Business Rule: Upsert
`CurrencyRateService::store()` looks up `(currency_id, effective_date)`; if found it **updates** the rate, otherwise it **creates** a new row. No duplicate rows per currency/day (also enforced by unique DB constraint). As of 2026-09-08, `store` also sets `source=manual, provider=null`, locks `currencies` row, and if `effective_date` is today sets `currencies.rate_mode=manual, manual_rate=exchange_rate`.

### Response (200)
`message` = `CURRENCY_RATE_CREATED_SUCCESSFULLY`, `data` is a `CurrencyRateResource`:
```json
{
  "id": 1,
  "currency_id": 2,
  "exchange_rate": "0.2500000000",
  "effective_date": "2026-08-10",
  "source": "manual",
  "provider": null,
  "created_at": "2026-08-10T00:00:00+00:00"
}
```
> **Note (legacy):** Older docs said `CurrencyRateResource` did not serialize `currency`. The **Marvel** `CurrencyRateResource` now *does* serialize a nested `currency` (`id,code,name,symbol`) when `currency` is eager-loaded; the **App** resource remains flat. Both are valid — admin `GET /currency-rates` uses Marvel resource (with `currency`), admin `POST /currency-rates` returns it with `currency` loaded.

---

## 9. Show Exchange Rate (Admin)

**GET** `/api/v1/currency-rates/{currency_rate}` — `{currency_rate}` numeric. Missing → 404. Returns the same `CurrencyRateResource` shape as create.

---

## 10. Update Exchange Rate (Admin)

**PUT** `/api/v1/currency-rates/{currency_rate}`

`exchange_rate` required, numeric, `gt:0`. Only `exchange_rate` is writable. Missing → 404. On success also sets `source=manual` and if `effective_date` is today switches currency to `MANUAL` (same as create).

---

## 11. Delete Exchange Rate (Admin)

**DELETE** `/api/v1/currency-rates/{currency_rate}` — hard delete. Missing → 404. If `{currency_rate}` is the current effective rate (`effective_date <= today` latest row for that currency) → **409** `CANNOT_DELETE_CURRENCY_IN_USE` (via `CurrencyInUseException::isOnlyEffectiveRate()`). Historical rows → **200** `CURRENCY_RATE_DELETED_SUCCESSFULLY`.

---

## 12. List Currencies (Public / Storefront)

**GET** `/api/v1/general/currencies` — no auth required.

- Returns only `is_active = true` currencies, ordered `sort_order` then `code`.
- Result cached under tag `currencies` with key `md5(fullUrl)`, TTL 4h (`HasCache::remember`).
- Cache invalidated on any currency/rate write via `CurrencyService::invalidatePriceCaches()` (flushes `currencies` + `products` + product strategy tags).

### Response (200)
Flat array of active currencies (same item structure minus admin flags depend on settings).

---

## 12a. Select Currency (Public / Storefront)

**POST** `/api/v1/general/currencies/select` — no auth required, `throttle:public-api`.

### Request Body (`SelectCurrencyRequest`)

```json
{ "currency_code": "KWD" }
```

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `currency_code` | string | Yes | required, string, max:3, `Rule::exists('currencies','code')->where('is_active', true)` |

### Flow
1. Code uppercased (`strtoupper`) and validated as active currency.
2. If an authenticated user is present (`auth('sanctum')->user() ?? auth()->user()`), the preference is stored via `UserCurrencyPreferenceService::setUserPreference` and `adoptGuestCurrencyOnLogin` adopts `X-Currency` header if needed.
3. The selected currency is also accepted via the `X-Currency` header on subsequent requests (`UserCurrencyPreferenceService::getHeaderCurrencyCode()`); the legacy `guest_currency` cookie is no longer the canonical guest transport (see `UserCurrencyPreferenceService.php` — `getHeaderCurrencyCode()` with `normalizeHeaderValue()` `/^[A-Z]{3}$/`). `CORS_ALLOWED_HEADERS` includes `X-Currency`.
4. `CurrencyService::forgetEffectiveCode()` resets the memoized effective currency.
5. Returns `200` `CURRENCY_SELECTED_SUCCESSFULLY` with the selected `CurrencyResource`.

### Response (200)
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
    "is_base": true,
    "is_catalog": false,
    "created_at": "2026-08-10T00:00:00+00:00"
  }
}
```

### Errors
| Status | Condition |
|--------|-----------|
| 422 | Missing/invalid `currency_code`, or code not among active currencies |

### Interaction with `currency_selection_enabled`
The stored preference/cookie only affects display/pricing resolution when the setting `currency_selection_enabled` is `true` (see `change-response.md` §7). When `false` (default), the selection is stored but ignored by `CurrencyService::getEffectiveCode()` — the effective currency remains the catalog code.

---

## Business Rules Summary

1. **Base vs catalog currency** live in settings `options` (`base_currency_code`, `catalog_currency_code`, `currency`), default `config('shop.default_currency')` (USD).
2. **Conversion formula** (`CurrencyConversionService`):
   - `rate = bcdiv(targetRate, sourceRate, 6)`
   - `converted = round(bcdiv(bcmul(amount, targetRate, 6), sourceRate, 6), 2)`
   - Same-code conversion is identity (no DB query, rate `1`).
3. **Rate resolution** picks the latest `effective_date <= date` (`whereDate`), raising `CurrencyRateNotFoundException` if none.
4. **Order snapshot** (`OrderCreationService::resolveCurrencySnapshot`) stores `currency_code` (= base), `base_currency_code`, `catalog_currency_code`, `currency_rate` (catalog→base ratio), `currency_rate_date`, `total_price` (converted base amount) and `converted_total_price` on every create/update.
5. **Product prices** converted via `ConvertsProductPrice` from catalog → base code.
6. **Cart responses** — `CartResource`/`CartItemResource` convert item prices, subtotals, coupon discount and totals to base currency and expose `currency` (base code).
7. **Order responses** — `OrderResource` exposes `currency`/`base_currency` (base code) plus new `catalog_currency` field; `total_price` is the base-currency amount.
8. **Payment currency sourcing** — gateways, QR payloads, payment-transaction records, invoice snapshots and the reconciliation job read the order's base currency (`base_currency_code ?? currency_code`) and fall back to `config('payment.default_currency')` for legacy orders.
9. **Catalog currency switch** (`set-catalog`) re-points `catalog_currency_code` without touching the base currency or the `currency` option.
10. **Delete protection** for base currency and currencies referenced by rates.
11. **All user-facing messages** come from `resources/lang/{en,ar}/message.php`.
12. **Effective currency** (`CurrencyService::getEffectiveCode()`) — when the setting `currency_selection_enabled` is `false` (default) it always resolves to the catalog code; when `true` it resolves to `user preference > X-Currency header > catalog code`.
13. **Settings responses** (admin `GET/PUT /api/v1/settings` and public `GET /api/v1/general/settings`) expose a top-level `currency_selection_enabled` boolean; `PUT /api/v1/settings` accepts it as `sometimes|boolean` and merges it into `options` without dropping other options.

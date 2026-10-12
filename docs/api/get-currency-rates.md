# Endpoint Name

Currency Rates – List Exchange Rates (`GET /api/v1/currency-rates`)

# Description

Returns a paginated list of exchange rates ordered by effective date with five filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/currency-rates` |
| Path composition | `api/v1` prefix + `currency-rates` from `Route::apiResource('currency-rates', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-exchange-rates` gate decides) |
| Controller action | `Marvel\Http\Controllers\CurrencyRateController@index` → `CurrencyRateService::list(currencyId, effectiveDate, dateFrom, dateTo, code, limit)` with `currency` eager-loaded, ordered `effective_date DESC, id DESC`; banner-style manual meta-flattening envelope. `limit` clamped `1`–`100` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-exchange-rates` via controller constructor (applies to `index` and `show`); the route pipe lists `view-exchange-rates\|create-exchange-rate\|update-exchange-rate\|delete-exchange-rate` but the constructor gate is decisive |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currency-rates.index` (apiResource default naming) |

## Request inputs

No validation runs — unknown `currency_id`/`code` and malformed dates yield an empty page or DB-level behavior, never 422 (code-verified; the `code=XXX` test proves empty-200).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `limit` | query | integer | Optional | No | Clamped `max(1, min((int) limit, 100))` | Effective `1`–`100` | `15` | None | `15` | Page size |
| `currency_id` | query | integer | Optional | No | Applied only when the key is present (`has`); NO `exists` check — unknown id yields empty page (code-verified) | Any integer | None (no filter) | None | `2` | Exact currency filter (verified: SAR filter → `total: 2`, every item's `currency.id` matches) |
| `effective_date` | query | date string | Optional | No | `whereDate(effective_date, value)` when truthy | Any date | None (no filter) | None | `"2026-01-01"` | Exact-day filter (verified: → `total: 1`, `data.0.effective_date: 2026-01-01`) |
| `date_from` | query | date string | Optional | No | `whereDate(effective_date, >=, value)` when truthy | Any date | None (no filter) | None | `"2026-01-15"` | Range start (verified with `date_to` → 1 row `2026-02-01`) |
| `date_to` | query | date string | Optional | No | `whereDate(effective_date, <=, value)` when truthy | Any date | None (no filter) | None | `"2026-02-15"` | Range end |
| `code` | query | string | Optional | No | `whereHas(currency: code = value)` when truthy — unknown codes yield empty-200, not 404 (verified by `list_code_filter_returns_empty_for_unknown_code`: `code=XXX` → `total: 0`) | Any code | None (no filter) | None | `"SAR"` | Currency-code filter (verified: → `total: 2`, every item's `currency.code: SAR`) |

No body fields or path parameters. No `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/currency-rates?currency_id=3&limit=15",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the banner-style custom envelope. Verified by the five filter tests (`list_can_be_filtered_by_currency/effective_date/code/date_range`, `list_code_filter_returns_empty_for_unknown_code`), all `getJson` with `success: true` + `data.total` assertions.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (same flattened shape as currencies index) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by `CurrencyRateResource` with `currency` ALWAYS loaded here (the block is always present — code-verified for index) | Item: `id`, `currency{id,code,name{},symbol{}}`, `exchange_rate` (string decimal), `source` (`manual`/…/null), `provider`, `effective_date` (`YYYY-MM-DD`), `created_at`, `updated_at` |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.total` | integers | Always present | Paginator meta | Paginator values |
| `data.path` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | strings | Always present (possibly `''`) | Paginator links | URLs or `''` |
| `data.per_page` | integer | Always present | Effective page size | `1`–`100` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "currency": {
          "id": 3,
          "code": "SAR",
          "name": { "en": "Saudi Riyal", "ar": "ريال سعودي" },
          "symbol": { "en": "$", "ar": "$" }
        },
        "exchange_rate": "3.7500000000",
        "source": "manual",
        "provider": null,
        "effective_date": "2026-01-01",
        "created_at": "2026-01-01T00:00:00+00:00",
        "updated_at": "2026-01-01T00:00:00+00:00"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/currency-rates",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/currency-rates?page=1",
    "first_page_url": "http://localhost/api/v1/currency-rates?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `unauthenticated_user_is_rejected` for `GET /currency-rates`) | See 401 example |
| 403 | Authenticated caller lacks `view-exchange-rates` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Customer (verified by `customer_without_permission_is_forbidden` for `GET /currency-rates`) | See 403 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example:

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

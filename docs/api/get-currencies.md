# Endpoint Name

Currencies – List Currencies (`GET /api/v1/currencies`)

# Description

Returns a paginated list of currencies ordered by sort order with search and filter support.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/currencies` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `currencies` from `Route::apiResource('currencies', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-currencies` gate decides) |
| Controller action | `Marvel\Http\Controllers\CurrencyController@index` → inline Eloquent query (`App\Models\Currency`, SoftDeletes scope active — trashed excluded): `limit` clamped `1`–`100`, optional `search` (LIKE over `code`, `numeric_code`, `name/symbol/country_name` en+ar), exact `code`, `is_active`, `sort_order`; ordered by `sort_order`, then `code`; banner-style manual meta-flattening envelope |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-currencies` via controller constructor (applies to `index` and `show`); the route pipe lists `view-currencies\|create-currency\|update-currency\|delete-currency` but the constructor gate is decisive |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `currencies.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | Clamped `max(1, min((int) limit, 100))` — NOT `per_page` (code-verified) | Effective `1`–`100` | `15` | None | `3` | Page size (verified by `index_limits_and_caps_pagination`: `limit=3` → `data.per_page: 3`) |
| `search` | query | string | Optional | No | LIKE `%term%` over 8 columns (code, numeric_code, name/symbol/country_name × en/ar); unescaped LIKE (a `%` in the term acts as a wildcard — code-verified edge) | Any string | None (no filter) | None | `KW` | Free-text filter (verified: `search=KW` → only `KWD`; `search=Dinar` matches the translated name) |
| `code` | query | string | Optional | No | Exact `where('code', $code)` (case-sensitive at DB level) | Any code | None (no filter) | None | `USD` | Exact code filter (verified: `code=USD` → `total: 1`) |
| `is_active` | query | boolean-ish | Optional | No | Applied only when the key is present; parsed via `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — NOTE `FILTER_VALIDATE_BOOLEAN` maps unrecognized strings (e.g. `abc`) to `false`, so `is_active=abc` filters INACTIVE rows instead of erroring (code-verified wart) | `1`/`0`/`true`/`false` | None (no filter) | None | `1` | Active filter (verified both directions: `1` → 2 active, `0` → 1 inactive) |
| `sort_order` | query | integer | Optional | No | Cast `(int)`; exact match | Any integer | None (no filter) | None | `0` | Sort-order filter (verified: `sort_order=0` → all 3 seeded rows) |

No body fields, path parameters, or file uploads. No `422` contract (no validation runs — bad keys are coerced, never rejected).

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/currencies?limit=15&code=USD",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the banner-style custom envelope (`data` is an OBJECT). Verified by `admin_can_list_all_currencies` (`data.total: 3`, codes `USD/KWD/SAR`, `getJson`) plus the filter tests above.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (same flattened shape as banners/site-reviews) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by Marvel `CurrencyResource` (full translation maps — NOT locale strings: `name`/`symbol`/`country_name` are objects with ALL locales, code-verified) | Item: `id`, `code`, `name{}`, `symbol{}`, `country_name{}`, `numeric_code`, `decimal_places` (int), `icon`, `is_active` (bool), `sort_order` (int), `is_base`/`is_catalog` (bool, computed), `effective_rate`, `rate_mode` (`auto`/`manual`/null), `manual_rate`, `provider_rate`, `provider`, `last_synced_at`, `provider_rate_at`, `effective_rate_updated_at`, `is_rate_stale`, `created_at`, `updated_at` |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.total` | integers | Always present | Paginator meta | Paginator values |
| `data.path` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | strings | Always present (possibly `''`) | Paginator links | URLs or `''` |
| `data.per_page` | integer | Always present | Effective (possibly clamped) page size | `1`–`100` |

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
        "name": { "en": "US Dollar", "ar": "دولار أمريكي" },
        "symbol": { "en": "$", "ar": "$" },
        "country_name": { "en": "United States", "ar": "الولايات المتحدة" },
        "numeric_code": "840",
        "decimal_places": 2,
        "icon": "usd",
        "is_active": true,
        "sort_order": 0,
        "is_base": true,
        "is_catalog": true,
        "effective_rate": "1.0000000000",
        "rate_mode": "manual",
        "manual_rate": "1.0000000000",
        "provider_rate": null,
        "provider": null,
        "last_synced_at": null,
        "provider_rate_at": null,
        "effective_rate_updated_at": "2026-01-01T00:00:00+00:00",
        "is_rate_stale": false,
        "created_at": "2026-01-01T00:00:00+00:00",
        "updated_at": "2026-01-01T00:00:00+00:00"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/currencies",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/currencies?page=1",
    "first_page_url": "http://localhost/api/v1/currencies?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (verified by `unauthenticated_user_is_rejected` and `unauthenticated_requests_are_rejected_with_401`, `getJson`) | See 401 example |
| 403 | Authenticated caller lacks `view-currencies` (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Customer with no currency permissions (verified by `customer_without_permission_is_forbidden` and `customer_without_currency_permissions_is_forbidden`) | See 403 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

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

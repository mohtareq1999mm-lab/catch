# Endpoint Name

Currencies – Set Catalog Currency (`POST /api/v1/currencies/{id}/set-catalog`)

# Description

Promotes one active, rated currency to catalog currency without touching the base currency.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (only `POST` is registered — `PUT`/`PATCH` on this path → 405) |
| Full path | `/api/v1/currencies/{id}/set-catalog` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route → 404 fixed shape) |
| Path composition | `api/v1` prefix + `currencies/{id}/set-catalog` custom `Route::post` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:set-catalog-currency` (two segments after `currencies`, so no collision with `currencies/{currency}`) |
| Controller action | `Marvel\Http\Controllers\CurrencyController@setCatalog(int $id)` → `withTrashed` lookup; miss → `CURRENCY_NOT_FOUND` 404; then `CurrencyService::setCatalogCurrency` (updates ONLY the `catalog_currency_code` option — the base code is untouched, verified by `set_catalog_updates_only_the_catalog_currency_option`; flushes frontend price caches, verified by `set_catalog_flushes_frontend_price_caches`); guards: inactive → 422 `CURRENCY_INACTIVE`, no rate → 422 `EXCHANGE_RATE_NOT_FOUND`. NOTE (code-verified asymmetry vs set-base): there is NO financial-orders guard here — catalog switches remain allowed with history |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `set-catalog-currency` via route middleware AND constructor `only: [setCatalog]` (double gate, same permission — code-verified; `view-currencies` alone fails 403) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Id of an active, rated currency (trashed included in lookup) | None | None | `2` | Identifies the new catalog currency |

No query parameters, body fields, or validation-driven `422` (the two 422s below are service-guard outcomes).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/currencies/2/set-catalog",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: catalog currency switched — HTTP 200

Returned via `ApiResponse::apiResponse(SET_CATALOG_CURRENCY_SUCCESSFULLY, 200, true, CurrencyResource::make($currency))`. Verified by `admin_can_set_a_new_catalog_currency` (`assertStatus(200)`, `data.code: KWD`, `postJson`) and `catalog_code_is_reflected_by_currency_service` (service `getCatalogCode()` → `KWD` while base stays `USD`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SET_CATALOG_CURRENCY_SUCCESSFULLY` | `en` → `"Catalog currency updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Full `CurrencyResource` of the new catalog currency | 22-key shape; `is_catalog: true`, `is_base` unchanged |

```json
{
  "status": 200,
  "message": "Catalog currency updated successfully",
  "success": true,
  "data": {
    "id": 2,
    "code": "KWD",
    "name": { "en": "Kuwaiti Dinar", "ar": "دينار كويتي" },
    "symbol": { "en": "د.ك", "ar": "د.ك" },
    "country_name": { "en": "Kuwait", "ar": "الكويت" },
    "numeric_code": "414",
    "decimal_places": 3,
    "icon": "kwd",
    "is_active": true,
    "sort_order": 0,
    "is_base": false,
    "is_catalog": true,
    "effective_rate": "0.2210000000",
    "rate_mode": "manual",
    "manual_rate": "0.2210000000",
    "provider_rate": null,
    "provider": null,
    "last_synced_at": null,
    "provider_rate_at": null,
    "effective_rate_updated_at": "2026-01-01T00:00:00+00:00",
    "is_rate_stale": false,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as the currency 401 tests) | See 401 example |
| 403 | Authenticated caller lacks `set-catalog-currency` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only admin (verified by `set_catalog_requires_permission` — 403; the view-only mutation test also asserts set-catalog → 403) | See 403 example |
| 404 | No currency row with `{id}` | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_NOT_FOUND` | None | Unknown numeric id (verified by `set_catalog_for_missing_currency_returns_404` for `999999`) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` | None | `/currencies/abc/set-catalog` (same `whereNumber` mechanics) | See 404-constraint example |
| 405 | `PUT`/`PATCH` on `/api/v1/currencies/{id}/set-catalog` (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Currency is inactive (`CurrencyInactiveException`) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_INACTIVE` | None | `is_active: false` row (verified by `inactive_currency_cannot_be_set_as_catalog`) | See 422-inactive example |
| 422 | Currency has no exchange rate (`CurrencyRateNotFoundException`) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.EXCHANGE_RATE_NOT_FOUND` | None | Fresh currency with no rates (verified by `currency_without_a_rate_cannot_be_set_as_catalog`) | See 422-rate example |
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

404 example:

```json
{
  "status": 404,
  "message": "Currency not found",
  "success": false
}
```

404-constraint example:

```json
{
  "message": "Not Found",
  "status": false
}
```

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422-inactive example:

```json
{
  "status": 422,
  "message": "Inactive currencies cannot be set as the base currency",
  "success": false
}
```

422-rate example:

```json
{
  "status": 422,
  "message": "No exchange rate found for this currency on or before the selected date",
  "success": false
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

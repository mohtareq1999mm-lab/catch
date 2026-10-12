# Endpoint Name

Currencies – Set Base Currency (`POST /api/v1/currencies/{id}/set-base`)

# Description

Promotes one active, rated currency to base currency unless financial orders freeze the base.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (only `POST` is registered — `PUT`/`PATCH` on this path → 405) |
| Full path | `/api/v1/currencies/{id}/set-base` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route → 404 fixed shape) |
| Path composition | `api/v1` prefix + `currencies/{id}/set-base` custom `Route::post` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:set-base-currency` (two segments after `currencies`, so no collision with `currencies/{currency}` show/update/destroy) |
| Controller action | `Marvel\Http\Controllers\CurrencyController@setBase(int $id)` → `withTrashed` lookup; miss → `CURRENCY_NOT_FOUND` 404; then `CurrencyService::setBaseCurrency` (syncs BOTH `base_currency_code` and legacy `currency` options, flushes PRODUCTS + CURRENCIES frontend price caches — verified by `set_base_syncs_options_currency_field` and `set_base_flushes_frontend_price_caches`); guards: inactive → 422 `CURRENCY_INACTIVE`, no rate → 422 `EXCHANGE_RATE_NOT_FOUND`, financial orders exist → 409 `CANNOT_CHANGE_BASE_CURRENCY_FINANCIAL_ORDERS_EXIST` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `set-base-currency` via route middleware AND constructor `only: [setBase]` (double gate, same permission — code-verified; `view-currencies` alone fails 403, verified by `set_base_requires_permission`) |
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
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Id of an active, rated currency (trashed included in lookup) | None | None | `2` | Identifies the new base currency |

No query parameters, body fields, or validation-driven `422` (the two 422s below are service-guard outcomes, not `FormRequest` failures).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/currencies/2/set-base",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: base currency switched — HTTP 200

Returned via `ApiResponse::apiResponse(SET_BASE_CURRENCY_SUCCESSFULLY, 200, true, CurrencyResource::make($currency))`. Verified by `admin_can_set_a_new_base_currency` (`assertStatus(200)`, exact message assertion, `data.code: KWD`, `data.is_base: true`, `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SET_BASE_CURRENCY_SUCCESSFULLY` | `en` → `"Base currency updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Full `CurrencyResource` of the new base (note: the resource is built from the pre-switch `$currency` model instance — `is_base` reflects the service mutation only if the service mutates in place; the test asserts `data.is_base: true`, so the flag is set on the returned instance) | 22-key shape; `is_base: true` |

```json
{
  "status": 200,
  "message": "Base currency updated successfully",
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
    "is_base": true,
    "is_catalog": false,
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
| 403 | Authenticated caller lacks `set-base-currency` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only admin (verified by `set_base_requires_permission` — 403) | See 403 example |
| 404 | No currency row with `{id}` | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_NOT_FOUND` | None | Unknown numeric id (verified by `set_base_for_missing_currency_returns_404` for `999999`) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` | None | `/currencies/abc/set-base` (same `whereNumber` mechanics as the apiResource 404-constraint test) | See 404-constraint example |
| 405 | `PUT`/`PATCH` on `/api/v1/currencies/{id}/set-base` (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 409 | Financial orders already exist — the base becomes IMMUTABLE after the first completed payment (`CurrencyInUseException::hasFinancialOrders`, `REASON_HAS_FINANCIAL_ORDERS`) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CANNOT_CHANGE_BASE_CURRENCY_FINANCIAL_ORDERS_EXIST`: `en` → `"Cannot change base currency when financial orders exist. The base currency becomes immutable after the first completed payment."` | None | Switching base with financial history (code-traced; no set-base test forces this path — the message text is verified in `en/message.php` and the exception factory) | See 409 example |
| 422 | Currency is inactive (`CurrencyInactiveException`) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_INACTIVE`: `en` → `"Inactive currencies cannot be set as the base currency"` | None | `is_active: false` row (verified by `inactive_currency_cannot_be_set_as_base` — base stays `USD`) | See 422-inactive example |
| 422 | Currency has no exchange rate (`CurrencyRateNotFoundException`) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.EXCHANGE_RATE_NOT_FOUND`: `en` → `"No exchange rate found for this currency on or before the selected date"` | None | Fresh currency with no rates (verified by `currency_without_a_rate_cannot_be_set_as_base` — base stays `USD`) | See 422-rate example |
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

409 example:

```json
{
  "status": 409,
  "message": "Cannot change base currency when financial orders exist. The base currency becomes immutable after the first completed payment.",
  "success": false
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

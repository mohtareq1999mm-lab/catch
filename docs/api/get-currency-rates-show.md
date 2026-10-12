# Endpoint Name

Currency Rates – Show Exchange Rate (`GET /api/v1/currency-rates/{currency_rate}`)

# Description

Returns one exchange rate by numeric id with its currency block.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/currency-rates/{currency_rate}` (SNAKE-CASE placeholder — code-verified, unlike `{id}` on the currency custom routes) |
| Path composition | `api/v1` prefix + `currency-rates/{currency_rate}` from `Route::apiResource('currency-rates', ...)->whereNumber('currency_rate')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-exchange-rates` gate decides; non-numeric segments match NO route → 404 fixed shape) |
| Controller action | `Marvel\Http\Controllers\CurrencyRateController@show(int $id)` → `CurrencyRate::query()->with('currency')->find($id)` (NO `withTrashed` on currencies here — but rates themselves are hard-deleted, so no trashed-rate question arises; code-verified); miss → `apiResponse(CURRENCY_RATE_NOT_FOUND, 404, false)` (translated `ERROR.CURRENCY_RATE_NOT_FOUND`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-exchange-rates` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currency-rates.show` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `currency_rate` | path | integer | Required | No | `whereNumber('currency_rate')` + `int $id` | Existing rate id | None | None | `1` | Identifies the rate |

No query parameters, body fields, or `422` contract. No show-specific test found (the `missing_rate_returns_404` test covers show+update+destroy misses together for `999999` — cited in Errors).

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/currency-rates/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: rate returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CurrencyRateResource::make($rate))` with `currency` loaded (block always present here).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CurrencyRateResource` | `id`, `currency{id,code,name{},symbol{}}`, `exchange_rate` (string), `source`, `provider`, `effective_date`, `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "currency": {
      "id": 1,
      "code": "USD",
      "name": { "en": "US Dollar", "ar": "دولار أمريكي" },
      "symbol": { "en": "$", "ar": "$" }
    },
    "exchange_rate": "1.0000000000",
    "source": "manual",
    "provider": null,
    "effective_date": "2026-01-01",
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the two different 404 shapes.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as the rate 401 tests) | See 401 example |
| 403 | Authenticated caller lacks `view-exchange-rates` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Customer (same constructor gate as index, test-verified on the index sibling) | See 403 example |
| 404 | No rate with id | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | Translated `ERROR.CURRENCY_RATE_NOT_FOUND`: `en` → `"Exchange rate not found"` | None | Unknown numeric id (verified by `missing_rate_returns_404` for `999999`, `getJson`) | See 404 example |
| 404 | Non-numeric id matches NO route | `{message: string, status: boolean}` (Handler shape) | None | `"Not Found"` (NOT the translated rate string) | None | `/currency-rates/abc` (same `whereNumber` mechanics as currencies) | See 404-constraint example |
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

404 example (unknown numeric id):

```json
{
  "status": 404,
  "message": "Exchange rate not found",
  "success": false
}
```

404-constraint example (non-numeric id):

```json
{
  "message": "Not Found",
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

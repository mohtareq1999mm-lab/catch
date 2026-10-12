# Endpoint Name

Currencies – Show Currency (`GET /api/v1/currencies/{currency}`)

# Description

Returns one currency by numeric id, including soft-deleted rows, with the full resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/currencies/{currency}` |
| Path composition | `api/v1` prefix + `currencies/{currency}` from `Route::apiResource('currencies', ...)->whereNumber('currency')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-currencies` gate decides; the `whereNumber` constraint means non-numeric segments match NO route → 404 `NotFoundHttpException` shape, verified by `non_numeric_id_returns_404_not_500`) |
| Controller action | `Marvel\Http\Controllers\CurrencyController@show(int $id)` → `Currency::query()->withTrashed()->find($id)` — TRASHED rows are still returned 200 (code-verified `withTrashed` on show/update/destroy alike); miss → `apiResponse(CURRENCY_NOT_FOUND, 404, false)` (translated `ERROR.CURRENCY_NOT_FOUND`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-currencies` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currencies.show` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `currency` | path | integer | Required | No | `whereNumber('currency')` at route level + `int $id` signature; non-numeric → no route match (404 fixed shape, NOT the translated miss message) | Id of any currency row (trashed included) | None | None | `1` | Identifies the currency |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/currencies/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: currency returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CurrencyResource::make($currency))`. Verified by `admin_can_view_single_currency` (`data.id`, `data.code: USD`, `data.is_base: true`, `data.is_catalog: true`, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Full `CurrencyResource` (22 keys — same shape as list/create) | See list doc for the full key table |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
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
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the two different 404 shapes.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as the list 401 tests) | See 401 example |
| 403 | Authenticated caller lacks `view-currencies` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Customer without permissions (same constructor gate as index, test-verified on the index sibling) | See 403 example |
| 404 | No currency row with `{currency}` (trashed rows still HIT — only truly absent ids miss) | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | Translated `ERROR.CURRENCY_NOT_FOUND`: `en` → `"Currency not found"` | None | Unknown numeric id (verified by `missing_currency_returns_404` for `999999`) | See 404 example |
| 404 | Non-numeric `{currency}` (e.g. `/currencies/abc`) matches NO route due to `whereNumber` | `{message: string, status: boolean}` (Handler shape) | None | `"Not Found"` (NOT the translated currency string) | None | Non-numeric segment (verified by `non_numeric_id_returns_404_not_500` — and NOT 500, hence the test name) | See 404-constraint example |
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
  "message": "Currency not found",
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

# Endpoint Name

Currency Rates – Delete Exchange Rate (`DELETE /api/v1/currency-rates/{currency_rate}`)

# Description

Hard-deletes one exchange rate by numeric id unless it is the current effective rate.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/currency-rates/{currency_rate}` |
| Path composition | `api/v1` prefix + `currency-rates/{currency_rate}` from `Route::apiResource('currency-rates', ...)->whereNumber('currency_rate')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-exchange-rate` gate decides) |
| Controller action | `Marvel\Http\Controllers\CurrencyRateController@destroy(int $id)` → plain `find($id)`; miss → `CURRENCY_RATE_NOT_FOUND` 404; then `CurrencyRateService::delete` in a row-locked transaction: resolves the CURRENT effective rate (latest `effective_date <= today`, tie-break `id DESC`) and throws `CurrencyInUseException::isOnlyEffectiveRate()` when the target IS it → 409 `CANNOT_DELETE_CURRENCY_IN_USE`; else HARD-deletes (verified `assertDatabaseMissing`) + invalidates price caches |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-exchange-rate` via controller constructor (applies only to `destroy` — `update-exchange-rate` alone fails 403, verified by `admin_with_update_exchange_rate_permission_cannot_delete_rates`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currency-rates.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `currency_rate` | path | integer | Required | No | `whereNumber` + `int $id` | Existing rate id | None | None | `1` | Identifies the rate |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/currency-rates/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: rate hard-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(CURRENCY_RATE_DELETED_SUCCESSFULLY, 200, true)` with NO data key. Verified by `admin_can_delete_exchange_rate` (`assertStatus(200)`, exact message assertion, `assertDatabaseMissing`) and `admin_with_delete_exchange_rate_permission_can_delete_rates` (delete-holder, `deleteJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CURRENCY_RATE_DELETED_SUCCESSFULLY` | `en` → `"Exchange rate deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Exchange rate deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the 409 message reuses the currency key (the text says "currency", even though a rate was refused — code-verified wording wart).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as the rate 401 tests) | See 401 example |
| 403 | Authenticated caller lacks `delete-exchange-rate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Update-only holder (verified: DELETE → 403 while PUT → 200); view-only holder (verified: DELETE → 403) | See 403 example |
| 404 | No rate with id | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_RATE_NOT_FOUND` | None | Unknown numeric id (verified by `missing_rate_returns_404` for `999999`, `deleteJson`) | See 404 example |
| 404 | Non-numeric id matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` | None | `/currency-rates/abc` (same `whereNumber` mechanics) | See 404-constraint example |
| 409 | Rate IS the currency's current effective rate (the only row the engine would fall back to — deleting it would leave pricing unresolvable) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CANNOT_DELETE_CURRENCY_IN_USE`: `en` → `"This currency cannot be deleted because it is in use"` (currency-worded message for a rate refusal — code-verified wart) | None | Deleting the sole/current effective rate (verified by `admin_cannot_delete_the_current_effective_rate` — row survives) | See 409 example |
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
  "message": "Exchange rate not found",
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

409 example:

```json
{
  "status": 409,
  "message": "This currency cannot be deleted because it is in use",
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

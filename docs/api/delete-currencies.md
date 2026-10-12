# Endpoint Name

Currencies – Delete Currency (`DELETE /api/v1/currencies/{currency}`)

# Description

Soft-deletes one currency by numeric id unless it is the base currency or has rates.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/currencies/{currency}` |
| Path composition | `api/v1` prefix + `currencies/{currency}` from `Route::apiResource('currencies', ...)->whereNumber('currency')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-currency` gate decides; non-numeric segments match NO route → 404 fixed shape) |
| Controller action | `Marvel\Http\Controllers\CurrencyController@destroy(int $id)` → `withTrashed` lookup (deleting an already-trashed row re-runs the service path — behavior **Unverified in source**); miss → `CURRENCY_NOT_FOUND` 404; then `CurrencyService::deleteCurrency` which throws `CurrencyInUseException`: `REASON_BASE_CURRENCY` → 409 `CANNOT_DELETE_BASE_CURRENCY`, any other reason (rates reference) → 409 `CANNOT_DELETE_CURRENCY_IN_USE`; else soft-deletes (verified `assertSoftDeleted`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-currency` via controller constructor (applies only to `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currencies.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `currency` | path | integer | Required | No | `whereNumber` + `int $id` | Existing currency id (trashed included) | None | None | `4` | Identifies the currency |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/currencies/4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: currency soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(CURRENCY_DELETED_SUCCESSFULLY, 200, true)` with NO data key. Verified by `admin_can_delete_currency_without_rates` (`assertStatus(200)`, exact message assertion, `assertSoftDeleted`) using `deleteJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CURRENCY_DELETED_SUCCESSFULLY` | `en` → `"Currency deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Currency deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as list 401 tests) | See 401 example |
| 403 | Authenticated caller lacks `delete-currency` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only admin (verified by `admin_with_only_view_permission_cannot_mutate_currencies` — DELETE → 403) | See 403 example |
| 404 | No currency row with id | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_NOT_FOUND` | None | Unknown numeric id (verified by `missing_currency_returns_404` for `999999`) | See 404 example |
| 404 | Non-numeric id matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` | None | `/currencies/abc` (verified by `non_numeric_id_returns_404_not_500`) | See 404-constraint example |
| 409 | Currency is the base currency (`REASON_BASE_CURRENCY`) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CANNOT_DELETE_BASE_CURRENCY`: `en` → `"The base currency cannot be deleted"` | None | Deleting the base currency (verified by `cannot_delete_the_base_currency`) | See 409-base example |
| 409 | Currency has exchange rates (`REASON_REFERENCED_BY_RATES`) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CANNOT_DELETE_CURRENCY_IN_USE`: `en` → `"This currency cannot be deleted because it is in use"` | None | Deleting KWD which has rates (verified by `cannot_delete_currency_that_has_exchange_rates`) | See 409-in-use example |
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

409-base example:

```json
{
  "status": 409,
  "message": "The base currency cannot be deleted",
  "success": false
}
```

409-in-use example:

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

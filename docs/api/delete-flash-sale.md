# Endpoint Name

Flash Sale – Delete Flash Sale (`DELETE /api/v1/flash-sale/{flash_sale}`)

# Description

Deletes one flash sale by id and returns 200 with no data payload.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/flash-sale/{flash_sale}` |
| Path composition | `api/v1` prefix + `flash-sale/{flash_sale}` from `Route::apiResource('flash-sale', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-flash-sale` gate decides) |
| Controller action | `Marvel\Http\Controllers\FlashSaleController@destroy($id, Request $request)` → merges path `id` → `deleteFlashSale` → `findOrFail` + `delete()` (`FlashSale` uses `SoftDeletes` — soft delete, code-verified); the miss (`ModelNotFoundException`) is NOT a `MarvelException`, so it ESCAPES both `MarvelException`-only catches into `Handler` → 404 FIXED string (code-verified — the in-controller translated 404 only fires for `MarvelException`-sourced failures). NOTE: attached products keep their `has_flash_sale` flags and `price_after_flash_sale` values (no flag cleanup on delete, unlike update — code-verified asymmetry) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-flash-sale` via controller constructor (applies only to `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `flash-sale.destroy` (singular apiResource naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `flash_sale` | path | mixed id | Required | No | No route constraint; passed to `findOrFail` (soft-deleted ids miss — no restore path here) | Id of a non-deleted flash sale | None | None | `1` | Identifies the flash sale |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/flash-sale/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: flash sale deleted — HTTP 200

Returned via `ApiResponse::apiResponse(DELETE_FLASH_SALE_SUCCESSFULLY, 200, true)` with NO data key (plus `flushTag(FLASH_SALES)` cache invalidation).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETE_FLASH_SALE_SUCCESSFULLY` | `en` → `"Flash sale deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Flash sale deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `delete-flash-sale` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `delete-flash-sale` (code-traced) | See 403 example |
| 404 | No flash sale with id (miss escapes as `ModelNotFoundException` → fixed string, NOT the translated in-controller message) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown id | See 404 example |
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
  "message": "Resource Not Found",
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

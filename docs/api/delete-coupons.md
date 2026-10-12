# Endpoint Name

Coupons – Delete Coupon (`DELETE /api/v1/coupons/{coupon}`)

# Description

Hard-deletes one coupon by id with no guard checks and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/coupons/{coupon}` |
| Path composition | `api/v1` prefix + `coupons/{coupon}` from bare `Route::apiResource('coupons', ...)` (no route middleware; the in-controller `delete-coupon` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CouponController@destroy($id)` → `CouponRepository::findOrFail($id)->delete()` (`Coupon` has NO `SoftDeletes` trait — HARD delete, code-verified, unlike FAQ/flash-sale soft deletes; no usage/history/guard check in code — deleting a coupon with redemptions, assignments, or runs is allowed at this layer, code-verified); the miss (`ModelNotFoundException`) is NOT a `MarvelException`, so it ESCAPES the `catch (MarvelException)` (whose `throw new MarvelException(NOT_FOUND)` rethrow is dead for real misses) into `Handler` → 404 FIXED string (code-verified — compare show's translated 404) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-coupon` via controller constructor (applies only to `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `coupons.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `coupon` | path | mixed id | Required | No | No route constraint; passed to `findOrFail` (code lookup NOT supported here, unlike show) | Existing coupon id | None | None | `1` | Identifies the coupon |

No query parameters, body fields, or `422` contract. Media rows and pivot/assignment/targeting/run rows cleanup is database-level (**Unverified in source** — no explicit cleanup in code). No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/coupons/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: coupon hard-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(DELETED_COUPON_SUCCESSFULLY, 200, true)` with NO data key (plus `forget(COUPONS)` cache invalidation).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETED_COUPON_SUCCESSFULLY` | `en` → `"Coupon deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Coupon deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation and no guards, so no `422`/`409`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `delete-coupon` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `delete-coupon` (code-traced) | See 403 example |
| 404 | No coupon with id (miss escapes as `ModelNotFoundException` → fixed string, NOT the translated rethrow) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown id | See 404 example |
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

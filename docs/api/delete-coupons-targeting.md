# Endpoint Name

Coupons – Delete Targeting (`DELETE /api/v1/coupons/{id}/targeting`)

# Description

Removes one coupon's targeting row, restoring always-eligible behavior, and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/coupons/{id}/targeting` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `coupons/{id}/targeting` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.coupons.targeting.destroy`, route middleware `permission:update-coupon|create-coupon` — working-tree addition that runs BEFORE the inline check) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\CouponTargetingController@destroy(Request $request, int $id)` → `authorizeWrite` (update-coupon OR create-coupon — same BLOCKER-fix split as upsert); `findOrFail` miss escapes → 404 fixed; missing targeting row → 404 translated `COUPON_NO_TARGETING`; else deletes + `CouponTargetingChanged` event + discovery-cache invalidation (in-flight targeted runs stop harmlessly; the coupon flips back to public visibility — code-verified comments) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | WRITE split: `update-coupon` OR `create-coupon` via route middleware FIRST, then inline `authorizeWrite` (same BLOCKER-fix split; the inline verbatim abort is now unreachable for permissionless callers since the sets match; view-only fails — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.coupons.targeting.destroy` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Id of a coupon WITH a targeting row | None | None | `1` | Identifies the coupon |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/coupons/1/targeting",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: targeting removed — HTTP 200

Returned via `ApiResponse::apiResponse(DELETED_COUPON_SUCCESSFULLY, 200, true)` with NO data key (generic delete message, NOT a targeting-specific key — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETED_COUPON_SUCCESSFULLY` | `en` → `"Coupon deleted successfully"` (coupon-worded message for a targeting removal — code-verified wording wart) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Coupon deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks BOTH `update-coupon` and `create-coupon` (route pipe rejects first) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | View-only or permissionless token (code-traced) | See 403 example |
| 404 | No coupon with `{id}` (`findOrFail` escapes) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Coupon exists but has NO targeting row (nothing to remove — backward-compat state is already "always eligible") | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.COUPON_NO_TARGETING`: `en` → `"This coupon has no targeting configuration."` | None | Untargeted coupon (code-traced) | See 404-targeting example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (note the period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (verbatim):

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

404-targeting example:

```json
{
  "status": 404,
  "message": "This coupon has no targeting configuration.",
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

# Endpoint Name

Coupon Assignments – Delete Assignment (`DELETE /api/v1/coupons/{coupon}/assignments/{assignment}`)

# Description

Removes one unused coupon assignment scoped to its coupon and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/coupons/{coupon}/assignments/{assignment}` |
| Path composition | `api/v1` prefix + nested path from the `Route::prefix('coupons/{coupon}')` group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:delete-coupon-assignment` (route-inventory confirms the exact middleware set) |
| Controller action | `Marvel\Http\Controllers\CouponAssignmentController@destroy(Request $request, $couponId, $assignmentId)` → `CouponAssignmentRepository::removeAssignment` in a row-locked transaction: scoped `lockForUpdate` lookup (miss → `firstOrFail` → `ModelNotFoundException` → 404 translated); `used > 0` → `MarvelBadRequestException('CANNOT_DELETE_ASSIGNMENT_WITH_USAGE')` → 409 with the RAW key (same translation analysis); else deletes + invalidates discovery caches. NOTE (code-verified, mirrored in suggest-fix guidance): removal is deliberately NOT a limit change — it affects eligibility, claim state, usage history, and prior notifications |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-coupon-assignment` via route middleware AND constructor `only: [destroy]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (unnamed prefix-group route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `coupon` | path | integer | Required | No | Cast `(int)`; scoping half of the locked lookup | Owning coupon id | None | None | `1` | Scopes the lookup |
| `assignment` | path | integer | Required | No | Cast `(int)`; compound locked lookup | Unused assignment id under `{coupon}` | None | None | `1` | Identifies the assignment |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/coupons/1/assignments/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: assignment removed — HTTP 200

Returned via `ApiResponse::apiResponse(COUPON_ASSIGNMENT_DELETED_SUCCESSFULLY, 200, true)` with NO data key.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.COUPON_ASSIGNMENT_DELETED_SUCCESSFULLY` | `en` → `"Assignment removed successfully."` (note: "removed", not "deleted") |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Assignment removed successfully.",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `delete-coupon-assignment` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No assignment for the (`coupon`, `assignment`) pair | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.NOT_FOUND` | None | Unmatched pair (code-traced) | See 404 example |
| 409 | Assignment has usage history (`used > 0` — lowering the quota is the supported alternative, never deletion) | `{status: integer, message: string, success: boolean}` | None | `"CANNOT_DELETE_ASSIGNMENT_WITH_USAGE"` (RAW untranslated key — same translation analysis; human text would be `"Cannot delete assignment with usage history."`) | None | Deleting a used assignment (code-traced) | See 409 example |
| 400 | Any other post-auth failure | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG` | None | Post-auth failure (code-traced) | See 400 example |
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
  "message": "Not found",
  "success": false
}
```

409 example (RAW key on the wire):

```json
{
  "status": 409,
  "message": "CANNOT_DELETE_ASSIGNMENT_WITH_USAGE",
  "success": false
}
```

400 example:

```json
{
  "status": 400,
  "message": "Something went wrong",
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

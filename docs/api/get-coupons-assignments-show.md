# Endpoint Name

Coupon Assignments – Show Assignment (`GET /api/v1/coupons/{coupon}/assignments/{assignment}`)

# Description

Returns one coupon assignment scoped to its coupon with the user block.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/coupons/{coupon}/assignments/{assignment}` |
| Path composition | `api/v1` prefix + `coupons/{coupon}/assignments/{assignment}` from the `Route::prefix('coupons/{coupon}')` group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:view-coupon-assignments` |
| Controller action | `Marvel\Http\Controllers\CouponAssignmentController@show(Request $request, $couponId, $assignmentId)` → `CouponAssignmentRepository::findAssignment` (`where coupon_id AND id`, with `user`; miss → explicit `ModelNotFoundException('Assignment not found for this coupon.')` → 404 translated); a VALID assignment id under the WRONG coupon misses identically (cross-coupon reads are impossible by construction — code-verified scoping) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-coupon-assignments` via route middleware AND constructor `only: [index, show]` (double gate, same permission) |
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
| `coupon` | path | integer | Required | No | Cast `(int)`; part of the compound lookup (no separate coupon-existence check) | Id of the owning coupon | None | None | `1` | Scopes the lookup |
| `assignment` | path | integer | Required | No | Cast `(int)`; compound `where(coupon_id, id)` lookup | Id of an assignment belonging to `{coupon}` | None | None | `1` | Identifies the assignment |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/coupons/1/assignments/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: assignment returned — HTTP 200

Returned via `ApiResponse::apiResponse(COUPON_ASSIGNMENTS_FETCHED_SUCCESSFULLY, 200, true, CouponAssignmentResource::make($assignment))` (same fetched-message as the index — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.COUPON_ASSIGNMENTS_FETCHED_SUCCESSFULLY` | `en` → `"Assignments fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CouponAssignmentResource` with `user` loaded | `id`, `coupon_id`, `user_id`, `user{id, name, email}`, `max_uses`, `used`, `remaining`, `is_expired`, `assigned_at`, `expires_at` |

```json
{
  "status": 200,
  "message": "Assignments fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 1,
    "user_id": 7,
    "user": { "id": 7, "name": "Jane", "email": "jane@example.com" },
    "max_uses": 3,
    "used": 1,
    "remaining": 2,
    "is_expired": false,
    "assigned_at": "2026-01-01T00:00:00+00:00",
    "expires_at": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-coupon-assignments` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No assignment for the (`coupon`, `assignment`) pair — unknown id, unknown coupon, or right id under the wrong coupon (all identical) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"` | None | Unmatched pair (code-traced) | See 404 example |
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

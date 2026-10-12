# Endpoint Name

Coupon Assignments – Update Assignment (`PUT /api/v1/coupons/{coupon}/assignments/{assignment}`)

# Description

Updates the quota or expiry of one coupon assignment without touching usage and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified, unlike coupon CRUD which registers both) |
| Full path | `/api/v1/coupons/{coupon}/assignments/{assignment}` |
| Path composition | `api/v1` prefix + nested path from the `Route::prefix('coupons/{coupon}')` group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:update-coupon-assignment` |
| Controller action | `Marvel\Http\Controllers\CouponAssignmentController@update(UpdateCouponAssignmentRequest $request, $couponId, $assignmentId)` → `CouponAssignmentRepository::updateAssignment`: scoped lookup (miss → 404 translated); `max_uses` below recorded `used` → `MarvelBadRequestException('MAX_USES_BELOW_USED_COUNT')` → 422 with the RAW key (same translation analysis as the 409 twin); only `max_uses`/`expires_at` persist (`user_id`/`coupon_id` immutable — silently ignored, code-verified); an EMPTY validated body is a no-op success (`!empty($allowed)` guard — still 200, code-verified); `used` never writable here (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-coupon-assignment` via route middleware AND constructor `only: [update]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (unnamed prefix-group route) |

Middleware runs before validation: 401/403 precede 404/409/422. Validation precedes the scoped lookup, so 422 precedes 404 when both apply.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `coupon` | path | integer | Required | No | Cast `(int)`; scoping half of the compound lookup | Owning coupon id | None | None | `1` | Scopes the lookup |
| `assignment` | path | integer | Required | No | Cast `(int)`; compound lookup | Assignment id under `{coupon}` | None | None | `1` | Identifies the assignment |
| `max_uses` | body | integer | Optional | No | `sometimes\|integer\|min:1`, PLUS the repository floor: must be ≥ recorded `used` (else 422 RAW — code-verified second layer beyond validation) | Integer ≥ max(1, used) | Unchanged | None | `5` | New per-customer quota (raising never revokes past uses) |
| `expires_at` | body | date string | Optional | Yes | `nullable\|date\|after:now` (must be FUTURE — backdating 422s; explicit `null` clears the expiry, code-verified via `array_key_exists`) | Future date or null | Unchanged | None | `"2026-12-31"` | New expiry (null = no expiry) |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/coupons/1/assignments/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "max_uses": 5
  }
}
```

# Responses

## Success: assignment updated — HTTP 200

Returned via `ApiResponse::apiResponse(COUPON_ASSIGNMENT_UPDATED_SUCCESSFULLY, 200, true, CouponAssignmentResource::make($assignment))` (refreshed + `user` loaded).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including the empty-body no-op) |
| `message` | string | Always present | Translated `MESSAGE.COUPON_ASSIGNMENT_UPDATED_SUCCESSFULLY` | `en` → `"Assignment updated successfully."` (note trailing period) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `CouponAssignmentResource` | Same key set as show |

```json
{
  "status": 200,
  "message": "Assignment updated successfully.",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 1,
    "user_id": 7,
    "user": { "id": 7, "name": "Jane", "email": "jane@example.com" },
    "max_uses": 5,
    "used": 1,
    "remaining": 4,
    "is_expired": false,
    "assigned_at": "2026-01-01T00:00:00+00:00",
    "expires_at": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. NOTE the method split: quota-floor violations are 422 here (vs 409 for duplicates/deletes) — code-verified per-action mapping.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `update-coupon-assignment` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No assignment for the (`coupon`, `assignment`) pair (post-validation lookup miss) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.NOT_FOUND` | None | Unmatched pair with valid body (code-traced) | See 404 example |
| 405 | `PATCH` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 400 | Any other post-validation failure | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG` | None | Post-validation failure (code-traced) | See 400 example |
| 422 | `UpdateCouponAssignmentRequest` fails (`max_uses: 0`, past `expires_at`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | `max_uses` below recorded `used` (repository floor — passes validation, fails business rule) | `{status: integer, message: string, success: boolean}` (NO `errors` map — business outcome, not validation) | None | `"MAX_USES_BELOW_USED_COUNT"` (RAW untranslated key — same translation analysis as the 409 twin; human text would be `"max_uses cannot be less than the current usage count."`) | None | Lowering the quota under history (code-traced) | See 422-floor example |
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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
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

422-validation example:

```json
{
  "message": "The max uses field must be at least 1.",
  "status": false,
  "errors": {
    "max_uses": ["The max uses field must be at least 1."]
  }
}
```

422-floor example (RAW key on the wire):

```json
{
  "status": 422,
  "message": "MAX_USES_BELOW_USED_COUNT",
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

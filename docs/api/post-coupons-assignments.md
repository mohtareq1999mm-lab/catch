# Endpoint Name

Coupon Assignments – Create Assignment (`POST /api/v1/coupons/{coupon}/assignments`)

# Description

Assigns one coupon to one customer with a usage quota and returns 201 with the user block.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/coupons/{coupon}/assignments` |
| Path composition | `api/v1` prefix + `coupons/{coupon}/assignments` from the `Route::prefix('coupons/{coupon}')` group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:create-coupon-assignment` |
| Controller action | `Marvel\Http\Controllers\CouponAssignmentController@store(CouponAssignmentRequest $request, $couponId)` → `CouponAssignmentRepository::assignCoupon`: `Coupon::findOrFail` (miss → `ModelNotFoundException` → 404 translated); duplicate (`coupon_id`+`user_id`) check → `MarvelBadRequestException('COUPON_ALREADY_ASSIGNED_TO_USER')` → 409 with the RAW key (verified translation analysis: bare keys match no `message.*` entry since `app_notice_domain` is `''`); the DB `unique(coupon_id,user_id)` is the race arbiter, mapped to the same 409; then `CouponAssigned` event + discovery-cache invalidation (side effects, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-coupon-assignment` via route middleware AND constructor `only: [store]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (unnamed prefix-group route) |

Middleware runs before validation: 401/403 precede 404/409/422.

## Request inputs

`CouponAssignmentRequest` runs before the body. NOTE: creating the FIRST assignment converts a public coupon to assigned (past public usage still counts — per the suggest-fix business rules, code-verified domain fact).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `coupon` | path | integer | Required | No | Cast `(int)`; `findOrFail` (soft-deletes N/A — coupons are hard-deleted) | Existing coupon id | None | None | `1` | Identifies the coupon |
| `user_id` | body | integer | Required | No | `required\|integer\|exists:users,id` | Existing user id | None | None | `7` | The customer receiving the coupon |
| `max_uses` | body | integer | Required | No | `required\|integer\|min:1` | Integer ≥ 1 | None | None | `3` | Per-customer redemption quota |
| `expires_at` | body | date string | Optional | Yes | `nullable\|date\|after:now` (must be FUTURE — past dates 422, code-verified) | Future date | `null` (no expiry) | None | `"2026-12-31"` | Assignment expiry |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/coupons/1/assignments",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "user_id": 7,
    "max_uses": 3
  }
}
```

# Responses

## Success: assignment created — HTTP 201

Returned via `ApiResponse::apiResponse(COUPON_ASSIGNED_SUCCESSFULLY, 201, true, CouponAssignmentResource::make($assignment->load('user')))` (user block always present here).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.COUPON_ASSIGNED_SUCCESSFULLY` | `en` → `"Coupon assigned successfully."` (note trailing period) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CouponAssignmentResource` with `user` loaded | `id`, `coupon_id`, `user_id`, `user{id, name, email}`, `max_uses`, `used: 0`, `remaining` (= max_uses), `is_expired: false`, `assigned_at`, `expires_at` |

```json
{
  "status": 201,
  "message": "Coupon assigned successfully.",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 1,
    "user_id": 7,
    "user": { "id": 7, "name": "Jane", "email": "jane@example.com" },
    "max_uses": 3,
    "used": 0,
    "remaining": 3,
    "is_expired": false,
    "assigned_at": "2026-01-01T00:00:00+00:00",
    "expires_at": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `create-coupon-assignment` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No coupon with `{coupon}` (`findOrFail` → `ModelNotFoundException` → translated path) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"` | None | Unknown coupon id with valid body (code-traced) | See 404 example |
| 409 | Customer already assigned (pre-check OR race-loser on `unique(coupon_id,user_id)` — both map here; code-verified) | `{status: integer, message: string, success: boolean}` | None | `"COUPON_ALREADY_ASSIGNED_TO_USER"` (RAW untranslated key — `translateNotice` finds no `message.COUPON_ALREADY_ASSIGNED_TO_USER` entry since `app_notice_domain` is `''`; verified against `config/shop.php` + `en/message.php`, which only holds the `ERROR.`-prefixed twin) | None | Duplicate assignment (code-traced; the human text would be `"This coupon is already assigned to this user."` but the wire carries the key) | See 409 example |
| 400 | Any other post-validation failure (non-unique DB error escaping the unique-violation mapper, event/listener failure) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong"` | None | Post-validation failure (code-traced) | See 400 example |
| 422 | `CouponAssignmentRequest` fails (missing/unknown `user_id`, missing/`min:1`-violating `max_uses`, past `expires_at`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
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
  "message": "COUPON_ALREADY_ASSIGNED_TO_USER",
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

422 example:

```json
{
  "message": "The user id field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "user_id": ["The user id field is required."],
    "max_uses": ["The max uses field is required."]
  }
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

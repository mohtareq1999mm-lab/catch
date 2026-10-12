# Endpoint Name

Coupon Assignments – List Assignments (`GET /api/v1/coupons/{coupon}/assignments`)

# Description

Returns a paginated list of customer assignments for one coupon id with user blocks.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/coupons/{coupon}/assignments` |
| Path composition | `api/v1` prefix + `coupons/{coupon}/assignments` from the `Route::prefix('coupons/{coupon}')` group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:view-coupon-assignments` (two segments after `coupons`, so no collision with `coupons/{coupon}` show; route-inventory confirms the exact middleware set) |
| Controller action | `Marvel\Http\Controllers\CouponAssignmentController@index(Request $request, $couponId)` → `CouponAssignmentRepository::listByCoupon((int) $couponId, (int) $limit)` with `user` eager-loaded, ordered `assigned_at DESC`, paginated; NOTE (code-verified): the coupon's own existence is NEVER checked — an unknown `{coupon}` yields 200 with an empty page, NOT 404; `catch (Exception)` converts real failures into 404 translated (code-verified wart: list failures render as 404) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-coupon-assignments` via route middleware AND constructor `only: [index, show]` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (unnamed prefix-group route — route-inventory confirms `name: null`) |

## Request inputs

No validation runs — `limit` is cast, never rejected (garbage coerces per PHP `(int)` rules; code-verified unvalidated).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `coupon` | path | integer | Required | No | Cast `(int)`; NO existence check (unknown → empty 200) | Any integer (existing coupon for meaningful rows) | None | None | `1` | Identifies the coupon |
| `limit` | query | integer | Optional | No | `$request->limit ?? 15`, cast `(int)`; unvalidated | Any integer | `15` | None | `15` | Page size |

No body fields. No `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/coupons/1/assignments?limit=15",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(COUPON_ASSIGNMENTS_FETCHED_SUCCESSFULLY, 200, true, [...])` with a CUSTOM envelope (resource collection nested under `data.data` — NOT the banner-style meta flattening; code-verified distinct shape).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.COUPON_ASSIGNMENTS_FETCHED_SUCCESSFULLY` | `en` → `"Assignments fetched successfully."` (note trailing period) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Custom pagination envelope | Keys below |
| `data.data` | array | Always present (possibly empty — including for UNKNOWN coupons) | Items by `CouponAssignmentResource` with `user` loaded | Item: `id`, `coupon_id`, `user_id`, `user{id, name, email}`, `max_uses`, `used`, `remaining`, `is_expired`, `assigned_at`, `expires_at` |
| `data.current_page` / `data.from` / `data.last_page` / `data.per_page` / `data.to` / `data.total` | integers/null | Always present (`from`/`to` are null when empty) | Paginator values (NOTE: no `page` alias, no `path`, no links — unlike the banner envelope) | Paginator values |

```json
{
  "status": 200,
  "message": "Assignments fetched successfully.",
  "success": true,
  "data": {
    "data": [
      {
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
    ],
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-coupon-assignments` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced double gate) | See 403 example |
| 404 | Repository/pagination failure (the ONLY failure mapping on this action — unknown coupons do NOT 404, they return empty 200) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"` | None | Post-auth failure (code-traced wart) | See 404 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

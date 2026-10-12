# Endpoint Name

Coupons – Usage Info (`GET /api/v1/coupons/{id}/usage-info`)

# Description

Returns a computed capacity explainer for one coupon id with assignment aggregates.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/coupons/{id}/usage-info` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route → 404 fixed shape) |
| Path composition | `api/v1` prefix + `coupons/{id}/usage-info` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.coupons.usage-info`) in `packages/marvel/src/Rest/Routes.php` (two segments after `coupons`, so no collision with `coupons/{coupon}` show) |
| Controller action | `App\Http\Controllers\Api\Admin\CouponConfigurationController@getUsageInfo(Request $request, $couponId)` → `authorizeAdmin` (triple-permission fallback); then `Coupon::with(['assignments', 'couponUsages'])->findOrFail($couponId)` — NO try/catch, so a miss throws `ModelNotFoundException` → `Handler` 404 FIXED string (code-verified — NOT the translated coupon message); then pure aggregation (`isPublic()` = no assignment rows; `remaining = max(0, limiter - used)`; unlimited when limiter null; multi-use = any assignment with `max_uses > 1`; public usage counted from `couponUsages`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | Any ONE of `view-coupons` / `update-coupon` / `create-coupon` via inline `authorizeAdmin` |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.coupons.usage-info` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` (non-numeric → no route match) | Existing coupon id | None | None | `1` | Identifies the coupon |

No query parameters, body fields, or `422` contract. Read-only (no writes, no events). No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/coupons/1/usage-info",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: explainer returned — HTTP 200

Returned via `ApiResponse::apiResponse('Usage info retrieved.', 200, true, [...])` (literal message, not a constant). All keys verified verbatim in source.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Usage info retrieved."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Explainer payload (keys verified) | Keys below |
| `data.coupon_code` | string | Always present | The coupon's server-generated code | e.g. `"COUPON_X7K2P9Q"` |
| `data.coupon_type` | string | Always present | `public` = no assignment rows, else `assigned` (derived, NOT the `is_public` flag) | `public`, `assigned` |
| `data.usage_model` | string | Always present | Human sentence from `getUsageDescription()` (verified literals: `"Public coupon: Single use per customer (global limit: {limiter\|unlimited})"`, `"Assigned coupon: Up to {max} uses per assigned customer"`) | Sentence |
| `data.current_usage` | integer | Always present | `(int) coupon.used` | Redemption count |
| `data.global_limit` | integer/null | Always present | `coupon.limiter` (null = unlimited) | Integer or null |
| `data.remaining_capacity` | integer/string | Always present | `max(0, limiter - used)`, or the STRING `"unlimited"` when limiter is null (mixed type — code-verified) | Integer or `"unlimited"` |
| `data.is_multi_use_per_user` | boolean | Always present | Any assignment with `max_uses > 1` | `true`/`false` |
| `data.assignment_info` | object/null | Always present | Null for public coupons; aggregates otherwise (`total_assignments`, `assignments_with_usage` (used > 0), `max_uses_per_user` (max, default 1), `total_possible_redemptions` = count × max) | Object or null |
| `data.public_usage_count` | integer | Always present | `couponUsages` count for public coupons, else `0` | Integer |

```json
{
  "status": 200,
  "message": "Usage info retrieved.",
  "success": true,
  "data": {
    "coupon_code": "COUPON_X7K2P9Q",
    "coupon_type": "assigned",
    "usage_model": "Assigned coupon: Up to 3 uses per assigned customer",
    "current_usage": 1,
    "global_limit": 100,
    "remaining_capacity": 99,
    "is_multi_use_per_user": true,
    "assignment_info": {
      "total_assignments": 2,
      "assignments_with_usage": 1,
      "max_uses_per_user": 3,
      "total_possible_redemptions": 6
    },
    "public_usage_count": 0
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NONE of the three permissions | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: view-coupons."` (verbatim) | None | Valid token without any of the three (code-traced) | See 403 example |
| 404 | No coupon with `{id}` (`findOrFail` escapes — no translated coupon message here) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route (`whereNumber`) | `{message: string, status: boolean}` | None | `"Not Found"` (NOT the fixed resource string — route-miss shape) | None | Non-numeric segment | See 404-constraint example |
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
  "message": "Forbidden. Missing required permission: view-coupons.",
  "status": false
}
```

404 example (unknown numeric id):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-constraint example (non-numeric id):

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

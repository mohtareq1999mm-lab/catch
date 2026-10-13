# Endpoint Name

Coupons – List Distribution Runs (`GET /api/v1/coupons/{id}/distributions`)

# Description

Returns a paginated run history for one coupon id with a custom meta envelope.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/coupons/{id}/distributions` (explicit `{id}` placeholder WITHOUT `whereNumber` — non-numeric segments REACH the controller where `findOrFail` misses → 404 translated coupon message, NOT the route-miss shape; code-verified distinction from its `whereNumber` siblings) |
| Path composition | `api/v1` prefix + `coupons/{id}/distributions` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (NO route name — code-verified unnamed; route middleware `permission:update-coupon` — working-tree addition that runs BEFORE the inline check) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\CouponDistributionAdminController@index(Request $request, int $id)` → `authorizeAdmin` (`update-coupon`); `Coupon::findOrFail($id)` miss → 404 translated `COUPON_NOT_FOUND` (explicit catch — unlike targeting); then `CouponDistributionRun` rows for the coupon ordered `id DESC`, hardcoded `paginate(15)` (NO `limit` parameter is read — `?limit=` is silently ignored; code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | `update-coupon` (config key `coupon-distribution.admin_permission`) via route middleware FIRST, then inline `authorizeAdmin` (the inline verbatim abort is now unreachable for permissionless callers while the config matches the route) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (unnamed — code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `int $id` signature; NO `whereNumber` (non-numeric reaches the controller and 404s translated — code-verified) | Existing coupon id | None | None | `1` | Identifies the coupon |

No query parameters are honored (`limit`/`page` excepted — `page` works via the paginator's default resolution; `limit` is ignored), no body, no `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/coupons/1/distributions",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: run history returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ['data' => [...runs], 'meta' => {...}])` with a CUSTOM envelope (neither banner-style nor assignment-style: `data.data` is a plain array and `data.meta` carries only four keys — code-verified third shape).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Custom envelope | `data` (run array), `meta` (four keys) |
| `data.data` | array | Always present (possibly empty) | Run presentations (`presentRun` — same keys as distribute; counters only, no PII) | `id`, `coupon_id`, `trigger_type`, `tree_hash`, `status`, `candidate_count`, `eligible_count`, `not_eligible_count`, `notified_count`, `failed_count`, `duplicate_skipped_count`, `started_at`, `finished_at` |
| `data.meta` | object | Always present | Pagination meta (ONLY four keys — no `from`/`to`/`path`/links, code-verified) | `current_page`, `per_page` (always 15), `total`, `last_page` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [],
    "meta": {
      "current_page": 1,
      "per_page": 15,
      "total": 0,
      "last_page": 1
    }
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks the configured permission (route pipe rejects first) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No coupon with `{id}` (explicit catch → translated coupon key) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.COUPON_NOT_FOUND`: `en` → `"Coupon not found"` | None | Unknown id, numeric or not (no `whereNumber` here — both reach the controller; code-verified) | See 404 example |
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
  "status": 404,
  "message": "Coupon not found",
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

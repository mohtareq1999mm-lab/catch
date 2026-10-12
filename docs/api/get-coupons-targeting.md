# Endpoint Name

Coupons – Show Targeting (`GET /api/v1/coupons/{id}/targeting`)

# Description

Returns one coupon's targeting row with claim settings or a translated 404 when absent.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/coupons/{id}/targeting` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `coupons/{id}/targeting` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.coupons.targeting.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\CouponTargetingController@show(Request $request, int $id)` → `authorizeAdmin` (= `authorizeRead`: view OR update OR create coupon permission, triple fallback); `Coupon::findOrFail($id)` with NO try/catch → miss escapes as `ModelNotFoundException` → 404 FIXED string (code-verified); missing targeting row → `apiResponse(COUPON_NO_TARGETING, 404, false)` (translated — the two 404s differ, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | READ split: any ONE of `view-coupons` / `update-coupon` / `create-coupon` via inline `authorizeRead` (writes demand more — see upsert/destroy docs; the BLOCKER-fix split is source-commented) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.coupons.targeting.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing coupon id | None | None | `1` | Identifies the coupon |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/coupons/1/targeting",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: targeting returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CouponTargetingResource::make($targeting))`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CouponTargetingResource` (keys verified verbatim) | `id`, `coupon_id`, `mode` (`assignment`/`dynamic`/`assignment_and_dynamic`/`assignment_or_dynamic`), `require_claim` (bool), `max_claims` (int/null), `claim_ttl_hours` (int/null), `rule_tree` (object/null — validated tree or null meaning "no rules" for dynamic-family modes), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 1,
    "mode": "dynamic",
    "require_claim": false,
    "max_claims": null,
    "claim_ttl_hours": null,
    "rule_tree": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the two different 404s.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NONE of the three read permissions | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: view-coupons."` (verbatim) | None | Valid token without any of the three (code-traced) | See 403 example |
| 404 | No coupon with `{id}` (`findOrFail` escapes — no translated coupon message here) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Coupon exists but has NO targeting row (backward-compat "always eligible" state) | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | Translated `ERROR.COUPON_NO_TARGETING`: `en` → `"This coupon has no targeting configuration."` | None | Untargeted coupon (code-traced) | See 404-targeting example |
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

404-targeting example (no targeting row):

```json
{
  "status": 404,
  "message": "This coupon has no targeting configuration.",
  "success": false
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

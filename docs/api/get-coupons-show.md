# Endpoint Name

Coupons – Show Coupon (`GET /api/v1/coupons/{coupon}`)

# Description

Returns one coupon by id or code with assignments and targeting loaded.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/coupons/{coupon}` |
| Path composition | `api/v1` prefix + `coupons/{coupon}` from bare `Route::apiResource('coupons', ...)` (no route middleware — route-inventory confirms group middleware only; the in-controller `view-coupons` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group. NOTE the `coupons/rules` static route is registered BEFORE the apiResource precisely so `rules` is not captured here (source comment verified) |
| Controller action | `Marvel\Http\Controllers\CouponController@show(Request $request, $id)` (untyped — numeric segment → `where('id')`, anything else → `orWhere('code')`, `firstOrFail`; then `loadMissing(assignments, targeting)`); `catch (Throwable)` converts EVERYTHING (miss or otherwise) into `apiResponse(NOT_FOUND, 404, false)` (translated `ERROR.NOT_FOUND` — the broadest catch in this file, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-coupons` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `coupons.show` (apiResource default naming — and NOTE: `CouponResource` checks `routeIs('coupons.show')`, TRUE here, so `name` is the `{ar,en}` OBJECT on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale (`name` is bilingual here regardless) |
| `coupon` | path | integer id OR code string | Required | No | No route-level constraint; id match first, else exact `code` match (code comparison is DB-collation-dependent for case — **Unverified in source** whether `save10` matches `SAVE10`) | Id or code of an existing coupon | None | None | `1` or `COUPON_X7K2P9Q` | Identifies the coupon |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/coupons/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: coupon returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CouponResource::make($coupon))` with `assignments` + `targeting` loaded.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CouponResource` with `name` as `{ar,en}` OBJECT (code-verified show branch) | Same key set as the list item, plus loaded `assignments[]` and `targeting{}` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "code": "COUPON_X7K2P9Q",
    "name": { "ar": "تخفيضات الصيف", "en": "Summer Sale" },
    "image": { "desktop": null, "mobile": null },
    "borderColor": null,
    "borderless": false,
    "discount": "50.00",
    "discount_type": "Percentage discount",
    "max_discount_amount": 100.0,
    "start_date": "2026-01-01",
    "end_date": "2026-02-01",
    "limiter": 100,
    "used": 3,
    "status": true,
    "is_valid": true,
    "audience": {},
    "is_assigned": false,
    "audience_type": "PUBLIC",
    "targeting_mode": null,
    "targeting": null,
    "assignments": [],
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-coupons` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-coupons` (code-traced) | See 403 example |
| 404 | No coupon matches `{coupon}` by id or code (OR any other `Throwable` — the catch is total; code-verified) | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"` (generic — NOT the coupon-specific key; compare destroy's fixed string) | None | Unknown id or code | See 404 example |
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

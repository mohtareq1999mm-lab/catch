# Endpoint Name

Order Flows – List Flows (`GET /api/v1/admin/order-flows`)

# Description

Returns a paginated flow list with escaped search and a clamped page size.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/order-flows` |
| Path composition | `api/v1` prefix + `admin/order-flows/` from the `Route::prefix('admin/order-flows')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-flows.index`) in `packages/marvel/src/Rest/Routes.php`. No `lang` in group middleware (same as its order-flow siblings — no locale behavior documented) |
| Controller action | `App\Http\Controllers\Api\Admin\OrderFlowController@index` (constructor `auth:sanctum` ONLY — no permission middleware; the route pipe alone decides): `statuses` eager-loaded, optional `shipping_type` exact match, `is_active` via `FILTER_VALIDATE_BOOLEAN` (same `abc`-means-false wart as the currency index — code-verified), `search` over `code`/`name` with LIKE metacharacters ESCAPED (`escapeLike` — `%`/`_` are literal here, unlike the unescaped promotion/coupon searches; code-verified distinction), `id ASC` ordering, custom envelope |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `view-order-flows` / `view-orders` / `view-order` via the route pipe (no constructor/inline check exists — the pipe alone decides) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests) |
| Route name | `api.admin.order-flows.index` (explicit named route) |

## Request inputs

No `FormRequest` — NOTHING here 422s (unknown `shipping_type` yields an empty page, not a rejection; code-verified leniency, unlike the strict create path).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `shipping_type` | query | string | Optional | No | Applied only when `filled`; EXACT match, unvalidated (unknown types → empty 200) | Any string (meaningful: `local`, `international`) | None (no filter) | None | `international` | Shipping-type filter |
| `is_active` | query | boolean-ish | Optional | No | Applied only when `filled`; `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — unrecognized strings (e.g. `abc`) become `false` and filter INACTIVE rows instead of erroring (code-verified wart) | `true`/`false`/`1`/`0` | None (no filter) | None | `true` | Active filter |
| `search` | query | string | Optional | No | Applied only when `filled`; LIKE-escaped `code`/`name` match (`%`/`_` literal — code-verified) | Any string | None (no filter) | None | `intl` | Free-text filter |
| `per_page` | query | integer | Optional | No | Clamped `max(1, min(200, (int) per_page))` — never rejected (code-verified; NOTE the 200 cap and 50 default, both unique in this API) | Effective `1`–`200` | `50` | None | `50` | Page size |

No endpoint test found for the ADMIN index (only general/available + service tests) — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/order-flows?per_page=50",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: page returned — HTTP 200 (CUSTOM envelope)

Returned via `ApiResponse::apiResponse('Order flows retrieved successfully.', 200, true, ['data' => ..., 'meta' => ...])` (literal message). Custom envelope — fourth shape in this API: `data` is a plain array and `meta` carries ONLY three keys (code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Order flows retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Custom envelope | `data` (flow array), `meta` (`current_page`, `per_page`, `total` ONLY — no `last_page`/`from`/`to`/links, code-verified) |
| `data.data[]` | object | Always present (possibly empty) | `OrderFlowResource` with `statuses` loaded (positioned `{id, code, name, is_active, sort_order}` rows — array order IS the transition map) | `id`, `code`, `name` (localized via `LocalizedName`), `shipping_type`, `is_default`, `is_active`, `statuses[]`, `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Order flows retrieved successfully.",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "code": "intl_flow",
        "name": { "en": "International" },
        "shipping_type": "international",
        "is_default": true,
        "is_active": true,
        "statuses": [],
        "created_at": "2026-01-01T00:00:00+00:00",
        "updated_at": "2026-01-01T00:00:00+00:00"
      }
    ],
    "meta": {
      "current_page": 1,
      "per_page": 50,
      "total": 1
    }
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (code-traced; the cancel twin pins 401 strictly on this group stack) | See 401 example |
| 403 | Authenticated caller holds NONE of the three pipe permissions | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without any of the three (code-traced; inputs twin pins 403 for the same pipe family in `test_input_endpoints_require_permission`) | See 403 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

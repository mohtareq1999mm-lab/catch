# Endpoint Name

Order Flows – Show Flow (`GET /api/v1/admin/order-flows/{id}`)

# Description

Returns one flow by numeric id with ordered statuses and ordered inputs.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/order-flows/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/order-flows/{id}` from the `Route::prefix('admin/order-flows')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-flows.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\OrderFlowController@show(int $id)` → `findOrFail` with `statuses` + ORDERED `inputs` eager-loaded (miss escapes → 404 FIXED string — code-verified, no try/catch); the resource array is built manually and `inputs` APPENDED as a top-level key beside the resource keys (NOT nested inside a relation envelope — code-verified serialization) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `view-order-flows` / `view-orders` / `view-order` via the route pipe (no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-flows.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing flow id | None | None | `2` | Identifies the flow |

No query parameters, body fields, or `422` contract.

## Request examples

Valid request showing a flow (test-compatible call):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/order-flows/2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: flow returned — HTTP 200 (resource keys + top-level `inputs`)

Returned via `ApiResponse::apiResponse('Order flow retrieved successfully.', 200, true, $payload)` where `$payload` is the resource array PLUS `inputs`. Verified by `test_flow_show_returns_ordered_statuses` (`assertOk` via `adminGet`, exact 12-code order assertion — the `statuses` array order IS the transition map).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Order flow retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Resource keys + `inputs` (keys verified) | `id`, `code`, `name` (localized), `shipping_type`, `is_default`, `is_active`, `statuses[{id, code, name, is_active, sort_order}]` (position order), `inputs[]` (ORDERED `FlowInputResource` rows — full 15-key shape, see inputs docs), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Order flow retrieved successfully.",
  "success": true,
  "data": {
    "id": 2,
    "code": "intl_flow",
    "name": { "en": "International" },
    "shipping_type": "international",
    "is_default": true,
    "is_active": true,
    "statuses": [],
    "inputs": [],
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NONE of the three pipe permissions | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without any of the three (code-traced) | See 403 example |
| 404 | No flow with `{id}` (`findOrFail` escapes — no translated flow message exists on this action) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced; no show-miss test found) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route (`whereNumber`) | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
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
  "message": "Resource Not Found",
  "status": false
}
```

404-constraint example:

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

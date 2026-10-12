# Endpoint Name

Order Statuses – Show Status (`GET /api/v1/admin/order-statuses/{id}`)

# Description

Returns one order status by numeric id with no inputs and no validation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/order-statuses/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/order-statuses/{id}` from the `Route::prefix('admin/order-statuses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-statuses.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\OrderStatusCatalogController@show(int $id)` → `findOrFail` with NO relations and NO try/catch (miss escapes → 404 FIXED string — code-verified); resource array built manually (same pattern as flow show) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `view-order-flows` / `view-orders` / `view-order` via the route pipe (no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-statuses.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing status id | None | None | `1` | Identifies the status |

No query parameters, body fields, or `422` contract. No show-specific test found (index/update twins pin the neighboring contracts) — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/order-statuses/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: status returned — HTTP 200

Returned via `ApiResponse::apiResponse('Order status retrieved successfully.', 200, true, (new OrderStatusResource($status))->toArray(request()))` (literal message; resource ARRAY — code-verified serialization choice shared with flow show/update).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated — singular `status`, unlike the plural index message) | Always `"Order status retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `OrderStatusResource` array | `id`, `code`, `name` (localized), `description`, `is_active` (bool), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Order status retrieved successfully.",
  "success": true,
  "data": {
    "id": 1,
    "code": "pending",
    "name": { "en": "Pending" },
    "description": null,
    "is_active": true,
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
| 404 | No status with `{id}` (`findOrFail` escapes — no translated status message exists on this action) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
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

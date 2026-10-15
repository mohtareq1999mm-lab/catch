# Endpoint Name

Admin Tracking – Requires Attention (`GET /api/v1/admin/tracking/requires-attention`)

# Description

Returns counts of orders needing operational attention plus generated alerts. All computation is server-side with no inputs; stuck-payment logging is best-effort and never breaks the endpoint.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/tracking/requires-attention` (static segment under the same `v1/admin/tracking` prefix as `orders`/`orders/{orderId}` — sibling paths, so no overlap regardless of `whereNumber`; code-verified) |
| Path composition | `api` prefix + `v1/admin/tracking` prefix + `requires-attention` from `routes/api.php:234` inside the `['api', 'auth:sanctum', 'throttle:admin']` group (NO `lang` middleware) |
| Controller action | `App\Http\Controllers\Api\Admin\AdminOrderTrackingController@requiresAttention` → `authorizeAdmin`, then four `Order::query()->count()` probes + `total = array_sum` + `generateAlerts()` + best-effort stuck-payment logging (swallowed `Throwable`) → 200 `array_merge($issues, ['alerts'])` |
| Authentication | Required twice: `auth:sanctum` group middleware + in-method `authorizeAdmin` 401 guard (unreachable behind the middleware) |
| Permission | Required: `view-orders` via in-method `authorizeAdmin` (same rule as dashboard — Spatie/gate pass, strict deny for legacy admin-type without the permission when the system is available) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No query, no body; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests) |
| Route name | `api.admin.tracking.attention` (explicit named route; `route:list` verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |

No query parameters, path parameters, body fields, validation, or lookup — NO `422`, NO `404`.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/tracking/requires-attention",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: attention metrics — HTTP 200

Returned via `apiResponse(__('message.MESSAGE.ATTENTION_METRICS_RETRIEVED'), 200, true, issues + alerts)` (`en` → `"Attention metrics retrieved successfully."`, `message.php:591`). Verified by `admin_requires_attention_endpoint` (200, structure `data: [payment_pending, processing_delayed, payment_failed, total]`; uses `getJson`) and `test_admin_with_view_orders_can_access_tracking` (200 on this URI).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ATTENTION_METRICS_RETRIEVED` | `en` → `"Attention metrics retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data.payment_pending` | integer | Always present | `status pending` + `payment pending` + created > 24h ago | Count (possibly 0) |
| `data.processing_delayed` | integer | Always present | `status processing` + created > 48h ago | Count |
| `data.payment_failed` | integer | Always present | `payment failed` + created within last 7d | Count |
| `data.payment_verification_stuck` | integer | Always present | `pending` + `payment pending` + `online` + created 30min–24h ago (P2-3 window) | Count — NOT asserted by the attention test (code-verified) |
| `data.total` | integer | Always present | `array_sum` of the four probes | Count |
| `data.alerts` | array (0–3) | Always present | Generated from thresholds: stuck>0 → `{warning, medium, link /admin/orders?payment_status=…}`; pending>5 → `{info, low, …}`; delayed>0 → `{warning, high, …}` — each `{type, priority, count, message (translated ATTENTION_*), action, link}` | Possibly `[]` |

```json
{
  "status": 200,
  "message": "Attention metrics retrieved successfully.",
  "success": true,
  "data": {
    "payment_pending": 2,
    "processing_delayed": 1,
    "payment_failed": 0,
    "payment_verification_stuck": 0,
    "total": 3,
    "alerts": []
  }
}
```

(Figures illustrative; keys/message/structure verified — `payment_verification_stuck`/`alerts` are code-verified beyond the test's asserted structure.)

# Errors

No error-code field; every error below has Error code `None`. No inputs and no lookup → NO `422`, NO `404`. 200/401/403 test-verified; 405/429/500 code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period) | None | No valid token (verified by `test_admin_tracking_requires_authentication` on this URI) | See 401 example |
| 403 | Caller fails `authorizeAdmin` | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: view-orders."` (via `translateHttpMessage` catalogue `ERROR.FORBIDDEN_PERMISSION`; `en` identical) | None | Customer without permission (verified by `test_customer_without_permission_denied_on_tracking` on this URI) | See 403 example |
| 405 | Method other than `GET` (only `Route::get` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | E.g. `POST` (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `"Too many requests. Please try again later."` | None | Rate limit exceeded (code-traced) | See 429 example |
| 500 | Unexpected failure (stuck-payment logging is swallowed, so only counts/alerts escaping) | `{message: string, status: boolean}` | None | Exception message when `app.debug`, else `"Internal Server Error"` | None | DB failure (code-traced, no test) | See 500 example |

401 example:

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example:

```json
{
  "message": "Forbidden. Missing required permission: view-orders.",
  "status": false
}
```

405 example:

```json
{
  "message": "Method Not Allowed",
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

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

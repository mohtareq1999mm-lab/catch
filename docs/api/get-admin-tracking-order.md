# Endpoint Name

Admin Tracking – Track Order (`GET /api/v1/admin/tracking/orders/{orderId}`)

# Description

Returns one order with customer, items, transactions, status timeline, analytics, and available actions. Unknown ids return a 404 in the SUCCESS envelope (not the Handler shape) — see below.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/tracking/orders/{orderId}` (explicit `{orderId}` placeholder with `whereNumber('orderId')` — sibling `requires-attention` is a static segment under the same prefix so the two never overlap; a non-numeric final segment under `orders/` (e.g. `/orders/abc`) matches NO route) |
| Path composition | `api` prefix + `v1/admin/tracking` prefix + `orders/{orderId}` from `routes/api.php:233` inside the `['api', 'auth:sanctum', 'throttle:admin']` group (note: NO `lang` middleware — messages are fixed English) |
| Controller action | `App\Http\Controllers\Api\Admin\AdminOrderTrackingController@trackOrder(Request, $orderId)` → `authorizeAdmin`, then `Order::with(user, orderItems, statusHistory.changedBy, transactions)->find($orderId)`; null → `apiResponse(ERROR.ORDER_NOT_FOUND, 404, false)` (envelope, NOT `abort`); hit → best-effort tracking/metrics logging (swallowed `Throwable`) → 200 `{order, timeline, analytics, actions_available}` |
| Authentication | Required twice: `auth:sanctum` group middleware + in-method `authorizeAdmin` 401 guard (unreachable behind the middleware) |
| Permission | Required: `view-orders` via in-method `authorizeAdmin` (same rule as the dashboard doc — Spatie/gate pass, otherwise legacy admin-type fallback with strict deny when the permission system is available) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests) |
| Route name | `api.admin.tracking.order` (explicit named route; `route:list` verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `orderId` | path | integer | Required | No | `whereNumber('orderId')`; no type-hint — passed to `find()` | Visible order id | None | None | `42` | Identifies the order |

No query parameters, body fields, or validation — NO `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/tracking/orders/42",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: order tracking — HTTP 200

Returned via `apiResponse(__('message.MESSAGE.TRACKING_RETRIEVED_SUCCESSFULLY'), 200, true, [order, timeline, analytics, actions_available])` (`en` → `"Order tracking retrieved successfully."`, `message.php:588`). Verified by `admin_can_view_single_order_tracking` (200, `success: true`, structure `data: [order, timeline, analytics, actions_available]`; uses `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.TRACKING_RETRIEVED_SUCCESSFULLY` | `en` → `"Order tracking retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data.order` | object | Always present | Full order model | With `user`, `orderItems`, `statusHistory`, `transactions` |
| `data.timeline` | array | Always present (possibly empty) | `statusHistory` asc by `changed_at`: `{id, timestamp (ISO), timestamp_human, old_status, new_status, payment_status, fulfillment_status, changed_by{id,name,email}/null, changed_by_type, notes, metadata}` | Chronological |
| `data.analytics` | object | Always present | `{total_status_changes, time_to_complete (trans_choice HOURS or null when incomplete), time_since_creation (human), time_in_current_status (first-history change or creation, human)}` | Human strings via `diffForHumans()` |
| `data.actions_available` | object | Always present | Five booleans from exact rules: `can_process (pending + payment-success)`, `can_ship (completed + fulfillment processing)`, `can_cancel (pending/processing)`, `can_refund (payment-success + not delivered)`, `can_mark_delivered (fulfillment out_for_delivery)` | Code-verified rules |

```json
{
  "status": 200,
  "message": "Order tracking retrieved successfully.",
  "success": true,
  "data": {
    "order": { "id": 42, "order_number": "ORD-00000042", "status": "processing" },
    "timeline": [],
    "analytics": {
      "total_status_changes": 1,
      "time_to_complete": null,
      "time_since_creation": "2 hours ago",
      "time_in_current_status": "2 hours ago"
    },
    "actions_available": {
      "can_process": false,
      "can_ship": false,
      "can_cancel": true,
      "can_refund": false,
      "can_mark_delivered": false
    }
  }
}
```

(Rows abbreviated; keys/structure verified, figures illustrative.)

## Not-found: unknown order — HTTP 404 (SUCCESS envelope, NOT Handler shape)

Returned via `apiResponse(__('message.ERROR.ORDER_NOT_FOUND'), 404, false)` (`en` → `"Order not found."`, `message.php:592`) — note `success: false` + integer `status: 404` + `message`, UNLIKE the Handler `{message, status: boolean}` shape. No `data` key (`empty()` guard in `ApiResponse::apiResponse`).

```json
{
  "status": 404,
  "message": "Order not found.",
  "success": false
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation → no `422`. The 200 contract is test-verified; 401/403 are test-verified on the sibling tracking URIs + code-traced here (shared `authorizeAdmin`); the 404-envelope row is code-verified (no dedicated miss test found).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated."` (WITH period) | None | No valid token (verified on sibling tracking URIs by `test_admin_tracking_requires_authentication`; shared middleware — code-traced here) | See 401 example |
| 403 | Caller fails `authorizeAdmin` | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: view-orders."` (via `translateHttpMessage` catalogue `ERROR.FORBIDDEN_PERMISSION`; `en` identical to the abort string) | None | Customer without permission (verified on `orders/1` by `test_customer_without_permission_denied_on_tracking` → 403) | See 403 example |
| 404 | Unknown `{orderId}` (envelope, NOT Handler) | `{status: integer, message: string, success: boolean}` (no `data`) | None | `"Order not found."` (`ERROR.ORDER_NOT_FOUND`) | None | Numeric id with no row (code-verified `find()` null branch) | See 404-envelope example |
| 404 | Non-numeric segment matches NO route | `{message: string, status: boolean}` (Handler route-miss shape — different text) | None | `"Not Found"` | None | E.g. `/orders/abc` (code-verified `whereNumber`) | See 404-constraint example |
| 405 | Method other than `GET` (only `Route::get` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | E.g. `POST` (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `"Too many requests. Please try again later."` | None | Rate limit exceeded (code-traced) | See 429 example |
| 500 | Unexpected failure (logging is swallowed, so only query/serialization escapes) | `{message: string, status: boolean}` | None | Exception message when `app.debug`, else `"Internal Server Error"` | None | DB failure (code-traced, no test) | See 500 example |

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

# Endpoint Name

Admin Orders – Show Shipment (`GET /api/v1/admin/orders/{orderId}/shipment`)

# Description

Returns one order's shipment fields as plain JSON with no message key and no permission beyond viewing.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/orders/{orderId}/shipment` |
| Path composition | `api/v1` prefix + `admin/orders/{orderId}/shipment` from the `Route::prefix('admin/orders')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group in `packages/marvel/src/Rest/Routes.php` with route-level ANY-of `permission:view-shipment\|view-shipments\|view-orders\|view-order` (four-way pipe — any one passes; source comment cites SEC-1 as the reason this pipe exists at all). NOTE the group middleware has NO `lang` entry (unlike the Marvel group) — there is no locale behavior on this action |
| Controller action | `App\Http\Controllers\Api\Admin\ShipmentController@show(string $orderId)` — a PLAIN `Controller` with NO constructor middleware (group supplies auth; no in-controller permission check — the route pipe alone decides). `Order::findOrFail` miss (or any `Exception`) → plain 404 `__('message.ERROR.ORDER_NOT_FOUND')` (`message.php:592` → `"Order not found."` WITH period — translated, NOT the fixed `Resource Not Found` string — code-verified). Success is plain `response()->json` (NOT `ApiResponse` — there is NO `status` key and NO `message` key on success; code-verified shape difference) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Any ONE of `view-shipment` / `view-shipments` / `view-orders` / `view-order` via the route pipe (no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests) |
| Route name | `api.admin.orders.shipment.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `orderId` | path | integer | Required | No | `whereNumber('orderId')` route constraint + `string $orderId` signature; non-numeric segments match NO route | Id of an existing order | None | None | `42` | Identifies the order |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract and no `lang` header behavior.

## Request examples

Valid request reading shipment state (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/orders/42/shipment",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: shipment returned — HTTP 200 (PLAIN shape, no `status`/`message` keys)

Plain `response()->json(['success' => true, 'data' => [...]])`. Verified by `test_admin_with_shipment_permission_can_update` (`assertOk`, `getJson`, same admin setup as the update half).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Shipment fields straight off the order row (keys verified) | `order_id`, `shipment_status`, `tracking_number`, `courier_name`, `estimated_delivery_at` (ISO or null), `actual_delivery_at` (ISO or null) |

```json
{
  "success": true,
  "data": {
    "order_id": 42,
    "shipment_status": "in_transit",
    "tracking_number": "TRK-1",
    "courier_name": "Aramex",
    "estimated_delivery_at": "2026-01-05T00:00:00+00:00",
    "actual_delivery_at": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.Shapes here are PLAIN (`success`/`message`, no `status` key) — NOT the `ApiResponse`/`Handler` shapes used elsewhere.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` (group-middleware shape) | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | Any request without a valid token (code-traced; the twin cancel action pins 401 strictly in `test_cancel_unauthenticated_returns_401`) | See 401 example |
| 403 | Authenticated caller holds NONE of the four pipe permissions | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Customer with no viewing permission (verified by `test_customer_cannot_read_admin_shipment` → 403) | See 403 example |
| 404 | No order with `{orderId}` (ANY `Exception` → translated message — code-verified total catch) | `{success: boolean, message: string}` (PLAIN shape, no `status` key) | None | `"Order not found."` (WITH period via `__('message.ERROR.ORDER_NOT_FOUND')`, `message.php:592` — NOT the fixed `Resource Not Found` string) | None | Unknown numeric id (code-traced; no show-miss test found) | See 404 example |
| 404 | Non-numeric `{orderId}` matches NO route (`whereNumber`) | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

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
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown numeric id — PLAIN shape):

```json
{
  "success": false,
  "message": "Order not found."
}
```

404-constraint example (non-numeric segment):

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

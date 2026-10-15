# Endpoint Name

Admin Orders – Update Shipment Status (`POST /api/v1/admin/orders/{orderId}/shipment/update-status`)

# Description

Drives one order's shipment transition through domain events with a stale tracking echo.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (only `POST` is registered — `PUT`/`PATCH` on this path → 405; code-verified, and the twin test uses `postJson`) |
| Full path | `/api/v1/admin/orders/{orderId}/shipment/update-status` |
| Path composition | `api/v1` prefix + `admin/orders/{orderId}/shipment/update-status` from the `Route::prefix('admin/orders')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group in `packages/marvel/src/Rest/Routes.php` with route-level ANY-of `permission:update-shipment\|create-shipment` (either passes; NO constructor/inline permission check exists — the route pipe alone decides). No `lang` in group middleware (same as the show sibling) |
| Controller action | `App\Http\Controllers\Api\Admin\ShipmentController@updateStatus(Request $request, string $orderId)` — manual `Validator::make(...)` + `->validate()` (→ `ValidationException` → `Handler` 422 with `errors` map); then `Order::findOrFail` + a DB transaction firing `ShipmentStatusChanged` ALWAYS and `EstimatedDeliveryChanged` ONLY when the ETA date-string actually changes (default reason `'admin_update'` when `notes` absent — code-verified); the actual row mutation happens in EVENT LISTENERS, not in the controller (so `shipment_status` must be re-read — and IS — while `tracking_number` is echoed from the STALE pre-event instance; code-verified asymmetry, proven by the test asserting the persisted `TRK-1` separately). ANY exception (INCLUDING the order miss) → caught → logged (`Shipment status update failed` with order id) → plain 500 `'Failed to update shipment status'` (code-verified wart: unknown orders 500 here, NOT 404 — the opposite of the show sibling) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Any ONE of `update-shipment` / `create-shipment` via the route pipe (no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.orders.shipment.update-status` (explicit named route) |

Order matters: 401/403 → 422 (validation) → 200/500 (lookup + transition share the total catch).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `orderId` | path | integer | Required | No | `whereNumber('orderId')` + `string $orderId` | Existing order id | None | None | `42` | Identifies the order |
| `status` | body | string | Required | No | `required\|string\|in:pending,label_created,picked_up,in_transit,out_for_delivery,delivered,failed_delivery,returned,cancelled` (NINE states — code-verified closed set) | The nine states | None | None | `"in_transit"` | Target shipment state (transition VALIDITY itself — e.g. skipping states — is listener/service territory, **Unverified in source** beyond acceptance) |
| `tracking_number` | body | string | Optional | Yes | `nullable\|string\|max:255` | ≤ 255 chars | Unchanged | None | `"TRK-1"` | Carrier tracking ref (test-verified persistence) |
| `courier_name` | body | string | Optional | Yes | `nullable\|string\|max:255` | ≤ 255 chars | Unchanged | None | `"Aramex"` | Carrier name |
| `location_data` | body | object | Optional | Yes | `nullable\|array` (keys unvalidated — code-verified passthrough) | Any object | Unchanged | None | `{"city": "Cairo"}` | Location payload for the event |
| `estimated_delivery_at` | body | date string | Optional | Yes | `nullable\|date` (compared as DATE-STRING vs the row's date; equal → no ETA event, code-verified) | Any date | Unchanged | None | `"2026-01-05"` | New ETA (fires the second event only on change) |
| `notes` | body | string | Optional | Yes | `nullable\|string` (no max — code-verified) | Any string | `'admin_update'` (as the ETA-event reason when absent) | None | `"Left at depot"` | Admin note / ETA reason |

## Request examples

Valid request driving a transition (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/orders/42/shipment/update-status",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "status": "in_transit",
    "tracking_number": "TRK-1"
  }
}
```

# Responses

## Success: transition accepted — HTTP 200 (PLAIN shape with a stale echo)

Plain `response()->json(['success' => true, 'message' => ..., 'data' => ...])` — NO `status` key (code-verified shape difference from `ApiResponse` actions). Message via `__('message.MESSAGE.SHIPMENT_STATUS_UPDATED_SUCCESSFULLY')` (`message.php:638`, NO period). Verified by `test_admin_with_shipment_permission_can_update` (`assertOk`, `postJson`; persistence proven separately: `shipment_status: in_transit`, `tracking_number: TRK-1`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.MESSAGE.SHIPMENT_STATUS_UPDATED_SUCCESSFULLY` (`message.php:638` — note NO trailing period, unlike `ORDER_NOT_FOUND`/`UNAUTHENTICATED`) | Always `"Shipment status updated successfully"` |
| `data` | object | Always present on success | Result payload (keys verified) | `order_id`, `shipment_status` (FRESH re-read — trustworthy), `tracking_number` (STALE pre-event instance echo — code-verified asymmetry; trust the show endpoint for confirmation, not this echo) |

```json
{
  "success": true,
  "message": "Shipment status updated successfully",
  "data": {
    "order_id": 42,
    "shipment_status": "in_transit",
    "tracking_number": "TRK-1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Success/error shapes here are PLAIN (`success`/`message`, no `status` key) EXCEPT the middleware layers (401/403/405/422/429 keep their usual shapes).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NEITHER `update-shipment` NOR `create-shipment` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Customer (verified by `test_customer_cannot_mutate_admin_shipment` → 403, plus `shipment_status` proven UNCHANGED) | See 403 example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Manual validation fails (missing/unknown `status`, overlong strings, non-array `location_data`, bad date) | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA` — `validate()` throw → `Handler` validation branch; field texts live in `errors`) | Field → messages | Invalid body (code-traced; no validation test found) | See 422 example |
| 500 | Unknown `{orderId}` (miss → total catch → 500, NOT 404 — code-verified wart, the inverse of the show sibling) OR transition/listener failure (logged with order id; the transaction rolls back but the log line persists) | `{success: boolean, message: string}` (PLAIN shape, no `status` key) | None | `"Failed to update shipment status"` via `__('message.ERROR.SHIPMENT_STATUS_UPDATE_FAILED')` (`message.php:639`, NO period) | None | Unknown id or transition failure (code-traced) | See 500 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example:

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "status": ["The status field is required."]
  }
}
```

500 example (unknown order OR transition failure — PLAIN shape):

```json
{
  "success": false,
  "message": "Failed to update shipment status"
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

# Endpoint Name

Shipments – Bulk Deliver (`POST /api/v1/admin/shipments/bulk-deliver`)

# Description

Staff-confirmed delivery for many labels at once (walks `picked_up` → … → `delivered` inside the service). Fault-tolerant: delivered rows progress, missing/premature rows report per-item reasons. Always HTTP 200 — check `summary`.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (only `POST` registered; static path declared BEFORE `{id}/deliver` but `whereNumber` on the latter already prevents overlap) |
| Full path | `/api/v1/admin/shipments/bulk-deliver` (static segment under the `admin/shipments` prefix) |
| Path composition | `api/v1` prefix + `admin/shipments/bulk-deliver` from the `Route::prefix('admin/shipments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.shipments.bulk-deliver`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@bulkDeliver(BulkShipmentActionRequest)` → `ShipmentService::bulkDeliver(shipment_ids, notes?)` → 200 `['successful', 'failed', 'summary']`. NOTE: no `authorizeWarehouseScope` call exists on this bulk path (code-verified absence) — authorization is the route `update-shipment` permission ONLY |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `update-shipment` via route middleware (only gate) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json`, `Content-Type: application/json` |
| Supported request formats | JSON body (required) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.shipments.bulk-deliver` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422-validation → 200. Per-item misses/refusals live inside `data.failed` — they never become top-level 404/422-engine statuses (request-level 422 below still applies).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `shipment_ids` | body | array | Required | No | `required\|array\|min:1\|max:100` + `shipment_ids.*: integer\|min:1` | 1–100 shipment ids (de-duplicated server-side) | None | None | `[7, 8]` | Labels to deliver |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:500` | Free text | `null` | Passed to every `markDelivered()` | `Delivered to customers` | Delivery note |

Validation failure returns `422` with an `errors` map.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/shipments/bulk-deliver",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "shipment_ids": [7, 8],
    "notes": "Delivered to customers"
  }
}
```

# Responses

## Success: bulk result — HTTP 200 (fault-tolerant, check `summary`)

Returned via `ApiResponse::apiResponse(__('wms.shipment.delivered'), 200, true, $result)` (`en` → `"Shipment delivered successfully."`, `resources/lang/en/wms.php` shipment block). Status/summary verified by `test_bulk_dispatch_and_deliver` (2 ids → 200, `success_count: 2`; rows `delivered`, fulfillments `delivered`; uses `postJson`); per-item shapes code-traced. Pre-dispatch (`label_created`) rows are refused per-item (single-deliver requires `picked_up`/`in_transit`/`out_for_delivery`); re-delivery counts as `successful`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` |
| `message` | string | Always present | Translated `wms.shipment.delivered` | `en` → `"Shipment delivered successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` (per-item failures in `data.failed`) |
| `data.successful` | array | Always present | Delivered labels | `[{shipment_id, tracking_number, status (`delivered`), fulfillment_id}][]` |
| `data.failed` | array | Always present | Missing/refused rows | `[{shipment_id, tracking_number?, reason}][]` — missing: `"Shipment #id not found"`; refusal: live engine text (e.g. premature `deliver_state`) |
| `data.summary` | object | Always present | Counts over de-duplicated ids | `{total, success_count, failed_count}` |

```json
{
  "status": 200,
  "message": "Shipment delivered successfully.",
  "success": true,
  "data": {
    "successful": [
      {"shipment_id": 7, "tracking_number": null, "status": "delivered", "fulfillment_id": 11}
    ],
    "failed": [
      {"shipment_id": 9, "reason": "Shipment #9 not found"}
    ],
    "summary": {"total": 2, "success_count": 1, "failed_count": 1}
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Per-item outcomes are 200-payload — they never become top-level 404/422-engine statuses (request-level 422 below still applies).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `update-shipment` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 405 | Method other than `POST` | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | E.g. `GET` (code-traced) | See 405 example |
| 422 | Body validation fails | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` | Field → messages | `{}` / oversize `notes` (code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded (code-traced) | See 429 example |

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

422 example:

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "shipment_ids": ["The shipment ids field is required."]
  }
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

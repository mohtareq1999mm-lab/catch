# Endpoint Name

Fulfillments – Bulk Create Shipments (`POST /api/v1/admin/fulfillments/create-shipments`)

# Description

Creates one shipment label per ready fulfillment (fault-tolerant: created rows progress, ineligible/missing rows report per-item reasons). Always HTTP 200 — even when every item fails (check `summary`).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (only `POST` registered on this static path) |
| Full path | `/api/v1/admin/fulfillments/create-shipments` (static segment under the `admin/fulfillments` prefix) |
| Path composition | `api/v1` prefix + `admin/fulfillments/create-shipments` from the `Route::prefix('admin/fulfillments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.fulfillments.create-shipments`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@bulkCreateShipments(BulkCreateShipmentsRequest)` → `ShipmentService::bulkCreateForFulfillments(fulfillment_ids, {courier?, shipping_method?, notes?})` → 200 `['successful', 'failed', 'summary']`. NOTE: no `authorizeWarehouseScope` call exists on this bulk path (code-verified absence) — authorization is the route `create-shipment` permission ONLY |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `create-shipment` via route middleware (only gate — no object-level scope on this path) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json`, `Content-Type: application/json` |
| Supported request formats | JSON body (required) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.create-shipments` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422-validation → 200. Per-item misses/refusals live inside `data.failed` — they never become top-level 404/409/422-engine statuses (request-level 422 below still applies).

NOTE vs the single-create endpoint: bulk accepts NO `idempotency_key` and NO per-label `destination_address`; `courier`/`shipping_method`/`notes` apply to every created label. Retries are safe but counted per-item (a duplicate bulk call surfaces one-active-label contention as `failed` with 200) — always check `summary`.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `fulfillment_ids` | body | array | Required | No | `required\|array\|min:1\|max:100` + `fulfillment_ids.*: integer\|min:1` | 1–100 ids (de-duplicated server-side) | None | None | `[11, 12]` | Fulfillments to label |
| `courier` | body | string | Optional | Yes | `nullable\|string\|max:100` | Free text | `null` | Applied to every created label (`commonData`) | `Aramex` | Courier |
| `shipping_method` | body | string | Optional | Yes | `nullable\|string\|max:100` | Free text | `null` | Same as `courier` | `express` | Method |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:500` | Free text | `null` | Same as `courier` | `fragile` | Label notes |

Validation failure returns `422` with an `errors` map.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/create-shipments",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "fulfillment_ids": [11, 12, 13],
    "courier": "Aramex",
    "shipping_method": "express"
  }
}
```

# Responses

## Success: bulk result — HTTP 200 (fault-tolerant, check `summary`)

Returned via `ApiResponse::apiResponse(__('wms.shipment.created'), 200, true, $result)` (`en` → `"Shipment created successfully."`, `resources/lang/en/wms.php` shipment block). Status/summary verified by `test_bulk_create_shipments` (3 ids → 200, `summary: {total: 3, success_count: 2, failed_count: 1}`; non-`ready_to_ship` row refused; uses `postJson`); per-item key shapes code-traced against the service return (not asserted by the test).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` (bulk never 201, unlike the single-create 201) |
| `message` | string | Always present | Translated `wms.shipment.created` | `en` → `"Shipment created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` (per-item failures live in `data.failed`) |
| `data.successful` | array | Always present | Created labels | `[{fulfillment_id, fulfillment_number, shipment_id, tracking_number, status (`label_created`), courier}][]` |
| `data.failed` | array | Always present | Refused/missing rows | `[{fulfillment_id, fulfillment_number?, reason}][]` — missing: `"Fulfillment #id not found"`; refusal: live engine text (e.g. non-ready `create_status`, one-active-label contention) |
| `data.summary` | object | Always present | Counts over de-duplicated ids | `{total, success_count, failed_count}` |

```json
{
  "status": 200,
  "message": "Shipment created successfully.",
  "success": true,
  "data": {
    "successful": [
      {"fulfillment_id": 11, "fulfillment_number": "FF-000011", "shipment_id": 7, "tracking_number": null, "status": "label_created", "courier": "Aramex"}
    ],
    "failed": [
      {"fulfillment_id": 13, "fulfillment_number": "FF-000013", "reason": "Cannot create shipment: fulfillment #13 is picking, expected ready_to_ship"}
    ],
    "summary": {"total": 3, "success_count": 2, "failed_count": 1}
  }
}
```

(Figures illustrative; keys/shape verified against the service return.)

# Errors

No error-code field; every error below has Error code `None`. Per-item misses/refusals are 200-payload — they never become top-level 404/409/422-engine statuses (request-level 422 below still applies).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period — Handler:170) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `create-shipment` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 405 | Method other than `POST` | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | E.g. `GET` (code-traced) | See 405 example |
| 422 | Body validation fails | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` | Field → messages | `{}` / oversize strings (code-traced) | See 422 example |
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
    "fulfillment_ids": ["The fulfillment ids field is required."]
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

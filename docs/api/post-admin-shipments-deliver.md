# Endpoint Name

Shipments – Mark Delivered (`POST /api/v1/admin/shipments/{id}/deliver`)

# Description

Staff-confirmed delivery: walks `picked_up` → `in_transit` → `out_for_delivery` → `delivered` inside `ShipmentService` and converges the order lifecycle. Re-delivery is a safe 200 replay; deliver-before-dispatch refuses with 422.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/shipments/{id}/deliver` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/shipments/{id}/deliver` from the `Route::prefix('admin/shipments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.shipments.deliver`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@markDelivered(ShipmentNotesRequest, int $id)` → `Shipment::whereKey->firstOrFail` (miss → 404); `authorizeWarehouseScope(shipment→fulfillment warehouse, 'update-shipment')` with default `asNotFound: FALSE` (cross-warehouse → 403 `"This action is unauthorized."` — code-verified); `ShipmentService::markDelivered(id, notes)` (`RuntimeException` → 422) → 200 `ShipmentDetailResource` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `update-shipment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json`, `Content-Type: application/json` |
| Supported request formats | JSON body with optional `notes` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.shipments.deliver` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-engine → 200.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Shipment id manageable by the caller | None | None | `1` | Identifies the shipment |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:2000` (`ShipmentNotesRequest`) | Free text | `null` | None | `"left at door"` | Delivery note |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/shipments/1/deliver",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {}
}
```

# Responses

## Success: shipment delivered — HTTP 200 (re-delivery replays 200)

Returned via `ApiResponse::apiResponse(__('wms.shipment.delivered'), 200, true, ShipmentDetailResource::make($fresh))` (`en` → `"Shipment delivered successfully."`, verified in `resources/lang/en/wms.php:189`). Verified by `test_deliver_walks_chain_and_replays_safely` (200, `delivered` + `delivered_at` set + fulfillment `delivered`; re-deliver → 200; uses `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (fresh AND replay) |
| `message` | string | Always present | Translated `wms.shipment.delivered` | `en` → `"Shipment delivered successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `ShipmentDetailResource` | Same 24 keys as show; `status` is `"delivered"`, `delivered_at` set |

```json
{
  "status": 200,
  "message": "Shipment delivered successfully.",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "order_id": 42,
    "fulfillment_id": 11,
    "packing_task_id": null,
    "tracking_number": null,
    "courier": "Aramex",
    "status": "delivered",
    "shipping_method": "standard",
    "shipping_cost": null,
    "currency": null,
    "total_weight": null,
    "origin_address": null,
    "destination_address": null,
    "notes": null,
    "cancel_reason": null,
    "cancel_source": null,
    "cancelled_by": null,
    "shipped_at": "2026-10-14T10:20:00+00:00",
    "estimated_delivery_at": null,
    "delivered_at": "2026-10-14T10:40:00+00:00",
    "cancelled_at": null,
    "created_at": "2026-10-14T09:00:00+00:00",
    "updated_at": "2026-10-14T10:40:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period — Handler:170) | None | No valid token (verified by `test_unauthenticated_shipment_endpoints_return_401` on this URI) | See 401 example |
| 403 | Authenticated caller lacks `update-shipment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | `view-shipment`-only holder (verified → 403 on deliver in `test_shipment_commands_require_granular_permissions`) | See 403 example |
| 403 | Cross-warehouse shipment at the SCOPE layer (writes never mask as 404) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION` — code-verified) | None | Foreign shipment id (code-traced) | See 403-scope example |
| 404 | No shipment with `{id}` | `{message: string, status: boolean}` | None | `"Resource Not Found"` | None | Unknown numeric id (code-traced) | See 404 example |
| 422 | `notes` overlong | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` | `notes` → messages | Oversize body (code-traced) | See 422-validation example |
| 422 | Premature deliver (before dispatch — explicit progression only) | `{message: string, status: boolean}` (NO `errors` map, verbatim engine text) | None | Live engine text | None | Deliver on `label_created` row (verified → 422 in `test_deliver_walks_chain_and_replays_safely`) | See 422-engine example |
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

403-scope example:

```json
{
  "message": "This action is unauthorized.",
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

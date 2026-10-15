# Endpoint Name

Shipments – Dispatch Shipment (`POST /api/v1/admin/shipments/{id}/dispatch`)

# Description

Carrier handoff: `label_created` → `picked_up`, with the fulfillment `ready_to_ship` → `shipped` move inside `ShipmentService`. Duplicate dispatch refuses loudly (422) — never a silent no-op.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/shipments/{id}/dispatch` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/shipments/{id}/dispatch` from the `Route::prefix('admin/shipments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.shipments.dispatch`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@dispatchShipment(ShipmentNotesRequest, int $id)` → `Shipment::whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(shipment→fulfillment warehouse, 'update-shipment')` with default `asNotFound: FALSE` (cross-warehouse → 403 `"This action is unauthorized."` via `AuthorizationException` branch — code-verified; writes never mask as 404); `ShipmentService::dispatch(id, notes)` (`RuntimeException` → 422 via `unprocessable()`) → 200 `ShipmentDetailResource` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `update-shipment` (route pipe + scope check — BOTH the same permission; a `view-shipment`-only holder fails with 403) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json`, `Content-Type: application/json` (body is optional but JSON when present) |
| Supported request formats | JSON body with optional `notes` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.shipments.dispatch` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-engine → 200.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Shipment id manageable by the caller | None | None | `1` | Identifies the shipment |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:2000` (`ShipmentNotesRequest`) | Free text | `null` | None | `"handed to courier"` | Handoff note |

Validation failure returns `422` with an `errors` map.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/shipments/1/dispatch",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "notes": "handed to courier"
  }
}
```

# Responses

## Success: shipment dispatched — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.shipment.dispatched'), 200, true, ShipmentDetailResource::make($fresh))` (`en` → `"Shipment dispatched successfully."`, verified in `resources/lang/en/wms.php:188`). Verified by `test_dispatch_moves_shipment_and_fulfillment_once` (200, `picked_up` + fulfillment `shipped` proofs; uses `postJson`, matching this route).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.shipment.dispatched` | `en` → `"Shipment dispatched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `ShipmentDetailResource` | Same 24 keys as show; `status` is `"picked_up"`, `shipped_at` set |

```json
{
  "status": 200,
  "message": "Shipment dispatched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "order_id": 42,
    "fulfillment_id": 11,
    "packing_task_id": null,
    "tracking_number": null,
    "courier": "Aramex",
    "status": "picked_up",
    "shipping_method": "standard",
    "shipping_cost": null,
    "currency": null,
    "total_weight": null,
    "origin_address": null,
    "destination_address": null,
    "notes": "handed to courier",
    "cancel_reason": null,
    "cancel_source": null,
    "cancelled_by": null,
    "shipped_at": "2026-10-14T10:20:00+00:00",
    "estimated_delivery_at": null,
    "delivered_at": null,
    "cancelled_at": null,
    "created_at": "2026-10-14T09:00:00+00:00",
    "updated_at": "2026-10-14T10:20:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period — Handler:170, code-verified) | None | No valid token (verified by `test_unauthenticated_shipment_endpoints_return_401` on this URI) | See 401 example |
| 403 | Authenticated caller lacks `update-shipment` (route pipe, Spatie) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `"User does not have the required permissions."` | None | `view-shipment`-only holder (verified by `test_shipment_commands_require_granular_permissions` → 403 on dispatch) | See 403 example |
| 403 | Cross-warehouse shipment at the SCOPE layer (writes never mask as 404 — `asNotFound: FALSE`) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION` — Handler swallows the `Forbidden warehouse operation.` throw text; code-verified) | None | Foreign shipment id with otherwise valid permission (code-traced) | See 403-scope example |
| 404 | No shipment with `{id}` | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 422 | `notes` overlong (`max:2000`) | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` | `notes` → messages | Oversize body (code-traced) | See 422-validation example |
| 422 | Wrong-state dispatch (duplicate or non-`label_created` row — service refusal) | `{message: string, status: boolean}` (NO `errors` map — `unprocessable()`-thrown `HttpException`, verbatim engine text) | None | Live engine text (exact string service-dependent) | None | Second dispatch on the same row (verified → 422 in `test_dispatch_moves_shipment_and_fulfillment_once`, row stays `picked_up`) | See 422-engine example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded (code-traced) | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (missing permission):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403-scope example (cross-warehouse write — distinct from read masking):

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

422-engine example (duplicate dispatch — illustrative text):

```json
{
  "message": "Shipment 1 cannot dispatch from 'picked_up': expected label_created",
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

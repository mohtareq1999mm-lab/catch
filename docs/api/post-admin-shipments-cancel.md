# Endpoint Name

Shipments – Cancel Shipment (`POST /api/v1/admin/shipments/{id}/cancel`)

# Description

Canonical shipment cancellation. Terminal states refuse (422); a shipped/delivered fulfillment refuses — cancellation never moves fulfillment backward. The row is retained with reason/source/actor audit.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/shipments/{id}/cancel` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/shipments/{id}/cancel` from the `Route::prefix('admin/shipments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.shipments.cancel`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@cancelShipment(BatchCommandRequest, int $id)` → `Shipment::whereKey->firstOrFail` (miss → 404); `authorizeWarehouseScope(shipment→fulfillment warehouse, 'update-shipment')` with default `asNotFound: FALSE` (cross-warehouse → 403 `"This action is unauthorized."` — code-verified); `ShipmentService::cancelShipment(id, reason, {cancel_source: 'admin_api', cancelled_by: actorId})` (`InvalidArgumentException`/`RuntimeException` → 422) → 200 `ShipmentDetailResource`. Actor fields are server-derived — caller-supplied `cancelled_by`/`cancel_source` are ignored (code-verified: not in `BatchCommandRequest` rules). |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `update-shipment` (route pipe + scope check) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json`, `Content-Type: application/json` |
| Supported request formats | JSON body with required `reason` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.shipments.cancel` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-engine → 200.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key and the audit `cancelled_by`) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Shipment id manageable by the caller | None | None | `1` | Identifies the shipment |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` (`BatchCommandRequest`) | Free text | None | None | `"wrong address"` | Cancellation reason (stored as `cancel_reason`) |

Validation failure returns `422` with an `errors` map. `reason` is required — `{}` or `''` fails validation (code-verified via `BatchCommandRequest`); any non-empty string passes validation and reaches the service.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/shipments/1/cancel",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "reason": "wrong address"
  }
}
```

# Responses

## Success: shipment cancelled — HTTP 200 (audited, row retained)

Returned via `ApiResponse::apiResponse(__('wms.shipment.cancelled'), 200, true, ShipmentDetailResource::make($fresh))` (`en` → `"Shipment cancelled successfully."`, verified in `resources/lang/en/wms.php:190`). Verified by `test_cancel_shipment_records_audit_and_never_moves_backward` (`cancelled` + `cancel_reason: 'wrong address'` + `cancel_source: 'admin_api'` + `cancelled_by: actor` + `cancelled_at` set; uses `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.shipment.cancelled` | `en` → `"Shipment cancelled successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `ShipmentDetailResource` | Same 24 keys as show; `status` is `"cancelled"`, `cancel_reason`/`cancel_source`/`cancelled_by`/`cancelled_at` set; fulfillment status untouched (still `ready_to_ship` when cancelled pre-dispatch) |

```json
{
  "status": 200,
  "message": "Shipment cancelled successfully.",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "order_id": 42,
    "fulfillment_id": 11,
    "packing_task_id": null,
    "tracking_number": null,
    "courier": "Aramex",
    "status": "cancelled",
    "shipping_method": "standard",
    "shipping_cost": null,
    "currency": null,
    "total_weight": null,
    "origin_address": null,
    "destination_address": null,
    "notes": null,
    "cancel_reason": "wrong address",
    "cancel_source": "admin_api",
    "cancelled_by": 7,
    "shipped_at": null,
    "estimated_delivery_at": null,
    "delivered_at": null,
    "cancelled_at": "2026-10-14T10:25:00+00:00",
    "created_at": "2026-10-14T09:00:00+00:00",
    "updated_at": "2026-10-14T10:25:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period — Handler:170) | None | No valid token (verified by `test_unauthenticated_shipment_endpoints_return_401` on this URI) | See 401 example |
| 403 | Authenticated caller lacks `update-shipment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | `view-shipment`-only holder (verified → 403 on cancel with `{'reason': 'x'}`) | See 403 example |
| 403 | Cross-warehouse shipment at the SCOPE layer (writes never mask as 404) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION` — code-verified) | None | Foreign shipment id (code-traced) | See 403-scope example |
| 404 | No shipment with `{id}` | `{message: string, status: boolean}` | None | `"Resource Not Found"` | None | Unknown numeric id (code-traced) | See 404 example |
| 422 | Missing/empty `reason` | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` | `reason` → messages | `{}` or `{'reason': ''}` (code-traced; `BatchCommandRequest` requires it) | See 422-validation example |
| 422 | Terminal cancel (already `cancelled`) or post-dispatch cancel (fulfillment `shipped` — never backward) | `{message: string, status: boolean}` (NO `errors` map, verbatim engine text) | None | Live engine text | None | Second cancel on same row (verified → 422, fulfillment stays `ready_to_ship`); cancel after dispatch (verified → 422, shipment stays `picked_up`, fulfillment stays `shipped`) | See 422-engine example |
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

422-validation example:

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "reason": ["The reason field is required."]
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

# Endpoint Name

Shipments – Show Shipment (`GET /api/v1/admin/shipments/{id}`)

# Description

Returns one fulfillment-linked shipment with full detail inside the caller's warehouse scope (cross-warehouse reads 404; order-only labels with null `fulfillment_id` 404).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/shipments/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/shipments/{id}` from the `Route::prefix('admin/shipments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.shipments.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@show(int $id)` → `Shipment::whereKey->firstOrFail` (miss → 404 fixed; nothing precedes the lookup — code-verified); `shipmentWarehouseId()` resolves `fulfillment.warehouse_id` via `Fulfillment::whereKey(fulfillment_id)->firstOrFail` (fail-closed: null-fulfillment rows 404 here — code-verified); `authorizeWarehouseScope(warehouse_id, 'view-shipment', asNotFound: TRUE)` (cross-warehouse → 404 anti-enumeration, NEVER 403 — code-verified) → 200 `ShipmentDetailResource`. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-shipment` OR `view-all-shipments` on the route pipe (`permission:view-shipment\|view-all-shipments` — the new-flow diff; `route:list` verified). In-controller: the same read, scope-checked as `view-shipment` — `WarehouseAccess::allows()` substitutes `view-all-shipments` for `view-shipment` across ALL warehouses, so an `view-all-shipments`-only auditor (no home warehouse, no role) reads a single foreign-warehouse shipment with 200 (code-verified + verified by `test_user_with_view_all_shipments_permission_can_view_all_shipments_without_role` step 2) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.shipments.show` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 404-lookup → 404-scope (incl. null-fulfillment fail-closed) → 200. No validation, no `422`. `view-all-shipments` holders skip the 404-scope layer entirely (WarehouseAccess substitute grant).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Shipment id visible to the caller | None | None | `1` | Identifies the shipment |

No query parameters, body fields, or validation-driven `422`.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/shipments/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: shipment detail — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.shipment.fetched_one'), 200, true, ShipmentDetailResource::make($shipment))` — translated at the call site (`en` → `"Shipment fetched successfully."`, verified in `resources/lang/en/wms.php` under `wms.shipment.fetched_one`). Verified by `test_shipment_reads_scope_to_home_warehouse` (200 via `getJson`) and by `test_user_with_view_all_shipments_permission_can_view_all_shipments_without_role` step 2 (view-all-shipments-only auditor reads a foreign-warehouse shipment → 200).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.shipment.fetched_one` | `en` → `"Shipment fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `ShipmentDetailResource` (24 keys) | `id`, `uuid`, `order_id`, `fulfillment_id`, `packing_task_id`, `tracking_number`, `courier`, `status` (`label_created\|picked_up\|in_transit\|out_for_delivery\|delivered\|cancelled\|…` per `Shipment::allowedTransitions`), `shipping_method`, `shipping_cost` (float/null), `currency`, `total_weight` (float/null), `origin_address`, `destination_address`, `notes`, `cancel_reason`, `cancel_source`, `cancelled_by`, `shipped_at`, `estimated_delivery_at`, `delivered_at`, `cancelled_at`, `created_at`, `updated_at` (ISO/null) |

```json
{
  "status": 200,
  "message": "Shipment fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "order_id": 42,
    "fulfillment_id": 11,
    "packing_task_id": null,
    "tracking_number": null,
    "courier": "Aramex",
    "status": "label_created",
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
    "shipped_at": null,
    "estimated_delivery_at": null,
    "delivered_at": null,
    "cancelled_at": null,
    "created_at": "2026-10-14T09:00:00+00:00",
    "updated_at": "2026-10-14T09:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (verified by `test_unauthenticated_shipment_endpoints_return_401` on this URI) | See 401 example |
| 403 | Authenticated caller lacks BOTH `view-shipment` and `view-all-shipments` (route pipe, Spatie) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `"User does not have the required permissions."` | None | Valid token without either read permission (pattern-verified on sibling WMS reads) | See 403 example |
| 404 | No shipment with `{id}` (lookup miss — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Cross-warehouse shipment (scope anti-enumeration — reads NEVER 403 at the scope layer; `view-shipment` holders need the home/assignment match) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign shipment id for a `view-shipment`-only caller (verified by `test_shipment_reads_scope_to_home_warehouse` → 404) | See 404-scope example |
| 404 | Order-only label (`fulfillment_id` null — outside the WMS surface) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; fail-closed `Fulfillment::whereKey(0)->firstOrFail` — code-verified) | None | Legacy order-only row (verified → 404 in the same test) | See 404-legacy example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape — different text, code-verified) | None | Non-numeric segment | See 404-constraint example |
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

404 example (miss, masked read, and legacy label — identical):

```json
{
  "message": "Resource Not Found",
  "status": false
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

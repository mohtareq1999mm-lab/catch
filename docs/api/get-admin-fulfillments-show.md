# Endpoint Name

Fulfillments – Show Fulfillment (`GET /api/v1/admin/fulfillments/{id}`)

# Description

Returns one fulfillment by numeric id with the bounded detail contract and masked foreign reads.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/fulfillments/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/fulfillments/{id}` from the `Route::prefix('admin/fulfillments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.fulfillments.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\FulfillmentController@show(int $id)` → `whereKey->firstOrFail` (miss escapes → 404 fixed); then `authorizeWarehouseScope(warehouse_id, 'view-fulfillment', asNotFound: TRUE)` — scoped denial converts to `NotFoundHttpException('Resource Not Found')` → SAME fixed text (code-verified anti-enumeration twin of warehouse/location show); then the bounded `loadDetail` assembly (six relations + two manual id-ordered sets — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` via the route pipe, PLUS home-scope (global `manage-warehouse` bypasses scope ONLY for that exact permission string — a `manage-warehouse`-only holder still fails THIS pipe; code-verified from the access helper + pipe) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Fulfillment id visible to the caller | None | None | `1` | Identifies the fulfillment |

No query parameters, body fields, or `422` contract.

## Request examples

Valid request showing a fulfillment (test-compatible call):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/fulfillments/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: bounded detail returned — HTTP 200 (contract-pinned keys AND absences)

Returned via `ApiResponse::apiResponse('Fulfillment fetched successfully.', 200, true, FulfillmentDetailResource::make($detail))` (literal message). Verified by `test_show_returns_bounded_detail_contract` (200 + `success: true`, TEN required keys, TWO asserted absences, single-item proof, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Fulfillment fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `FulfillmentDetailResource` (keys AND absences test-pinned) | PRESENT: `id`, `fulfillment_number`, `order_id`, `order{id, order_number, status}`, `warehouse_id`, `warehouse{id, code, name, status}`, `status`, `priority`, `assigned_to`, `assigned_user{id, name}`/null, `items[{id, order_item_id, product_id, product_variant_id, product_location_id, quantity, quantity_picked, status}]`, `picking_tasks[{id, fulfillment_item_id, batch_id, status, claimed_by, quantity_to_pick, quantity_picked}]`, `packing_tasks[{id, status, packing_station_id, assigned_to}]`, `packages[{id, packing_task_id, status, package_number}]`, `shipments[]` (shipment resources), `cancelled_by`, `cancel_source`, `notes`, eight `*_at` timestamps (ISO/null), `created_at`, `updated_at`. ABSENT by design: `allowed_actions` (P9-9 owns that contract), `metadata` |

```json
{
  "status": 200,
  "message": "Fulfillment fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "fulfillment_number": "FF-000001",
    "order_id": 42,
    "order": { "id": 42, "order_number": "ORD-00000042", "status": "processing" },
    "warehouse_id": 1,
    "warehouse": { "id": 1, "code": "WH-A", "name": "Alpha", "status": "active" },
    "status": "pending",
    "priority": null,
    "assigned_to": null,
    "assigned_user": null,
    "items": [],
    "picking_tasks": [],
    "packing_tasks": [],
    "packages": [],
    "shipments": [],
    "cancelled_by": null,
    "cancel_source": null,
    "notes": null,
    "picking_started_at": null,
    "picking_completed_at": null,
    "packing_started_at": null,
    "packing_completed_at": null,
    "ready_to_ship_at": null,
    "shipped_at": null,
    "delivered_at": null,
    "cancelled_at": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the byte-identical 404s (anti-enumeration by design).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (verified by the 401 matrix on show) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Homed permissionless user (verified 403 on show) | See 403 example |
| 404 | No visible fulfillment with `{id}` — genuine miss, OR cross-warehouse read masked by anti-enumeration (byte-identical: fixed string, Handler shape) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown id (verified by `test_show_missing_returns_404` for `999999`); foreign id (verified by `test_show_cross_warehouse_returns_404`) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape — different text, code-verified) | None | Non-numeric segment | See 404-constraint example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
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

404 example (miss OR masked read — identical):

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

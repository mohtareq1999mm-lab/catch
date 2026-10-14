# Endpoint Name

Picking Tasks – Show Task (`GET /api/v1/admin/picking-tasks/{id}`)

# Description

Returns one picking task with fulfillment and shelf-identity detail inside the caller's warehouse scope (cross-warehouse reads 404), returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/picking-tasks/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}` custom `Route::get(...)->name('api.admin.picking-tasks.show')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@show(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; nothing precedes the lookup — code-verified); `authorizeWarehouseScope(task→item→fulfillment warehouse, 'picking-execute', asNotFound: TRUE)` (cross-warehouse → 404 anti-enumeration, NEVER 403 — code-verified; test-authored); `loadMissing([fulfillmentItem.fulfillment, productLocation.location])` → 200 `PickingTaskDetailResource`. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.picking-tasks.show` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps — only `index`/`claim`/`confirm` are name-pinned in `WmsRbacFoundationTest`) |

Order matters: 401 → 403-route → 404-lookup → 404-scope → 200. No 403-scope exists on this read (anti-enumeration); no validation, no 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Task id manageable by the caller | None | None | `1` | Identifies the picking task |

No query parameters, body fields, or validation-driven `422`.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/picking-tasks/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: task detail — HTTP 200 (shelf identity included, no `allowed_actions`)

Returned via `ApiResponse::apiResponse(__('wms.picking.fetched_one'), 200, true, PickingTaskDetailResource::make(...))` (translated message — code-verified). The `location` block carries shelf identity (`code`/`name`/`barcode`/`type`/`status` plus legacy `id`/`location_id`/`warehouse_id` keys — test-authored, additive-only). No `allowed_actions` key exists (test-authored absence). `fulfillment` is OMITTED when the item/fulfillment chain is missing (`when` guard — code-verified); `location` is `null` when the task has no placement.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.picking.fetched_one` | `en` → `"Picking task fetched successfully."` (verified in `resources/lang/en/wms.php:148`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Full detail (list keys + below) | All `PickingTaskResource` keys (see index doc) plus: |
| `data.fulfillment` | object | Present when the item→fulfillment chain resolves | Owning fulfillment | `{id, fulfillment_number, warehouse_id, status}` |
| `data.location` | object/null | Present when `productLocation` is loaded (`null` when the task has no placement) | Shelf identity | `{id (placement id), location_id, warehouse_id, code, name, barcode, type, status}` — test-authored |
| `data.op_seq` | integer | Always present | Replay watermark | `0` when never confirmed |
| `data.scan_log` | array | Always present | Staff-facing scan audit | `[]` when empty (test-authored presence) |
| `data.notes` | string/null | Always present | Operator notes | e.g. skip reason |

```json
{
  "status": 200,
  "message": "Picking task fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "assigned",
    "quantity_to_pick": "2.00",
    "quantity_picked": "0.00",
    "remaining": 2,
    "claimed_by": 7,
    "fulfillment": {
      "id": 3,
      "fulfillment_number": "FUL-1A2B3C",
      "warehouse_id": 1,
      "status": "picking"
    },
    "location": {
      "id": 5,
      "location_id": 2,
      "warehouse_id": 1,
      "code": "SHELF-9",
      "name": "Aisle 9 Bin",
      "barcode": "SHELF-9-BC",
      "type": "storage",
      "status": "active"
    },
    "op_seq": 0,
    "scan_log": [],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | E.g. `view-fulfillment`-only viewer (test-authored 403 on this URI) | See 403 example |
| 404 | No task with `{id}` (lookup miss — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Cross-warehouse task (scope anti-enumeration — reads NEVER 403 at the scope layer, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign task id (test-authored 404 + detail-contract proof on the home task) | See 404-scope example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (`translateHttpMessage` returns the raw route-miss text verbatim; `"Not Found"` only when the exception message is empty — code-verified, literal NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

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

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-scope example (indistinguishable from a miss — by design):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-constraint example (ILLUSTRATIVE — NOT live-verified):

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

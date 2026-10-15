# Endpoint Name

Packing Tasks – Show Task (`GET /api/v1/admin/packing-tasks/{id}`)

# Description

Returns one packing task with station detail inside the caller's warehouse scope (cross-warehouse reads 404), returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/packing-tasks/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packing-tasks/{id}` custom `Route::get(...)->name('api.admin.packing-tasks.show')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingController@show(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; nothing precedes the lookup — code-verified); `authorizeWarehouseScope(task→fulfillment warehouse, 'view-fulfillment', asNotFound: TRUE)` (cross-warehouse → 404 anti-enumeration, NEVER 403 — code-verified; null hop fails closed to deny — code-verified); `loadMissing(packingStation:id,code,name,warehouse_id, fulfillment.items.product, fulfillment.items.productVariant.attributeProducts.attributeValue.attribute, fulfillment.items.productLocation.location)` → 200 `PackingTaskDetailResource`. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packing-tasks.show` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-lookup → 404-scope → 200. No 403-scope exists on this read (anti-enumeration); no validation, no 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Task id manageable by the caller | None | None | `1` | Identifies the packing task |

No query parameters, body fields, or validation-driven `422`.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/packing-tasks/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: task detail — HTTP 200 (station + items included)

Returned via `ApiResponse::apiResponse(__('wms.packing.task_fetched'), 200, true, PackingTaskDetailResource::make(...))` — translated at the call site (`en` → `"Packing task fetched successfully."`, verified in `resources/lang/en/wms.php:94`). The `station` block appears only when the relation is loaded (controller always loads it — code-verified); it carries NO station `status` (code-verified absence — `{id, code, name, warehouse_id}` only). The `items` block resolves via `fulfillment.items` (controller always loads `fulfillment.items.product`, `productVariant.*`, `productLocation.location` — code-verified) and is enriched with `product`/`variant`/`location` (working-tree addition).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.task_fetched` | `en` → `"Packing task fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Full detail (list keys + below) | All `PackingTaskResource` keys (see index doc) plus: |
| `data.station` | object | Present when loaded (always here) | Assigned station | `{id, code, name, warehouse_id}` — no `status` key |
| `data.dimensions` | array/null | Always present | Pack dimensions | Set at pack time |
| `data.package_materials` | array/null | Always present | Materials used | Set at pack time |
| `data.notes` | string/null | Always present | Operator notes | e.g. cancel/verify reason |
| `data.items` | array/null | Present when `fulfillment` loaded (always here — controller loads `fulfillment.items.*`); null when inner `items` not loaded, key absent when `fulfillment` itself not loaded (resource gates on `whenLoaded('fulfillment')`) | Fulfillment lines for this task | `[{id, order_item_id, product_id, product_variant_id, product_location_id, quantity (float), quantity_picked (float), status, product{id, name, sku, product_type, thumbnail}/null, variant{id, sku, price, stock_quantity, attributes[]}/null, location{id, code, name, barcode}/null}]` (working-tree addition via `FormatsWmsProductVariant`; `location.id` is the `productLocation` pivot id, `code`/`name`/`barcode` come from the nested warehouse `location`) |
| `data.assigned_at` | string/null | Always present | Assignment time | ISO-8601 |
| `data.started_at` | string/null | Always present | Start time | ISO-8601 |

```json
{
  "status": 200,
  "message": "Packing task fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "fulfillment_id": 11,
    "packing_station_id": 2,
    "station": {
      "id": 2,
      "code": "ST-A1",
      "name": "Station A1",
      "warehouse_id": 1
    },
    "assigned_to": 7,
    "status": "packing",
    "weight": null,
    "dimensions": null,
    "package_materials": null,
    "notes": null,
    "items": [
      {
        "id": 3,
        "order_item_id": 5,
        "product_id": 10,
        "product_variant_id": null,
        "product_location_id": 3,
        "quantity": 2.0,
        "quantity_picked": 2.0,
        "status": "picked",
        "product": {"id": 10, "name": "FP", "sku": "SKU-FUL-1", "product_type": "simple", "thumbnail": null},
        "variant": null,
        "location": {"id": 3, "code": "FA-01", "name": "Bin", "barcode": "FA-LOC-01"}
      }
    ],
    "assigned_at": "2026-10-14T10:00:00+00:00",
    "started_at": "2026-10-14T10:05:00+00:00",
    "packed_at": null,
    "verified_at": null,
    "created_at": "2026-10-14T09:00:00+00:00",
    "updated_at": "2026-10-14T10:05:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No task with `{id}` (lookup miss — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Cross-warehouse task (scope anti-enumeration — reads NEVER 403 at the scope layer, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign task id (test-authored 404) | See 404-scope example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
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

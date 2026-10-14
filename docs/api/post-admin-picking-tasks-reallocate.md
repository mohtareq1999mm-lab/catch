# Endpoint Name

Picking Tasks – Reallocate Task (`POST /api/v1/admin/picking-tasks/{id}/reallocate`)

# Description

Moves one non-terminal task's pinned placement to another hint in the SAME warehouse (same product + variant, active/placeable target), returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/reallocate` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/reallocate` custom `Route::post(...)->name('api.admin.picking-tasks.reallocate')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@reallocate(ReallocateTaskRequest $request, int $id)` → validated target id → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→item→fulfillment warehouse, 'picking-execute', asNotFound: FALSE)`; then `PickingExecutionService::reallocateTask(task, product_location_id)` — `RuntimeException` → 422 (code-verified). QUIRK (code-verified): the target/location `firstOrFail()` lookups live INSIDE the service try whose `catch (RuntimeException)` swallows the `ModelNotFoundException` (`ModelNotFound extends RecordsNotFound extends RuntimeException`) — a target vanishing post-validation surfaces as 422 with the framework's no-query-results text (same class as the packing-assign station quirk), NOT 404. Service guards, all under lock in order (code-verified): terminal (`picked/skipped/cancelled`) → 422 `reallocate_status`; target product ≠ item product → 422 `reallocate_product`; variant mismatch (one SKU per bin — sibling-variant bins hold other goods, code-commented) → 422 `reallocate_variant`; target warehouse ≠ fulfillment warehouse → 422 `reallocate_warehouse`; target not active/placeable (active + null-or-placeable type, mirrors `scopePlaceable`) → 422 `target_inactive`; placement/location warehouse drift → 422 `placement_warehouse_mismatch`; else ONLY `product_location_id` is rewritten (code-verified — nothing else mutates). No override parameter exists on this action (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe + scope check |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.picking-tasks.reallocate` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps — only `index`/`claim`/`confirm` are name-pinned in `WmsRbacFoundationTest`) |

Order matters: 401 → 403-route → 422-validation → 404-task → 403-scope → 422-terminal → 422-target-miss (quirk — inside the try, NOT 404) → 422-target-guards → 200.

## Request inputs

`ReallocateTaskRequest` runs before the action (`authorize: true`). Unknown `product_location_id` fails at VALIDATION (`exists` rule → 422 with `errors` map); a target vanishing afterwards surfaces as 422 via the in-service quirk above (NOT 404 — code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Non-terminal task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `product_location_id` | body | integer | Required | No | `required\|integer\|exists:product_locations,id` (unknown ids 422 HERE at validation) | Same-warehouse placement id holding the same product + variant at an active/placeable location | None | None | `9` | The new pinned placement (test-authored move + foreign-target refusal) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/reallocate",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "product_location_id": 9
  }
}
```

# Responses

## Success: task reallocated — HTTP 200 (DETAIL resource, placement rewritten)

Returned via `ApiResponse::apiResponse(__('wms.picking.reallocated'), 200, true, PickingTaskDetailResource…)` — translated at the call site (`en` → `"Picking task reallocated successfully."`, verified in `resources/lang/en/wms.php:155`). Test-authored: 200 + `product_location_id` provably rewritten.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal text | Always `"Picking task reallocated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (new `product_location_id`) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Picking task reallocated successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "pending",
    "product_location_id": 9,
    "op_seq": 0,
    "scan_log": [],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); target-guard refusals use the EXCEPTION envelope `{message, status: false}` (code-verified). All guard texts live under `wms.picking.*` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse reallocate (code-traced) | See 403-scope example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 422 | Target placement/location vanished post-validation (in-service quirk: the lookups live INSIDE the try whose `catch (RuntimeException)` reframes the miss as 422 — unknown ids 422 at validation instead, so this fires only on TOCTOU races; code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Framework no-query-results text, verbatim passthrough (shape code-verified, exact literal NOT asserted) | None | Raced target miss (code-traced) | See 422-target-miss example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure (missing/non-integer/unknown `product_location_id`) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Unknown placement id (code-traced) | See 422-validation example |
| 422 | Terminal task (`picked/skipped/cancelled`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.reallocate_status`: `en` → `"Cannot reallocate task #1 in status picked"` (ids illustrative; verified in `resources/lang/en/wms.php:163`) | None | Terminal task (code-traced) | See 422-status example |
| 422 | Target holds a different product | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.reallocate_product`: `en` → `"Reallocation target holds a different product"` (verified in `resources/lang/en/wms.php:164`) | None | Wrong-product target (code-traced) | See 422-product example |
| 422 | Target holds a different variant | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.reallocate_variant`: `en` → `"Reallocation target holds a different variant"` (verified in `resources/lang/en/wms.php:165`) | None | Sibling-variant target (code-traced) | See 422-variant example |
| 422 | Target in another warehouse | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.reallocate_warehouse`: `en` → `"Reallocation must stay in the same warehouse"` (verified in `resources/lang/en/wms.php:166`) | None | Cross-warehouse target (test-authored 422 with the original placement provably retained) | See 422-warehouse example |
| 422 | Target location inactive/non-placeable | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.target_inactive` WITH location id: `en` → `"Target location #2 is not active/placeable"` (ids illustrative; verified in `resources/lang/en/wms.php:167`) | None | Deactivated target (code-traced) | See 422-target example |
| 422 | Placement/location warehouse drift | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.placement_warehouse_mismatch`: `en` → `"Product placement warehouse does not match its location warehouse"` (verified in `resources/lang/en/wms.php:168`) | None | Drifted placement (code-traced) | See 422-mismatch example |
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

403-scope example (catalogue text, code-verified):

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

422-target-miss example (framework text reframed as 422 — shape code-verified, exact literal NOT asserted):

```json
{
  "message": "No query results for model [App\\Models\\Fulfillment\\ProductLocation] 999999",
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

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "product_location_id": ["The selected product location id is invalid."]
  }
}
```

422-status example (translated, no map):

```json
{
  "message": "Cannot reallocate task #1 in status picked",
  "status": false
}
```

422-product example (translated, no map):

```json
{
  "message": "Reallocation target holds a different product",
  "status": false
}
```

422-variant example (translated, no map):

```json
{
  "message": "Reallocation target holds a different variant",
  "status": false
}
```

422-warehouse example (translated, no map):

```json
{
  "message": "Reallocation must stay in the same warehouse",
  "status": false
}
```

422-target example (translated WITH location id, no map):

```json
{
  "message": "Target location #2 is not active/placeable",
  "status": false
}
```

422-mismatch example (translated, no map):

```json
{
  "message": "Product placement warehouse does not match its location warehouse",
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

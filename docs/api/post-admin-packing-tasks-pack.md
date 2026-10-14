# Endpoint Name

Packing Tasks – Complete Packing (`POST /api/v1/admin/packing-tasks/{id}/pack`)

# Description

Records packing completion with weight/dimensions (supervisor-gated; double completion refused without overwrite), returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packing-tasks/{id}/pack` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packing-tasks/{id}/pack` custom `Route::post(...)->name('api.admin.packing-tasks.pack')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:packing.complete` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingController@pack(CompletePackingRequest $request, int $id)` → validated payload → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→fulfillment warehouse, 'packing.complete', asNotFound: FALSE)`; then `PackingService::completePacking(task, weight, dimensions, materials, notes)` — `RuntimeException` → 422 (code-verified). Completion is supervisor-gated: the packer executes, a supervisor records completion (controller-commented). Service, locked fresh state (code-verified): status MUST be exactly `packing` (second completion sees `packed` and is refused — no last-writer-wins on weight/dimensions, code-commented); else `packed` + weight/dimensions/materials/`packed_at`/notes. The FULFILLMENT stays `packing` until verification — `packed` is task-level state only (service-commented, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `packing.complete` via the route pipe + scope check (a `packing-execute`-only packer gets 403 here — test-authored permission split) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packing-tasks.pack` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-state → 200.

## Request inputs

`CompletePackingRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the supervisor |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be `packing` for success | Packing-state task id manageable by the caller | None | None | `1` | Identifies the packing task |
| `weight` | body | number | Required | No | `required\|numeric\|gt:0` | `> 0` | None | None | `2.5` | Packed weight (test-authored fixture) |
| `dimensions` | body | object | Required | No | `required\|array\|min:1`, items `numeric` | Non-empty map of numerics | None | None | `{"length": 30, "width": 20, "height": 10}` | Pack dimensions (test-authored fixture) |
| `materials` | body | array | Optional | Yes | `nullable\|array` | Any array or null | `null` | Stored as `package_materials` | `["box", "tape"]` | Materials used |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:2000` | Any string or null | `null` | Stored on the task | `"fragile"` | Operator note |

## Request examples

Test-authored call shape:

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packing-tasks/1/pack",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "weight": 2.5,
    "dimensions": {"length": 30, "width": 20, "height": 10}
  }
}
```

# Responses

## Success: packing completed — HTTP 200 (DETAIL resource, fulfillment untouched)

Returned via `ApiResponse::apiResponse(__('wms.packing.completed'), 200, true, PackingTaskDetailResource…)` — translated at the call site (`en` → `"Packing completed successfully."`, verified in `resources/lang/en/wms.php:98`). Test-authored: 200 + `packed`; order/inventory/shipment rows provably untouched by packing commands.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.completed` | `en` → `"Packing completed successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackingTaskDetailResource` (`status` = `packed`, weight/dims persisted) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Packing completed successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "packed",
    "weight": 2.5,
    "dimensions": {"length": 30, "width": 20, "height": 10},
    "package_materials": null,
    "packed_at": "2026-10-14T10:10:00+00:00",
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); the state refusal uses the EXCEPTION envelope `{message, status: false}` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `packing.complete` (route pipe — the packer/executor split) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | `packing-execute`-only packer (test-authored 403 with state provably still `packing`) | See 403 example |
| 403 | Out-of-scope `packing.complete` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse pack (code-traced) | See 403-scope example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing/non-numeric/`≤ 0` weight, missing/empty dimensions (code-traced) | See 422-validation example |
| 422 | Wrong state (anything but `packing` — double completion refused, original weight provably survives) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.complete_status`: `en` → `"Cannot complete packing task in status: packed"` (status illustrative; verified in `resources/lang/en/wms.php:115`) | None | Second completion (test-authored 422 with weight provably still `2.5`) | See 422-state example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (missing permission — the packer split):

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
    "weight": ["The weight must be greater than 0."]
  }
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot complete packing task in status: packed",
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

# Endpoint Name

Batches – Show Batch (`GET /api/v1/admin/batches/{id}`)

# Description

Returns one batch with its sequence-ordered tasks inside the caller's warehouse scope (cross-warehouse reads 404), returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/batches/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}` custom `Route::get(...)->name('api.admin.batches.show')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@show(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; nothing precedes the lookup — code-verified); `authorizeWarehouseScope(batch.warehouse_id, 'view-fulfillment', asNotFound: TRUE)` (cross-warehouse → 404 anti-enumeration, NEVER 403 — code-verified; test-authored foreign → 404); tasks eager-loaded ordered by `sequence` → 200 `BatchDetailResource`. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.show` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-lookup → 404-scope → 200. No 403-scope exists on this read (anti-enumeration); no validation, no 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Batch id manageable by the caller | None | None | `2` | Identifies the batch |

No query parameters, body fields, or validation-driven `422`.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/batches/2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: batch detail — HTTP 200 (tasks by sequence, no `allowed_actions`)

Returned via `ApiResponse::apiResponse(__('wms.batch.fetched_one'), 200, true, BatchDetailResource::make(...))` — translated at the call site (`en` → `"Batch fetched successfully."`, verified in `resources/lang/en/wms.php:20`). Test-authored: `tasks` + `progress` present, `allowed_actions` absent, tasks non-empty.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.fetched_one` | `en` → `"Batch fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Full detail (list keys + below) | All `BatchResource` keys (see index doc) plus: |
| `data.tasks` | array | Always present here | Tasks ordered by `sequence` | `PickingTaskResource[]` (controller eager-loads — code-verified) |
| `data.notes` | string/null | Always present | Batch notes | — |

```json
{
  "status": 200,
  "message": "Batch fetched successfully.",
  "success": true,
  "data": {
    "id": 2,
    "batch_number": "BATCH-20261014-ABC123",
    "warehouse_id": 1,
    "status": "picking",
    "type": "wave",
    "assigned_to": 7,
    "total_items": 2,
    "picked_items": 1,
    "progress": 50,
    "tasks": [
      {"id": 5, "status": "picked", "sequence": 1},
      {"id": 6, "status": "pending", "sequence": 2}
    ],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on batch reads) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No batch with `{id}` (lookup miss — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Cross-warehouse batch (scope anti-enumeration — reads NEVER 403 at the scope layer, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign batch id (test-authored 404) | See 404-scope example |
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

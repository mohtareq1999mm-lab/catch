# Endpoint Name

Batches – Complete Batch (`POST /api/v1/admin/batches/{id}/complete`)

# Description

Strictly completes one batch (rejects while work remains incomplete; completed batches are idempotent no-ops), returning 200 with batch detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/batches/{id}/complete` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}/complete` custom `Route::post(...)->name('api.admin.batches.complete')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:batch.operate` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@complete(int $id)` — NO body, NO request class (plain `int $id`); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(batch.warehouse_id, 'batch.operate', asNotFound: FALSE)`; then `BatchPickingService::completeBatch(batch)` — `RuntimeException` → 422 (code-verified). Service, one transaction (code-verified): ALREADY `completed` → returned untouched (idempotent no-op, stable timestamp kept); `cancelled` → 422 `complete_status`; outside `assigned/picking` → 422 `complete_status`; `picked_items` recounted from task state (never trusted from the client); still incomplete → 422 `incomplete` with picked/total counts; else `completed` + `completed_at` + fully-picked fulfillments advanced. Use `refresh-progress` for the lenient probe (controller-commented split) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `batch.operate` via the route pipe + scope check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.complete` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-lookup → 403-scope → 422-state/incomplete → 200. No validation, no 422-validation.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be complete-ready (or already `completed`) for success | Batch id manageable by the caller | None | None | `2` | Identifies the batch |

No body fields, query parameters, or validation-driven `422`.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/batches/2/complete",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: batch completed (or already completed) — HTTP 200 (DETAIL resource)

Returned via `ApiResponse::apiResponse(__('wms.batch.completed'), 200, true, BatchDetailResource…)` — translated at the call site (`en` → `"Batch completed successfully."`, verified in `resources/lang/en/wms.php:28`). Completion shape proven via the service auto-complete path (cancel test driving every task to `picked`); the STRICT gate responses below (`complete_status`/`incomplete`) are code-traced — no direct POST-complete HTTP test exists.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including idempotent replays on completed batches) |
| `message` | string | Always present | Translated `wms.batch.completed` | `en` → `"Batch completed successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `BatchDetailResource` (`status` = `completed`, `completed_at` set) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Batch completed successfully.",
  "success": true,
  "data": {
    "id": 2,
    "status": "completed",
    "total_items": 2,
    "picked_items": 2,
    "progress": 100,
    "completed_at": "2026-10-14T10:30:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action (both 422s are translated guard texts).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `batch.operate` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `batch.operate` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse batch (code-traced) | See 403-scope example |
| 404 | No batch with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Wrong state (`cancelled`, or outside `assigned/picking` — completed is NOT an error, it no-ops to 200) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.complete_status`: `en` → `"Cannot complete batch in status: cancelled"` (status illustrative; verified in `resources/lang/en/wms.php:29`) | None | Cancelled/pending batch (code-traced — no direct HTTP test) | See 422-state example |
| 422 | Incomplete work (recounted `picked_items ≠ total_items`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.incomplete` WITH id + counts: `en` → `"Batch #2 has incomplete picking work (1/2 picked): completion refused"` (values illustrative; verified in `resources/lang/en/wms.php:30`) | None | Unfinished batch (code-traced — no direct HTTP test) | See 422-incomplete example |
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

404-constraint example (ILLUSTRATIVE — NOT live-verified):

```json
{
  "message": "Not Found",
  "status": false
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot complete batch in status: cancelled",
  "status": false
}
```

422-incomplete example (translated WITH id + counts, no map):

```json
{
  "message": "Batch #2 has incomplete picking work (1/2 picked): completion refused",
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

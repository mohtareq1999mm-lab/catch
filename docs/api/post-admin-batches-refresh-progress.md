# Endpoint Name

Batches – Refresh Progress (`POST /api/v1/admin/batches/{id}/refresh-progress`)

# Description

Recomputes one batch's progress from task state through the single completion authority (lenient probe for scan flows; never refuses — not-ready and completed batches are no-ops), returning 200 with batch detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/batches/{id}/refresh-progress` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}/refresh-progress` custom `Route::post(...)->name('api.admin.batches.refresh-progress')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:batch.operate` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@refreshProgress(int $id)` — NO body, NO request class (plain `int $id`); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(batch.warehouse_id, 'batch.operate', asNotFound: FALSE)`; then `BatchPickingService::refreshBatchProgress` → the single completion authority `completeBatchIfReady` (code-verified). Authority behavior, one transaction (code-verified): `cancelled`/`completed` → returned untouched (terminal never resurrects); `picked_items` recounted from `picked`-status tasks whenever it drifts; auto-completion from ANY non-terminal state once `isComplete()` holds (pending batches whose tasks were all picked directly still close out) with a stable `completed_at` + fully-picked fulfillments advanced via the transition owner. The `RuntimeException` catch exists but no reachable throw remains on this path (the locked lookup re-reads the same id — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `batch.operate` via the route pipe + scope check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.refresh-progress` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-lookup → 403-scope → 200. No validation and no reachable 422 on this path (code-verified).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Batch id manageable by the caller | None | None | `2` | Identifies the batch |

No body fields, query parameters, or validation-driven `422`.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/batches/2/refresh-progress",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: progress refreshed — HTTP 200 (DETAIL resource, message reflects outcome)

The message is outcome-dependent (code-verified): `completed` status → `wms.batch.completed`, anything else → `wms.batch.progress`. Both carry fresh `BatchDetailResource`. Use this as the lenient probe; use `complete` for the strict gate.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.completed` / `wms.batch.progress` | `en` → `"Batch completed successfully."` / `"Batch progress refreshed successfully."` (verified in `resources/lang/en/wms.php:28,33`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `BatchDetailResource` (recounted `picked_items`) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Batch progress refreshed successfully.",
  "success": true,
  "data": {
    "id": 2,
    "status": "picking",
    "total_items": 2,
    "picked_items": 1,
    "progress": 50
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation and no reachable guard refusal exist on this path.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `batch.operate` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `batch.operate` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse batch (code-traced) | See 403-scope example |
| 404 | No batch with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

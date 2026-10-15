# Endpoint Name

Batches – Start Batch (`POST /api/v1/admin/batches/{id}/start`)

# Description

Starts one assigned batch (`assigned` → `picking` with timestamp), returning 200 with batch detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/batches/{id}/start` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}/start` custom `Route::post(...)->name('api.admin.batches.start')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:batch.operate` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@start(int $id)` — NO body, NO request class (plain `int $id`); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(batch.warehouse_id, 'batch.operate', asNotFound: FALSE)`; then `BatchPickingService::startPicking(batch)` — `RuntimeException` → 422 (code-verified). Service, under lock (code-verified): status MUST be exactly `assigned` (pending batches refuse — assignment first; test-authored); else `picking` + `started_at` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `batch.operate` via the route pipe + scope check (a picker without it gets 403 — test-authored lifecycle split) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.start` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-lookup → 403-scope → 422-state → 200. No validation, no 422-validation.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be `assigned` for success | Assigned batch id manageable by the caller | None | None | `2` | Identifies the batch |

No body fields, query parameters, or validation-driven `422`.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/batches/2/start",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: batch started — HTTP 200 (DETAIL resource)

Returned via `ApiResponse::apiResponse(__('wms.batch.started'), 200, true, BatchDetailResource…)` — translated at the call site (`en` → `"Batch picking started successfully."`, verified in `resources/lang/en/wms.php:27`). Test-authored: assigned batch → 200 + `picking`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.started` | `en` → `"Batch picking started successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `BatchDetailResource` (`status` = `picking`, `started_at` set) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Batch picking started successfully.",
  "success": true,
  "data": {
    "id": 2,
    "status": "picking",
    "assigned_to": 7,
    "started_at": "2026-10-14T10:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action (the single 422 is a translated guard text).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on batch commands) | See 401 example |
| 403 | Authenticated caller lacks `batch.operate` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Picker without the permission (test-authored 403) | See 403 example |
| 403 | Out-of-scope `batch.operate` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse batch (code-traced) | See 403-scope example |
| 404 | No batch with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Wrong state (anything but `assigned`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.start_status`: `en` → `"Cannot start picking batch in status: pending"` (status illustrative; verified in `resources/lang/en/wms.php:48`) | None | Unassigned batch (test-authored 422 on a pending batch) | See 422-state example |
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
  "message": "Cannot start picking batch in status: pending",
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

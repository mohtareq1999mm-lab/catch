# Endpoint Name

Batches – Cancel Batch (`POST /api/v1/admin/batches/{id}/cancel`)

# Description

Cancels one batch with a required reason (open tasks released to skipped with claims cleared; picked work untouched; the row is retained), returning 200 with batch detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/batches/{id}/cancel` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}/cancel` custom `Route::post(...)->name('api.admin.batches.cancel')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:batch.manage` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@cancel(BatchCommandRequest $request, int $id)` → validated reason → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(batch.warehouse_id, 'batch.manage', asNotFound: FALSE)`; then `BatchPickingService::cancelBatch(batch, reason)` — `InvalidArgumentException` → 422, base `\Exception` (terminal batches) → 422 (narrow by design — code-verified). Service (code-verified): blank-after-trim reason → 422 `cancel_reason` (NOTE: `' '` passes validation `min:1` and fails HERE — test-authored); `completed`/`cancelled` (pre-lock AND rechecked under lock) → 422 `cancel_status`; else `cancelled` + `cancelled_at` + reason into `notes`, and ALL operational open tasks (`pending/assigned/picking`) bulk-released to `skipped` with `Batch cancelled: <reason>` notes and claims cleared (picked work stays untouched — test-authored) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `batch.manage` via the route pipe + scope check (an operator without it gets 403 — test-authored lifecycle split) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.cancel` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-reason/state → 200.

## Request inputs

`BatchCommandRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must NOT be completed/cancelled for success | Cancellable batch id manageable by the caller | None | None | `2` | Identifies the batch |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` at validation (missing → 422 WITH map); whitespace-only passes validation then 422 WITHOUT map at the service (code-verified split) | Non-blank string | None | Stored into `notes` | `"line down"` | Why the batch is cancelled (test-authored) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/batches/2/cancel",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "reason": "line down"
  }
}
```

# Responses

## Success: batch cancelled — HTTP 200 (DETAIL resource, open tasks released)

Returned via `ApiResponse::apiResponse(__('wms.batch.cancelled'), 200, true, BatchDetailResource…)` — translated at the call site (`en` → `"Batch cancelled successfully."`, verified in `resources/lang/en/wms.php:34`). Test-authored: 200 + `cancelled` + ZERO remaining open tasks.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.cancelled` | `en` → `"Batch cancelled successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `BatchDetailResource` (`status` = `cancelled`, `cancelled_at` set) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Batch cancelled successfully.",
  "success": true,
  "data": {
    "id": 2,
    "status": "cancelled",
    "cancelled_at": "2026-10-14T10:25:00+00:00",
    "notes": "line down"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); service refusals use the EXCEPTION envelope `{message, status: false}` (code-verified). The blank-reason text is `wms.batch.cancel_reason` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on batch commands) | See 401 example |
| 403 | Authenticated caller lacks `batch.manage` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Picker without the permission (test-authored 403) | See 403 example |
| 403 | Out-of-scope `batch.manage` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse batch (test-authored 403 with state provably still `pending` on the sibling cancel path) | See 403-scope example |
| 404 | No batch with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure (missing/non-string/over-long reason) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing `reason` (code-traced) | See 422-validation example |
| 422 | Blank-after-trim reason (passes `min:1`, refused by the service — code-verified split) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.cancel_reason`: `en` → `"Cancellation reason is required"` (verified in `resources/lang/en/wms.php:58`) | None | `' '` reason (test-authored 422) | See 422-reason example |
| 422 | Terminal batch (`completed`/`cancelled`, rechecked under lock) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.cancel_status`: `en` → `"Cannot cancel batch in status: completed"` (status illustrative; verified in `resources/lang/en/wms.php:59`) | None | Completed batch (test-authored 422) | See 422-state example |
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

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "reason": ["The reason field is required."]
  }
}
```

422-reason example (translated, no map):

```json
{
  "message": "Cancellation reason is required",
  "status": false
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot cancel batch in status: completed",
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

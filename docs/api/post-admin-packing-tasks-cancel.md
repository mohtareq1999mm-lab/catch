# Endpoint Name

Packing Tasks – Cancel Task (`POST /api/v1/admin/packing-tasks/{id}/cancel`)

# Description

Cancels one non-completed packing task with a required reason (packed/verified work cannot silently downgrade; the row is retained), returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packing-tasks/{id}/cancel` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packing-tasks/{id}/cancel` custom `Route::post(...)->name('api.admin.packing-tasks.cancel')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingController@cancel(BatchCommandRequest $request, int $id)` → validated reason → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→fulfillment warehouse, 'manage-fulfillment', asNotFound: FALSE)`; then `PackingService::cancelTask(task, reason)` — `InvalidArgumentException`/`RuntimeException` → 422 (code-verified). Service (code-verified): null/blank-after-trim reason → 422 `wms.batch.cancel_reason` (NOTE: `' '` passes validation `min:1` and fails HERE — same split as the skip endpoint); `packed`/`verified`/`cancelled` → 422 `cancel_status` (packed is completed physical work — verify it or leave it, code-commented); else `cancelled` + trimmed reason into `notes` (row retained for audit) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-fulfillment` via the route pipe + scope check (a packer/supervisor without it gets 403 — test-authored permission split) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packing-tasks.cancel` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-reason/state → 200.

## Request inputs

`BatchCommandRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must NOT be packed/verified/cancelled for success | Cancellable task id manageable by the caller | None | None | `1` | Identifies the packing task |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` at validation (missing → 422 WITH map); whitespace-only passes validation then 422 WITHOUT map at the service (code-verified split, batch-cancel-tested) | Non-blank string | None | Trimmed into `notes` | `"duplicate task"` | Why the task is cancelled (test-authored) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packing-tasks/1/cancel",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "reason": "duplicate task"
  }
}
```

# Responses

## Success: task cancelled — HTTP 200 (DETAIL resource, row retained)

Returned via `ApiResponse::apiResponse(__('wms.packing.cancelled'), 200, true, PackingTaskDetailResource…)` — translated at the call site (`en` → `"Packing task cancelled successfully."`, verified in `resources/lang/en/wms.php:100`). Test-authored: 200 + `cancelled`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.cancelled` | `en` → `"Packing task cancelled successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackingTaskDetailResource` (`status` = `cancelled`, `notes` = trimmed reason) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Packing task cancelled successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "cancelled",
    "notes": "duplicate task"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); service refusals use the EXCEPTION envelope `{message, status: false}` (code-verified). The blank-reason text lives under `wms.batch.*` (code-verified namespace split — same convention as the batch cancel endpoint).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `manage-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Packer without the permission (test-authored 403) | See 403 example |
| 403 | Out-of-scope `manage-fulfillment` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse cancel (pattern test-authored on sibling commands) | See 403-scope example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure (missing/non-string/over-long reason) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing `reason` (code-traced) | See 422-validation example |
| 422 | Blank-after-trim reason (passes `min:1`, refused by the service — code-verified split) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.cancel_reason`: `en` → `"Cancellation reason is required"` (verified in `resources/lang/en/wms.php:58`) | None | `' '` reason (batch-cancel-tested pattern) | See 422-reason example |
| 422 | Completed work (`packed`/`verified`) or already `cancelled` | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.cancel_status`: `en` → `"Cannot cancel packing task in status: packed"` (status illustrative; verified in `resources/lang/en/wms.php:121`) | None | Packed task (test-authored 422 with state provably still `packed`) | See 422-state example |
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
  "message": "Cannot cancel packing task in status: packed",
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

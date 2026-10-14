# Endpoint Name

Picking Tasks – Skip Task (`POST /api/v1/admin/picking-tasks/{id}/skip`)

# Description

Skips one open picking task with a required reason (skipped rows stay terminal and out of the open set for later retry), returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/skip` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/skip` custom `Route::post(...)->name('api.admin.picking-tasks.skip')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@skip(SkipTaskRequest $request, int $id)` → validated reason → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→item→fulfillment warehouse, 'picking-execute', asNotFound: FALSE)` (cross-warehouse → 403 catalogue with state provably `pending`, test-authored); then `BatchPickingService::skipTask(task, reason)` — `InvalidArgumentException`/`RuntimeException` → 422 (code-verified). Service (code-verified): blank-after-trim reason → 422 `skip_reason` (refused BEFORE the transaction; NOTE: `' '` PASSES validation `min:1` and fails HERE — test-authored); terminal (outside `pending/assigned/picking`, rechecked under lock) → 422 `skip_status`; else `status → skipped` + `notes = reason` (skipped rows persist for retry; replacements are separate rows — proven via the batch-retry tests). No override parameter exists on this action (code-verified — `resolveOverride` is never called) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe + scope check |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.picking-tasks.skip` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps — only `index`/`claim`/`confirm` are name-pinned in `WmsRbacFoundationTest`) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-reason/terminal → 200.

## Request inputs

`SkipTaskRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be OPEN (`pending/assigned/picking`) for success | Open task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` at validation (missing → 422 WITH map); whitespace-only passes validation then 422 WITHOUT map at the service (test-authored `' '` case) | Non-blank string | None | None | `"damaged stock"` | Why the task is skipped (persisted into `notes`; test-authored) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/skip",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "reason": "damaged stock"
  }
}
```

# Responses

## Success: task skipped — HTTP 200 (DETAIL resource, terminal state)

Returned via `ApiResponse::apiResponse(__('wms.picking.skipped'), 200, true, PickingTaskDetailResource…)` — translated at the call site (`en` → `"Picking task skipped successfully."`, verified in `resources/lang/en/wms.php:154`). Test-authored: valid reason → 200 + `skipped`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal text | Always `"Picking task skipped successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (`status` = `skipped`, `notes` = reason) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Picking task skipped successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "skipped",
    "quantity_picked": "0.00",
    "op_seq": 0,
    "scan_log": [],
    "notes": "damaged stock"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); service refusals use the EXCEPTION envelope `{message, status: false}` (code-verified). Both service texts live under `wms.batch.*` (code-verified namespace split).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse skip (test-authored 403 with state provably `pending`) | See 403-scope example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure (missing/non-string/over-long reason) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing `reason` (code-traced) | See 422-validation example |
| 422 | Blank-after-trim reason (passes `min:1`, refused by the service — code-verified split) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.skip_reason`: `en` → `"Skip reason is required"` (verified in `resources/lang/en/wms.php:53`) | None | `' '` reason (test-authored 422 with state provably `pending`) | See 422-reason example |
| 422 | Terminal task (outside `pending/assigned/picking`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.skip_status`: `en` → `"Cannot skip picking task #1 in status skipped"` (ids illustrative; verified in `resources/lang/en/wms.php:54`) | None | Repeat skip (test-authored 422) | See 422-status example |
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
  "message": "Skip reason is required",
  "status": false
}
```

422-status example (translated, no map):

```json
{
  "message": "Cannot skip picking task #1 in status skipped",
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

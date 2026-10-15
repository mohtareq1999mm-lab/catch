# Endpoint Name

Batches – Next Task (`GET /api/v1/admin/batches/{id}/next-task`)

# Description

Returns the batch's lowest-sequence pending task, or a translated empty message when none remains, returning 200 either way.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/batches/{id}/next-task` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}/next-task` custom `Route::get(...)->name('api.admin.batches.next-task')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@nextTask(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(batch.warehouse_id, 'view-fulfillment', asNotFound: TRUE)` (cross-warehouse → 404, test-authored); `BatchPickingService::getNextTask` = lowest-`sequence` `pending` task (`pending()` + `bySequence()` scopes — code-verified) or `null` → `null` returns 200 with the translated empty message and NO `data` key (code-verified `apiResponse` without data), else 200 `PickingTaskResource`. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.next-task` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-lookup → 404-scope → 200 (with or without a task). No 403-scope exists on this read; no validation, no 422.

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
  "url": "/api/v1/admin/batches/2/next-task",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success with a pending task — HTTP 200 (single `PickingTaskResource`)

Returned via `ApiResponse::apiResponse(__('wms.batch.next_task'), 200, true, PickingTaskResource::make(...))` — translated at the call site (`en` → `"Next picking task fetched successfully."`, verified in `resources/lang/en/wms.php:21`). Test-authored: lowest-sequence pending task first.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.next_task` | `en` → `"Next picking task fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Present when a pending task exists | Lowest-sequence pending task | `PickingTaskResource` keys (see picking index doc) |

```json
{
  "status": 200,
  "message": "Next picking task fetched successfully.",
  "success": true,
  "data": {
    "id": 6,
    "status": "pending",
    "sequence": 2
  }
}
```

## Success with NO pending task — HTTP 200 (NO `data` key)

Returned via `ApiResponse::apiResponse(__('message.MESSAGE.BATCH_NO_PENDING_TASKS'), 200, true)` — translated message with NO data (`ApiResponse` omits `data` when empty — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` |
| `message` | string | Always present | Translated `message.MESSAGE.BATCH_NO_PENDING_TASKS` | `en` → `"No pending tasks in this batch."` (verified in `resources/lang/en/message.php:632`) |
| `success` | boolean | Always present | Outcome flag | Always `true` |

```json
{
  "status": 200,
  "message": "No pending tasks in this batch.",
  "success": true
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

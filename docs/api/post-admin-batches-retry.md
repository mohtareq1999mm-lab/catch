# Endpoint Name

Batches – Retry Skipped (`POST /api/v1/admin/batches/{id}/retry`)

# Description

Creates one replacement task per retryable skipped item (open-task conflicts → 409; nothing retryable → 200 empty array), returning 200 with the created tasks.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/batches/{id}/retry` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}/retry` custom `Route::post(...)->name('api.admin.batches.retry')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:batch.manage` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@retry(BatchCommandRequest $request, int $id)` → validated reason → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(batch.warehouse_id, 'batch.manage', asNotFound: FALSE)`; then `BatchPickingService::retrySkippedTasks(batch, reason)` — `InvalidArgumentException` → 422, `WmsConflictException` → 409 (typed, locale-proof — terminal batches stay 422 by TYPE, never text, code-commented), `RuntimeException` → 422 (code-verified). Service, one transaction (code-verified): blank-after-trim reason → 422 `retry_reason`; `completed`/`cancelled` batch → 422 `retry_status`; per skipped row grouped by ITEM (one replacement per item — several dead rows may share an item across cycles): fully-picked meanwhile OR placement-less item → SKIPPED silently (operator assigns placement first — code-verified); an EXISTING open task on the item → loud 409 `retry_open_task` (exactly-one-open-task preserved; repeat retry is therefore NOT a safe replay — test-authored); else replacement created (`pending`, next `sequence`, `notes = 'Retry: <reason>'`, remaining quantity, current linkage). `total_items` needs NO adjustment — 1:1 swap keeps the completion denominator correct (service-commented, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `batch.manage` via the route pipe + scope check (an operator without it gets 403 — test-authored lifecycle split) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.retry` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-reason/batch-state → 409-open-task → 200 (with 0..n replacements).

## Request inputs

`BatchCommandRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must NOT be completed/cancelled for replacements | Batch id manageable by the caller | None | None | `2` | Identifies the batch |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` at validation (missing → 422 WITH map); whitespace-only passes validation then 422 WITHOUT map at the service (code-verified split) | Non-blank string | None | Prefixed into replacement `notes` as `'Retry: <reason>'` | `"restocked"` | Why skipped work is retried (test-authored) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/batches/2/retry",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "reason": "restocked"
  }
}
```

# Responses

## Success with replacements — HTTP 200 (`PickingTaskResource[]`)

Returned via `ApiResponse::apiResponse(__('wms.batch.retried'), 200, true, PickingTaskResource::collection($created))` — translated at the call site (`en` → `"Skipped tasks retried successfully."`, verified in `resources/lang/en/wms.php:35`). Test-authored: 1 replacement, victim row provably still `skipped`.

## Success with nothing retryable — HTTP 200 (empty array, different message)

Returned via `ApiResponse::apiResponse(__('wms.batch.nothing_retry'), 200, true, [])` — `en` → `"Nothing retryable in this batch."` (verified in `resources/lang/en/wms.php:36`). Test-authored: 200 + `[]`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.retried` / `wms.batch.nothing_retry` | `en` → `"Skipped tasks retried successfully."` / `"Nothing retryable in this batch."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present on success | Replacement tasks (possibly `[]`) | Flat `PickingTaskResource[]` (see picking index doc for keys) |

```json
{
  "status": 200,
  "message": "Skipped tasks retried successfully.",
  "success": true,
  "data": [
    {
      "id": 9,
      "status": "pending",
      "notes": "Retry: restocked"
    }
  ]
}
```

```json
{
  "status": 200,
  "message": "Nothing retryable in this batch.",
  "success": true,
  "data": []
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); contention uses 409 and refusals use the EXCEPTION envelope `{message, status: false}` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on batch commands) | See 401 example |
| 403 | Authenticated caller lacks `batch.manage` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Picker without the permission (test-authored 403) | See 403 example |
| 403 | Out-of-scope `batch.manage` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse batch (code-traced) | See 403-scope example |
| 404 | No batch with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 409 | An open task already exists on a retryable item (repeat retry is NOT a safe replay — refused with exactly 2 pending and nothing created, test-authored) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.retry_open_task`: `en` → `"Retry refused: fulfillment item #3 already has open picking task #5 (status: pending)"` (ids illustrative; verified in `resources/lang/en/wms.php:57`) | None | Repeat retry (test-authored 409 + no-duplication proof) | See 409 example |
| 422 | Body validation failure (missing/non-string/over-long reason) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing `reason` (code-traced) | See 422-validation example |
| 422 | Blank-after-trim reason (passes `min:1`, refused by the service — code-verified split) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.retry_reason`: `en` → `"Retry reason is required"` (verified in `resources/lang/en/wms.php:55`) | None | `' '` reason (batch-cancel-tested pattern) | See 422-reason example |
| 422 | Terminal batch (`completed`/`cancelled`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.retry_status`: `en` → `"Cannot retry skipped tasks on batch #2 in status completed"` (ids illustrative; verified in `resources/lang/en/wms.php:56`) | None | Terminal batch (code-traced) | See 422-state example |
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

409 example (translated, no map — nothing created):

```json
{
  "message": "Retry refused: fulfillment item #3 already has open picking task #5 (status: pending)",
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
  "message": "Retry reason is required",
  "status": false
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot retry skipped tasks on batch #2 in status completed",
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

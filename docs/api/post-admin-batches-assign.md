# Endpoint Name

Batches – Assign Batch (`POST /api/v1/admin/batches/{id}/assign`)

# Description

Assigns one batch to a worker (first-winner-wins → 409; privileged `reassign` steals with `batch.manage`), returning 200 with batch detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/batches/{id}/assign` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}/assign` custom `Route::post(...)->name('api.admin.batches.assign')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:batch.operate` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@assign(AssignBatchRequest $request, int $id)` → validated `user_id`/`reassign` → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(batch.warehouse_id, 'batch.operate', asNotFound: FALSE)`; terminal/wrong-state pre-guard (outside `pending/assigned` → 422 `assign_conflict_status` — duplicates the service guard, code-verified); `reassign=true` → SECOND scope check `batch.manage` on the same warehouse (TOCTOU-safe by design: the decision never relies on the pre-lock snapshot — controller-commented, code-verified); then `BatchPickingService::assignBatch(batch, userId, reassign)` — `WmsConflictException` → 409 (typed, locale-proof), `RuntimeException` → 422 (code-verified). Service, under lock (code-verified): terminal → 422 `assign_conflict_status`; occupied by ANOTHER user without `reassign` → 409 `already_assigned` (winner retention is structural — throw-before-write, code-verified; no test asserts the retained id, unlike the picking-claim twin); assignee missing OR homed in ANOTHER warehouse (globals with `manage-warehouse` and null-home users stay eligible; fail-closed on missing users — code-verified) → 422 `assignee_warehouse`; else `assigned_to` + `assigned`. The response message distinguishes takeover (`reassigned` when the PRE-lock snapshot showed another holder and the target matches — computed from the stale snapshot, code-verified) from plain assignment |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `batch.operate` via the route pipe + scope check (`reassign=true` additionally needs `batch.manage` on the batch warehouse) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.assign` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-state → 403-reassign-gate → 409-occupied → 422-assignee → 200.

## Request inputs

`AssignBatchRequest` runs before the action (`authorize: true`). NOTE (request-commented): the assignee is `user_id` here (unlike packing-task assign, which pins the caller) — but NEVER the actor implicitly.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be `pending`/`assigned` for success | Assignable batch id manageable by the caller | None | None | `2` | Identifies the batch |
| `user_id` | body | integer | Required | No | `required\|integer\|exists:users,id` (unknown users 422 HERE at validation) | Existing user id (same-warehouse, global, or null-home — else 422) | None | None | `7` | The worker taking the batch (test-authored) |
| `reassign` | body | boolean | Optional | Yes | `nullable\|boolean` | Truthy or omitted | `false` (first-winner 409 preserved) | `true` requires `batch.manage` on the batch warehouse (checked in-controller) | `true` | Privileged takeover intent |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/batches/2/assign",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "user_id": 7
  }
}
```

# Responses

## Success: batch assigned (or reassigned) — HTTP 200 (DETAIL resource)

Fresh assignment returns 200 with `wms.batch.assigned`; a privileged takeover returns 200 with `wms.batch.reassigned` (message chosen from the pre-lock snapshot — code-verified). Both carry `BatchDetailResource`. Test-authored: assign 200; re-assign by another operator without intent → 409 with the winner kept.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.assigned` / `wms.batch.reassigned` | `en` → `"Batch assigned successfully."` / `"Batch reassigned successfully."` (verified in `resources/lang/en/wms.php:25-26`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `BatchDetailResource` (`assigned_to` = target, `status` = `assigned`) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Batch assigned successfully.",
  "success": true,
  "data": {
    "id": 2,
    "status": "assigned",
    "assigned_to": 7
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); contention uses 409 and refusals use the EXCEPTION envelope `{message, status: false}` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on batch commands) | See 401 example |
| 403 | Authenticated caller lacks `batch.operate` (route pipe — the picker/executor split) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Picker without the permission (test-authored 403 + zero batches) | See 403 example |
| 403 | Out-of-scope `batch.operate` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse batch (test-authored 403 with `assigned_to` provably still NULL) | See 403-scope example |
| 403 | `reassign=true` WITHOUT `batch.manage` on the batch warehouse (explicit anti-takeover gate — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Unauthorized takeover (code-traced) | See 403-reassign example |
| 404 | No batch with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 409 | Batch already assigned to ANOTHER user without `reassign` (winner retention structural — throw-before-write, code-verified; not assertion-verified — no test asserts the retained id) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.already_assigned`: `en` → `"Batch #2 is already assigned to user 7"` (ids illustrative; verified in `resources/lang/en/wms.php:47`) | None | Contested assign (test-authored 409 status-only) | See 409 example |
| 422 | Body validation failure (missing/unknown `user_id`) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Bad body (code-traced) | See 422-validation example |
| 422 | Wrong state (outside `pending/assigned` — controller pre-guard emits `wms.batch.assign_status`, service recheck emits `wms.batch.assign_conflict_status`; identical English text, code-verified key split) | `{message: string, status: boolean}` (NO `errors` map) | None | `en` → `"Cannot assign batch in status: picking"` (status illustrative; both keys verified in `resources/lang/en/wms.php:37,46`) | None | Started batch (code-traced) | See 422-state example |
| 422 | Assignee missing OR homed in another warehouse (fail-closed; globals + null-home stay eligible — code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.assignee_warehouse`: `en` → `"User 9 is not eligible for warehouse 1"` (ids illustrative; verified in `resources/lang/en/wms.php:32`) | None | Foreign assignee (code-traced) | See 422-assignee example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (missing permission — the picker split):

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

403-reassign example (catalogue text, code-verified):

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

409 example (translated, no map):

```json
{
  "message": "Batch #2 is already assigned to user 7",
  "status": false
}
```

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "user_id": ["The selected user id is invalid."]
  }
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot assign batch in status: picking",
  "status": false
}
```

422-assignee example (translated, no map):

```json
{
  "message": "User 9 is not eligible for warehouse 1",
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

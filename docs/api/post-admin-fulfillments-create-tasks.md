# Endpoint Name

Fulfillments – Create Picking Tasks (`POST /api/v1/admin/fulfillments/{id}/create-tasks`)

# Description

Materializes idempotent picking tasks per item and advances pending rows to picking.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/fulfillments/{id}/create-tasks` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/fulfillments/{id}/create-tasks` custom `Route::post(...)->name('api.admin.fulfillments.create-tasks')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` (a PICKING permission on a fulfillment path — code-verified; `view-fulfillment`/`manage-fulfillment` alone fail it) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@createTasks(int $id)` — NO request validation (no body read at all); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(warehouse_id, 'picking-execute')` (verbatim-403 path — foreign fulfillment creates NOTHING there, test-verified); then `OrderPickingService::createTasksForFulfillment` in a row-locked transaction: items WITHOUT placement are SKIPPED (manual assignment required — allocation fallback, code-verified); fully-picked items skipped; items with ANY open task (pending/assigned/picking, order OR batch) REUSE the existing row (no doubles across flows — code-verified); new tasks get `sequence` 1..N in item-`id` order; non-empty result on a `pending` fulfillment advances it to `picking` through the canonical owner (any OTHER state untouched — code-verified). Concurrent creators serialize on the row lock (second blocks, then reuses — source-commented P4-9) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe (no constructor/inline check exists — the pipe alone decides) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.create-tasks` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Fulfillment id visible to the caller | None | None | `1` | Identifies the fulfillment |

No query parameters, body fields, or `422` contract (no validation runs AND the service path throws no mapped refusal — replays, skips, and advances all return 200; code-verified).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/1/create-tasks",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: tasks ready — HTTP 200 (ALWAYS, including replays and empty sets)

Returned via `ApiResponse::apiResponse('Picking tasks ready.', 200, true, PickingTaskResource::collection($tasks))` (literal message; PLAIN task array — NOT the fulfillment detail). Verified by `test_create_tasks_reuses_and_scopes_by_fulfillment` (200 + non-empty + replay id-equality, `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (fresh, replayed, or empty) |
| `message` | string | Always present | Literal (untranslated) | Always `"Picking tasks ready."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty — unplaced/complete items yield `[]`) | `PickingTaskResource` rows in creation order | Row keys per the task resource (id, item refs, quantities, status, claim fields — exact keys follow `PickingTaskResource`, summarized here) |

```json
{
  "status": 200,
  "message": "Picking tasks ready.",
  "success": true,
  "data": []
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation and no service refusals, so no `422` exists on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe — fulfillment permissions do NOT substitute) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (verbatim, NOTHING created abroad — verified zero-task proof) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Foreign fulfillment (verified → 403 + zero-task proof on the foreign row) | See 403-scope example |
| 404 | No fulfillment with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
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

403-scope example (verbatim, nothing created):

```json
{
  "message": "Forbidden warehouse operation.",
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

404-constraint example:

```json
{
  "message": "Not Found",
  "status": false
}
```

405 example:

```json
{
  "message": "Method Not Allowed",
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

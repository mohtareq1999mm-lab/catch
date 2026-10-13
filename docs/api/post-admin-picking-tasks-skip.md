# Endpoint Name

Picking Tasks – Skip Task (`POST /api/v1/admin/picking-tasks/{id}/skip`)

# Description

Skips one non-terminal picking task for a required reason, returning 200 skipped.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/skip` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/skip` custom `Route::post(...)->name('api.admin.picking-tasks.skip')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@skip(SkipTaskRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST — code-verified order); `authorizeWarehouseScope(task-chain-warehouse, 'picking-execute')` (verbatim-403 path — cross-warehouse skips leave `pending`, test-verified); then `batches->skipTask(task, reason)` — `InvalidArgumentException` (terminal re-skip — test-verified second-skip 422) and `RuntimeException` BOTH map to 422 live (code-verified dual catch); success flips to `skipped` (test-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe (no constructor/inline check exists besides the override sub-gate, which this action does NOT consult — no `resolveOverride` call here; code-verified absence) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.picking-tasks.skip` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200/422-engine.

## Request inputs

`SkipTaskRequest` runs before the body (single `reason` rule; blank-only `' '` fails via global empty-string conversion — test-verified with `pending` proof).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Non-terminal task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` (blank-only fails — test-verified) | Non-blank string ≤ 2000 chars | None | None | `"damaged stock"` | Audit reason (test-verified value) |

## Request examples

Valid request skipping a task (test-verified call):

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

## Success: task skipped — HTTP 200

Returned via `ApiResponse::apiResponse('Picking task skipped successfully.', 200, true, PickingTaskDetailResource::make($detail))` (literal message). Verified by `test_skip_requires_reason_and_refuses_terminal` (first skip 200 + `skipped` proof, `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Picking task skipped successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (`status: skipped`) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Picking task skipped successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "skipped",
    "op_seq": 0,
    "scan_log": [],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (verbatim, state provably `pending`) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse skip (verified by `test_skip_cross_warehouse_returns_403` → 403 + `pending` proof) | See 403-scope example |
| 404 | No task with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `SkipTaskRequest` fails (missing/blank/overlong `reason`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Blank `reason: ' '` (verified → 422 + `pending` proof) | See 422-validation example |
| 422 | Terminal re-skip (`InvalidArgumentException`) or service refusal (`RuntimeException`) — live text, state held | `{message: string, status: boolean}` (NO `errors` map — dual catch) | None | Live engine text (exact strings engine-dependent) | None | Already-terminal task (verified → 422 on the second skip) | See 422-engine example |
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

403-scope example (verbatim, state held):

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

422-validation example:

```json
{
  "message": "The reason field is required.",
  "status": false,
  "errors": {
    "reason": ["The reason field is required."]
  }
}
```

422-engine example (live text, no map):

```json
{
  "message": "Live engine refusal text (exact string engine-dependent)",
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

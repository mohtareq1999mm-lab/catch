# Endpoint Name

Picking Tasks – Release Claim (`POST /api/v1/admin/picking-tasks/{id}/release`)

# Description

Returns one held task to the pool with claimant-owned release and terminal refusal.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/release` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/release` custom `Route::post(...)->name('api.admin.picking-tasks.release')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@releaseClaim(Request $request, int $id)` — NO request validation (plain `Request`; body is UNREAD — even `override` is parsed but only forwarded, and the verified tests send `[]`); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task-chain-warehouse, 'picking-execute')` (cross-warehouse untested on THIS action — the claim twin proves the shape; code-traced here); TERMINAL tasks (outside pending/assigned/picking) refuse FIRST with 422 live text — the defensive read gate exists because the SERVICE would otherwise wipe claim state off completed work (source-commented rationale; code-verified); then `execution->releaseClaim(task, actorId, override)` — the SERVICE owns the claimant check: a NON-HOLDER gets `AuthorizationException` rethrown VERBATIM → `Handler` 403 (test-verified: foreign release 403s with the holder provably retained); the HOLDER releases → `pending`, claim cleared, progress kept (test-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe (no constructor/inline check exists besides the service-owned claimant rule) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body fields are honored (tests send `[]`); `Content-Type` is functionally irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.picking-tasks.release` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (must be the HOLDER to release) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be LIVE (pending/assigned/picking) for success | Live HELD task id manageable by the caller | None | None | `1` | Identifies the picking task |

No query parameters, body fields, or validation-driven `422` (the 422 below is the terminal gate, not validation).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/release",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: claim released — HTTP 200 (task pooled, progress kept)

Returned via `ApiResponse::apiResponse('Picking claim released successfully.', 200, true, PickingTaskDetailResource::make($detail))` (literal message). Verified by `test_release_returns_task_to_pool_and_forbids_foreign_claims` (owner half: 200 + `pending` + `claimed_by` NULL + progress-kept proofs, `postJson` with `[]`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Picking claim released successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (`status: pending`, `claimed_by: null`) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Picking claim released successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "pending",
    "claimed_by": null,
    "op_seq": 0,
    "scan_log": [],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so the 422 carries live text with NO `errors` map.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (verbatim) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse release (code-traced shape; claim twin proves it) | See 403-scope example |
| 403 | NON-HOLDER attempts release (service-owned claimant check — rethrown `AuthorizationException`, NOT the override path) | `{message: string, status: boolean}` | None | Live service text (exact string service-dependent — the test pins the STATUS, not the text) | None | Foreign release (verified → 403 with holder provably retained) | See 403-claimant example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | TERMINAL task (outside pending/assigned/picking — defensive read gate protecting completed work's claim state) | `{message: string, status: boolean}` (NO `errors` map) | None | `"Picking task #1 is terminal (status: picked): release refused"` (FULL live text with id + status — code-verified verbatim shape, ids illustrative) | None | Picked/skipped/cancelled task (verified → 422 with `picked` proof) | See 422 example |
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

403-scope example (verbatim WITH period):

```json
{
  "message": "Forbidden warehouse operation.",
  "status": false
}
```

403-claimant example (live service text):

```json
{
  "message": "Live claimant-check text (exact string service-dependent)",
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

422 example (FULL live text, no map):

```json
{
  "status": 422,
  "message": "Picking task #1 is terminal (status: picked): release refused",
  "success": false
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

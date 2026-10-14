# Endpoint Name

Picking Tasks – Claim Task (`POST /api/v1/admin/picking-tasks/{id}/claim`)

# Description

Claims one live picking task for the caller (same-worker re-claim refreshes the lease idempotently; override-gated steals allowed), returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/claim` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/claim` custom `Route::post(...)->name('api.admin.picking-tasks.claim')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@claim(Request $request, int $id)` — NO request validation (plain `Request`; forged `claimed_by`/`actor_id` fields IGNORED — the authenticated caller ALWAYS wins, test-authored); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→item→fulfillment warehouse, 'picking-execute', asNotFound: FALSE)` (cross-warehouse → 403 catalogue with state provably `pending` + `claimed_by` NULL, test-authored); TERMINAL tasks (outside `pending/assigned/picking`) refuse FIRST with 422 (defensive read gate — code-verified); then `PickingExecutionService::claim(task, actorId, null, override)` — lease `max(1, config('fulfillment.claim_lease_minutes', 15))` (default 15 min, code-verified); contention `RuntimeException` → 409 via `conflict()` (first winner provably retained, test-authored); same-worker re-claim → 200 lease refresh (test-authored). `override` intent (`$request->boolean('override')`) WITHOUT `fulfillment-override` permission → 403 (never silent elevation — holder provably retained, test-authored); WITH it → steal allowed when service state permits (override never reopens terminal tasks — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe + scope check (steals additionally need `fulfillment-override`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `override` is read via `$request->boolean()` (query or body); all other keys ignored; `Content-Type` functionally irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.picking-tasks.claim` (explicit named route; `route:list` verified; name-pinned in `WmsRbacFoundationTest`) |

Order matters: 401 → 403-route → 404-lookup → 403-scope → 422-terminal (pre-guard runs BEFORE override resolution — a terminal task with `override=1` and no permission still 422s, code-verified) → 403-override → 409-contention → 200.

## Request inputs

No `FormRequest` — the ONLY key with any effect is `override` (everything else, including forged identity fields, is dead input; code-verified + test-proven). No validation `422` with an `errors` map exists on this action.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (BECOMES the claimant — forged ids ignored) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be LIVE (`pending/assigned/picking`) for success | Live task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `override` | body/query | boolean-ish | Optional | No | `$request->boolean('override')` truthiness; true WITHOUT `fulfillment-override` permission → 403 (never silent elevation) | Truthy values | `false` | None | `true` | Steal intent (requires the override permission) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/claim",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  },
  "body": {}
}
```

# Responses

## Success: task claimed — HTTP 200 (DETAIL resource, holder recorded)

Returned via `ApiResponse::apiResponse(__('wms.picking.claimed'), 200, true, PickingTaskDetailResource…)` — translated at the call site (`en` → `"Picking task claimed successfully."`, verified in `resources/lang/en/wms.php:150`). Test-authored: first claim 200 + holder + `assigned`; replay 200 unchanged; override-steal 200 with new holder.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including replays and steals) |
| `message` | string | Always present | Literal text | Always `"Picking task claimed successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (`claimed_by` = caller, `status` = `assigned`) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Picking task claimed successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "assigned",
    "claimed_by": 7,
    "op_seq": 0,
    "scan_log": [],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. The 422/409 below use the EXCEPTION envelope `{message, status: false}` (no `success` key, no integer `status` key, no `errors` map — code-verified via `Handler`: `unprocessable()` → `HttpException` branch; `conflict()` → `ConflictHttpException` → same branch with 409).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (controller verbatim discarded by Handler — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse claim (test-authored 403 with state provably `pending` + `claimed_by` NULL) | See 403-scope example |
| 403 | `override` intent WITHOUT `fulfillment-override` permission (explicit anti-elevation; controller verbatim discarded by Handler — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Unauthorized steal (test-authored 403 + holder retained) | See 403-override example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 409 | Live contention lost (another holder won — first winner provably retained, test-authored) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.already_claimed`: `en` → `"Picking task #1 is already claimed (status: assigned)"` (ids illustrative; verified in `resources/lang/en/wms.php:161`). RACE NOTE: a task going terminal between the 422 pre-guard and the write surfaces here instead as `claim_terminal_exec` (identical text to the 422 guard — code-verified service recheck) | None | Contested claim (test-authored 409 + first-winner proof) | See 409 example |
| 422 | TERMINAL task (outside `pending/assigned/picking` — defensive read gate, checked BEFORE the service; code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.claim_terminal` WITH id + status: `en` → `"Picking task #1 is terminal (status: picked): claim refused"` (ids illustrative; verified in `resources/lang/en/wms.php:158`) | None | Picked/skipped/cancelled task (test-authored 422 on a `picked` row) | See 422 example |
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

403-scope example (catalogue text — the controller verbatim is discarded by the Handler, code-verified):

```json
{
  "message": "This action is unauthorized.",
  "status": false
}
```

403-override example (catalogue text — the override verbatim is likewise discarded, code-verified):

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
  "message": "Picking task #1 is already claimed (status: assigned)",
  "status": false
}
```

422 example (translated WITH id + status, no map):

```json
{
  "message": "Picking task #1 is terminal (status: picked): claim refused",
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

# Endpoint Name

Picking Tasks – Release Claim (`POST /api/v1/admin/picking-tasks/{id}/release`)

# Description

Releases one task's claim back to the pool (status → `pending`, claim fields cleared, picked progress kept), returning 200 with task detail. Only the claimant (or an override holder) may release — anyone else gets 403.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/release` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/release` custom `Route::post(...)->name('api.admin.picking-tasks.release')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@releaseClaim(Request $request, int $id)` — NO request validation (plain `Request`); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→item→fulfillment warehouse, 'picking-execute', asNotFound: FALSE)`; TERMINAL tasks (outside `pending/assigned/picking`) refuse with 422 (defensive read gate — the service would otherwise wipe claim state off completed work, code-verified); then `PickingExecutionService::releaseClaim(task, actorId, override)` — non-claimant WITHOUT override → `RuntimeException(claimed_other)` rethrown as `AuthorizationException` → 403 catalogue with the claim provably surviving (test-authored); owner → 200 `pending` + cleared claim + progress kept (test-authored). TOCTOU CAVEAT (code-verified): the terminal pre-guard runs OUTSIDE the transaction and the service performs NO terminal recheck under lock (unlike claim/recordPick/skip/reallocate) — a task completing between guard and write would have its claim state wiped. `override` intent WITHOUT `fulfillment-override` → 403 (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe + scope check (override additionally needs `fulfillment-override`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `override` is read via `$request->boolean()` (query or body); all other keys ignored; `Content-Type` functionally irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.picking-tasks.release` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps — only `index`/`claim`/`confirm` are name-pinned in `WmsRbacFoundationTest`) |

Order matters: 401 → 403-route → 404-lookup → 403-scope → 422-terminal (pre-guard runs BEFORE override resolution — code-verified) → 403-override → 403-foreign-claim → 200.

## Request inputs

No `FormRequest` — the ONLY key with any effect is `override`. No validation `422` with an `errors` map exists on this action.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (must BE the claimant unless overriding) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be LIVE (`pending/assigned/picking`) for success | Live task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `override` | body/query | boolean-ish | Optional | No | `$request->boolean('override')` truthiness; true WITHOUT `fulfillment-override` permission → 403 | Truthy values | `false` | None | `true` | Release-another's-claim intent (requires the override permission) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/release",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  },
  "body": {}
}
```

# Responses

## Success: claim released — HTTP 200 (DETAIL resource, pool state)

Returned via `ApiResponse::apiResponse(__('wms.picking.released'), 200, true, PickingTaskDetailResource…)` — translated at the call site (`en` → `"Picking claim released successfully."`, verified in `resources/lang/en/wms.php:151`). Test-authored: owner release → 200 + `pending` + `claimed_by` NULL.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal text | Always `"Picking claim released successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (`status` = `pending`, claim fields NULL) | Full detail keys (see show doc); `quantity_picked` progress preserved |

```json
{
  "status": 200,
  "message": "Picking claim released successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "pending",
    "claimed_by": null,
    "quantity_picked": "0.00",
    "op_seq": 0,
    "scan_log": [],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. The 422 uses the EXCEPTION envelope `{message, status: false}` (code-verified). NOTE the 403-foreign shape: the service's `claimed_other` text is DISCARDED — the controller converts it to a bare `AuthorizationException` (catalogue text).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (controller verbatim discarded by Handler — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse release (code-traced) | See 403-scope example |
| 403 | `override` intent WITHOUT `fulfillment-override` permission (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Unauthorized override (code-traced) | See 403-override example |
| 403 | Caller is NOT the claimant and no override (service `claimed_other` DISCARDED by design — bare `AuthorizationException`, code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Foreign release attempt (test-authored 403 with the claim provably surviving) | See 403-foreign example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | TERMINAL task (outside `pending/assigned/picking` — defensive read gate preserving completed work's claim state; code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.picking.release_terminal` WITH id + status: `en` → `"Picking task #1 is terminal (status: picked): release refused"` (ids illustrative; verified in `resources/lang/en/wms.php:159`) | None | Picked/skipped/cancelled task (test-authored 422 with `picked` provably preserved) | See 422 example |
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

403-override example (catalogue text, code-verified):

```json
{
  "message": "This action is unauthorized.",
  "status": false
}
```

403-foreign example (catalogue text — the `claimed_other` service text never surfaces, code-verified):

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

422 example (translated WITH id + status, no map):

```json
{
  "message": "Picking task #1 is terminal (status: picked): release refused",
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

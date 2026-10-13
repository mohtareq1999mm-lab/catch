# Endpoint Name

Picking Tasks – Claim Task (`POST /api/v1/admin/picking-tasks/{id}/claim`)

# Description

Claims one live picking task for the caller with override-gated steals, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/claim` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/claim` custom `Route::post(...)->name('api.admin.picking-tasks.claim')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@claim(Request $request, int $id)` — NO request validation (plain `Request`; forged `claimed_by`/`actor_id` fields IGNORED — the authenticated caller ALWAYS wins, test-verified); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task-chain-warehouse, 'picking-execute')` (verbatim-403 path — cross-warehouse claims leave `pending` + `claimed_by` NULL, test-verified); TERMINAL tasks (anything outside pending/assigned/picking) refuse FIRST with 422 live text (code-verified defensive gate); then `execution->claim(task, actorId, null, override)` — contention `RuntimeException` → 409 (first winner provably retained, test-verified); replay by the holder → 200 unchanged (test-verified). OVERRIDE intent (`override` truthy) WITHOUT `fulfillment-override` permission → 403 `AuthorizationException` (never silent elevation — test-verified holder-retained); WITH it → allowed when service state permits (test-verified steal) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe (no constructor/inline check exists besides the override sub-gate) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body fields are honored (`override` is the SOLE read key — everything else ignored); `Content-Type` is functionally irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.picking-tasks.claim` (explicit named route) |

## Request inputs

No `FormRequest` — the ONLY body key with any effect is `override` (everything else, including forged identity fields, is dead input; code-verified + test-proven).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (BECOMES the claimant — forged ids ignored) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be LIVE (pending/assigned/picking) for success | Live task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `override` | body | boolean-ish | Optional | No | `$request->boolean('override')` truthiness; true WITHOUT `fulfillment-override` permission → 403 (never silent elevation) | Truthy values | `false` | None | `true` | Steal intent (requires the override permission) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/claim",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: task claimed — HTTP 200 (DETAIL resource, holder recorded)

Returned via `ApiResponse::apiResponse('Picking task claimed successfully.', 200, true, PickingTaskDetailResource::make($detail))` (literal message). Verified: first claim 200 + holder + `assigned` proofs; replay 200 unchanged; override-steal 200 with new holder (all `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including replays and steals) |
| `message` | string | Always present | Literal (untranslated) | Always `"Picking task claimed successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (`claimed_by` = caller) | Full detail keys (see show doc) |

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

No error-code field; every error below has Error code `None`. No validation, so the 422s carry live text with NO `errors` map.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced; E2E twins pin 401-adjacent guest denials on this family) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (verbatim, state provably `pending` + `claimed_by` NULL) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse claim (verified → 403 with untouched proofs) | See 403-scope example |
| 403 | `override` intent WITHOUT `fulfillment-override` permission (explicit anti-elevation — holder provably retained) | `{message: string, status: boolean}` | None | `"Override requires fulfillment-override permission."` (verbatim `AuthorizationException` text WITH period) | None | Unauthorized steal (verified → 403 + holder proof) | See 403-override example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 409 | Live contention lost (another holder won the race — first winner provably retained) | `{message: string, status: boolean}` (NO `errors` map — `conflict()`-thrown `HttpException`) | None | Live engine text (exact string engine-dependent) | None | Contested claim (verified → 409 + first-winner proof) | See 409 example |
| 422 | TERMINAL task (outside pending/assigned/picking — defensive read gate, checked BEFORE the service) | `{message: string, status: boolean}` (NO `errors` map) | None | `"Picking task #1 is terminal (status: picked): claim refused"` (FULL live text with id + status — code-verified verbatim shape, ids illustrative) | None | Picked/skipped/cancelled task (verified → 422 on a `picked` row) | See 422 example |
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

403-scope example (verbatim, untouched):

```json
{
  "message": "Forbidden warehouse operation.",
  "status": false
}
```

403-override example (verbatim WITH period):

```json
{
  "message": "Override requires fulfillment-override permission.",
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

409 example (live text, first winner retained):

```json
{
  "message": "Live engine conflict text (exact string engine-dependent)",
  "status": false
}
```

422 example (FULL live text, no map):

```json
{
  "status": 422,
  "message": "Picking task #1 is terminal (status: picked): claim refused",
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

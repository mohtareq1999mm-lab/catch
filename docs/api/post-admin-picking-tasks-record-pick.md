# Endpoint Name

Picking Tasks – Record Pick (`POST /api/v1/admin/picking-tasks/{id}/record-pick`)

# Description

Records a directly-entered pick quantity (same guards as confirm, plus op_seq replay protection and batch auto-advance), returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/record-pick` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/record-pick` custom `Route::post(...)->name('api.admin.picking-tasks.record-pick')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@recordPick(RecordPickRequest $request, int $id)` → validated payload → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→item→fulfillment warehouse, 'picking-execute', asNotFound: FALSE)`; then `BatchPickingService::recordPick(task, quantity, notes, actorId, override, op_seq)` — ANY `\Exception` → 422 with the message (narrow by design: no other fallible work here — code-verified). Service guards, all under lock (code-verified): op_seq replay (`> 0` and already applied → current state, NO double-count — test-authored with watermark proof); terminal (outside `assigned/picking`) → 422 `record_pick_status`; non-claimant WITHOUT override → 422 `claimed_other` (NOTE: 422 here, unlike release's 403 — code-verified split); over-remaining → 422 `pick_exceeds`; `≤ 0` → 422 `pick_positive` (defensive post-validation — unreachable via HTTP since `gt:0` rejects first, code-verified). Completion advances `picked`/`picked_at`, saves `notes`, increments the item, and auto-advances the batch (may complete the batch and move fully-picked fulfillments — code-verified). `override` intent WITHOUT `fulfillment-override` → 403 (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe + scope check (override additionally needs `fulfillment-override`) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.picking-tasks.record-pick` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps — only `index`/`claim`/`confirm` are name-pinned in `WmsRbacFoundationTest`) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 403-override → 422-business → 200.

## Request inputs

`RecordPickRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (must hold the claim unless overriding) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `quantity` | body | number | Required | No | `required\|numeric\|gt:0` | `> 0` | None | `> remaining` → 422 at the service | `1` | Units picked (test-authored partial/additive progression) |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:2000` | Any string or null | `null` | Saved on the task when non-empty (sent as `"torn carton"` in tests; persistence code-verified, not assertion-verified) | `"torn carton"` | Operator note |
| `op_seq` | body | integer | Optional | Yes | `nullable\|integer\|min:0` | `≥ 0` | `0` (keyless path records normally) | Already-applied value → idempotent 200 replay | `7` | Device op sequence (test-authored replay proof incl. watermark `7`) |
| `override` | body/query | boolean-ish | Optional | No | `sometimes\|boolean` + `$request->boolean()`; true WITHOUT `fulfillment-override` → 403 | Truthy values | `false` | None | `true` | Bypass claimant guard (requires the override permission) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/record-pick",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "quantity": 1,
    "notes": "torn carton",
    "op_seq": 7
  }
}
```

# Responses

## Success: pick recorded — HTTP 200 (DETAIL resource, quantities advanced, batch progressed)

Returned via `ApiResponse::apiResponse(__('wms.picking.recorded'), 200, true, PickingTaskDetailResource…)` — translated at the call site (`en` → `"Pick recorded successfully."`, verified in `resources/lang/en/wms.php:153`). Test-authored: partial → `picking`; additive second call → `picked`; replay → 200 unchanged with watermark kept.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including op_seq replays) |
| `message` | string | Always present | Literal text | Always `"Pick recorded successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Pick recorded successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "picking",
    "quantity_to_pick": "2.00",
    "quantity_picked": "1.00",
    "remaining": 1,
    "claimed_by": 7,
    "op_seq": 7,
    "scan_log": [],
    "notes": "torn carton"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); business refusals use the EXCEPTION envelope `{message, status: false}` (code-verified). All four business texts live under `wms.batch.*` (NOT `wms.picking.*` — code-verified namespace split, since `BatchPickingService` owns this path).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse record (code-traced) | See 403-scope example |
| 403 | `override` intent WITHOUT `fulfillment-override` permission (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Unauthorized override (code-traced) | See 403-override example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing/non-numeric/`≤ 0` quantity, over-long notes (code-traced) | See 422-validation example |
| 422 | Terminal task (outside `assigned/picking`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.record_pick_status`: `en` → `"Cannot record pick on task #1 in status picked"` (ids illustrative; verified in `resources/lang/en/wms.php:49`) | None | Terminal task (code-traced) | See 422-status example |
| 422 | Non-claimant WITHOUT override (service claimant rule — 422 here, NOT 403 like release; code-verified split) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.claimed_other`: `en` → `"Picking task #1 is claimed by another worker"` (ids illustrative; verified in `resources/lang/en/wms.php:50`) | None | Foreign record attempt (test-authored 422) | See 422-claimant example |
| 422 | Quantity exceeds remaining | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.pick_exceeds`: `en` → `"Picked quantity (99) exceeds remaining quantity (2)"` (values illustrative; verified in `resources/lang/en/wms.php:51`) | None | Over-pick (test-authored 422 on `99`) | See 422-exceeds example |
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
    "quantity": ["The quantity must be greater than 0."]
  }
}
```

422-status example (translated, no map):

```json
{
  "message": "Cannot record pick on task #1 in status picked",
  "status": false
}
```

422-claimant example (translated, no map):

```json
{
  "message": "Picking task #1 is claimed by another worker",
  "status": false
}
```

422-exceeds example (translated, no map):

```json
{
  "message": "Picked quantity (99) exceeds remaining quantity (2)",
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

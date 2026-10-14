# Endpoint Name

Picking Tasks – Confirm Pick (`POST /api/v1/admin/picking-tasks/{id}/confirm`)

# Description

Confirms a scanned pick (WHERE + WHAT + HOW MUCH validated, quantities advanced, op_seq replay-safe), returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/confirm` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/confirm` custom `Route::post(...)->name('api.admin.picking-tasks.confirm')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@confirm(ConfirmPickRequest $request, int $id)` → validated scan payload → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→item→fulfillment warehouse, 'picking-execute', asNotFound: FALSE)` (cross-warehouse → 403 catalogue with picked quantity provably `0`, test-authored); then `PickingExecutionService::confirm(task, scan, actorId, override)` — `RuntimeException` (incl. `PickingValidationException` + `UnknownBarcodeException`) → 422 (code-verified). Scan semantics (code-verified): `location` stays a RAW string (barcode/code); `product` stays a RAW string (numeric IDs AND SKU/barcode strings accepted — an `(int)` cast here would destroy string SKUs, controller-commented); `quantity` → `(float)`; `op_seq` → `(int)`, default `0`. Op_seq replay (`0 < op_seq ≤ locked op_seq`) returns current state with NO mutation (test-authored). Completion (`newTotal ≥ quantity_to_pick`) → `picked` + `picked_at`, else `picking`; the fulfillment item's `quantity_picked` increments (test-authored). `override` intent WITHOUT `fulfillment-override` → 403 (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe + scope check (override additionally needs `fulfillment-override`) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.picking-tasks.confirm` (explicit named route; `route:list` verified; name-pinned in `WmsRbacFoundationTest`) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 403-override → 422-scan/business → 200.

## Request inputs

`ConfirmPickRequest` runs before the action (`authorize: true`). All business validation lives in the service (request comment — code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (must hold the claim unless overriding) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `location` | body | string | Required | No | `required\|string\|max:255` (unknown/wrong values 422 at the SERVICE, not validation) | Location barcode/code | None | None | `"PA-LOC-01"` | WHERE the picker scanned (test-authored fixture values) |
| `product` | body | mixed | Required | No | `required` (any type; numeric ID or SKU/barcode string — resolved by the service) | Product id, SKU, or barcode | None | None | `"SKU-PICK-1"` | WHAT was scanned (test-authored fixture values) |
| `quantity` | body | number | Required | No | `required\|numeric\|gt:0` | `> 0` | None | `> remaining` → 422 at the service | `1` | HOW MUCH was picked (test-authored partial/complete progression) |
| `op_seq` | body | integer | Optional | Yes | `nullable\|integer\|min:0` | `≥ 0` | `0` (no replay protection) | Already-applied value → idempotent 200 replay | `2` | Device op sequence (test-authored replay proof) |
| `override` | body/query | boolean-ish | Optional | No | `sometimes\|boolean` + `$request->boolean()`; true WITHOUT `fulfillment-override` → 403 | Truthy values | `false` | None | `true` | Bypass claimant/scan guards (requires the override permission) |

## Request examples

Test-authored call shape:

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/confirm",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "location": "PA-LOC-01",
    "product": "SKU-PICK-1",
    "quantity": 1,
    "op_seq": 2
  }
}
```

# Responses

## Success: pick confirmed — HTTP 200 (DETAIL resource, quantities advanced)

Returned via `ApiResponse::apiResponse(__('wms.picking.confirmed'), 200, true, PickingTaskDetailResource…)` — translated at the call site (`en` → `"Pick confirmed successfully."`, verified in `resources/lang/en/wms.php:152`). Test-authored: partial → `picking` + `quantity_picked`; replay → 200 with `quantity_picked` provably unchanged (`scan_log`/`op_seq` effects not asserted); completing → `picked` + item incremented.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including op_seq replays) |
| `message` | string | Always present | Literal text | Always `"Pick confirmed successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` | Full detail keys (see show doc); `scan_log` gains a `confirm` entry |

```json
{
  "status": 200,
  "message": "Pick confirmed successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "picking",
    "quantity_to_pick": "2.00",
    "quantity_picked": "1.00",
    "remaining": 1,
    "claimed_by": 7,
    "op_seq": 1,
    "scan_log": [],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); scan/business refusals use the EXCEPTION envelope `{message, status: false}` (code-verified). Scan-failure messages share ONE template — `"Picking validation failed: :reason."` (`wms.picking.validation_failed`, verified in `resources/lang/en/wms.php:169`) — with a stable machine-code reason (`task_not_claimed`, `task_claimed_by_other`, `wrong_location`, `wrong_product`, `stale_placement`, `invalid_quantity`; code-verified `reject()` call sites; only the sentence is translated).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse confirm (test-authored 403 with picked quantity provably `0`) | See 403-scope example |
| 403 | `override` intent WITHOUT `fulfillment-override` permission (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Unauthorized override (code-traced) | See 403-override example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing `location`/`product`/`quantity`, `quantity ≤ 0`, `op_seq < 0` (code-traced) | See 422-validation example |
| 422 | Scan/business refusal (unclaimed task, foreign claim, wrong location/product, stale placement, bad quantity, unknown barcode) | `{message: string, status: boolean}` (NO `errors` map) | None | `"Picking validation failed: <reason>."` or `"Unknown barcode: <code>."` (`wms.barcode.unknown`, verified in `resources/lang/en/wms.php:286`; code capped at 200 chars — code-verified) | None | Test-authored: unclaimed → 422 untouched; wrong location → 422; over-pick → 422; unknown barcode → 422 | See 422-scan example |
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

422-scan example (machine-code reason, no map):

```json
{
  "message": "Picking validation failed: wrong_location.",
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

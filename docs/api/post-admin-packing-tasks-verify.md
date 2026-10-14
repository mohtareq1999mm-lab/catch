# Endpoint Name

Packing Tasks – Verify Task (`POST /api/v1/admin/packing-tasks/{id}/verify`)

# Description

Verifies one packed task (no open sibling + every picked unit fully packaged), advancing the fulfillment to `ready_to_ship`, returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packing-tasks/{id}/verify` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packing-tasks/{id}/verify` custom `Route::post(...)->name('api.admin.packing-tasks.verify')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:packing.complete` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingController@verify(VerifyPackingRequest $request, int $id)` → validated notes → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→fulfillment warehouse, 'packing.complete', asNotFound: FALSE)`; then `PackingService::verifyPacking(task, notes)` — `RuntimeException` → 422 (code-verified). Service, one transaction with lock order task → fulfillment → siblings → packages → items (code-commented): status MUST be exactly `packed` (else 422 `verify_status`); any SIBLING open task (`pending/assigned/packing/packed`, excluding self) → 422 `verify_open_sibling`; every fulfillment item MUST satisfy `SUM(non-voided package_items.quantity) == quantity_picked` (read-only math — code-verified) else 422 `verify_quantities` on the first incomplete item; else `verified` + `verified_at` + notes, fulfillment transitions to `ready_to_ship` via the single transition owner + order-sync announce (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `packing.complete` via the route pipe + scope check (a `packing-execute`-only packer gets 403 here — test-authored permission split) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packing-tasks.verify` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-state/sibling/quantities → 200.

## Request inputs

`VerifyPackingRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the supervisor |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be `packed` with full packaging for success | Packed task id manageable by the caller | None | None | `1` | Identifies the packing task |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:2000` | Any string or null | `null` (keeps existing notes) | Stored on the task (`notes ?? locked.notes` — code-verified) | `"ok"` | Verification note (test-authored) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packing-tasks/1/verify",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "notes": "ok"
  }
}
```

# Responses

## Success: packing verified — HTTP 200 (DETAIL resource, fulfillment advanced)

Returned via `ApiResponse::apiResponse(__('wms.packing.verified'), 200, true, PackingTaskDetailResource…)` — translated at the call site (`en` → `"Packing verified successfully."`, verified in `resources/lang/en/wms.php:99`). Test-authored: 200 + task `verified` + fulfillment `ready_to_ship`; order/inventory/shipment rows provably untouched (only the fulfillment moves).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.verified` | `en` → `"Packing verified successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackingTaskDetailResource` (`status` = `verified`, `verified_at` set) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Packing verified successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "verified",
    "verified_at": "2026-10-14T10:20:00+00:00",
    "notes": "ok"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); verification refusals use the EXCEPTION envelope `{message, status: false}` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `packing.complete` (route pipe — the packer/executor split) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | `packing-execute`-only packer (test-authored 403) | See 403 example |
| 403 | Out-of-scope `packing.complete` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse verify (code-traced) | See 403-scope example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure (over-long notes) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | `notes > 2000` chars (code-traced) | See 422-validation example |
| 422 | Wrong state (anything but `packed`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.verify_status`: `en` → `"Cannot verify packing task in status: packing"` (status illustrative; verified in `resources/lang/en/wms.php:116`) | None | Unpacked task (code-traced) | See 422-state example |
| 422 | Another open sibling task on the same fulfillment | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.verify_open_sibling` WITH task + fulfillment ids: `en` → `"Cannot verify packing task #1: fulfillment #11 has another open packing task"` (ids illustrative; verified in `resources/lang/en/wms.php:117`) | None | Multi-task fulfillment (code-traced) | See 422-sibling example |
| 422 | Incomplete packaging (some picked unit not yet in a non-voided package) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.verify_quantities`: `en` → `"Cannot verify packing: fulfillment item #5 picked 2, packed 0"` (values illustrative; verified in `resources/lang/en/wms.php:118`) | None | Nothing packaged yet (test-authored 422 with fulfillment provably still `packing`) | See 422-quantities example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (missing permission — the packer split):

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

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "notes": ["The notes must not be greater than 2000 characters."]
  }
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot verify packing task in status: packing",
  "status": false
}
```

422-sibling example (translated WITH ids, no map):

```json
{
  "message": "Cannot verify packing task #1: fulfillment #11 has another open packing task",
  "status": false
}
```

422-quantities example (translated, no map):

```json
{
  "message": "Cannot verify packing: fulfillment item #5 picked 2, packed 0",
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

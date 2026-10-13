# Endpoint Name

Fulfillments – Create Packing Task (`POST /api/v1/admin/fulfillments/{id}/create-packing-task`)

# Description

Opens one packing task for a picked fulfillment with single-open-task protection, returning 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/fulfillments/{id}/create-packing-task` (explicit `{id}` placeholder with `whereNumber('id')` — the FULFILLMENT id; code-verified) |
| Path composition | `api/v1` prefix + `admin/fulfillments/{id}/create-packing-task` from the SECOND `Route::prefix('admin/fulfillments')` group (P9-6 packing surface — a separate group block from the fulfillment reads/commands at the top of `Routes.php`; same `['api', 'auth:sanctum', 'throttle:admin']` middleware) with route-level `permission:packing-execute` (NOT a fulfillment permission — a packing permission on a fulfillment path; code-verified) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingController@createTask(int $id)` — NO request validation and NO body read at all; `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(warehouse_id, 'packing-execute')` (verbatim-403 path — foreign fulfillments refuse with the row provably untouched, test-verified); then `PackingService::createPackingTaskFromFulfillment`: wrong-status fulfillment → `RuntimeException` → 422 (a freshly-released row refuses — test-verified with single-task-count proof); SECOND open task → typed `WmsConflictException` → 409 with NO duplicate row (test-verified); any other failure → 422. Success flips the fulfillment to `packing` (test-verified) → 201 with the task detail (+ slim station when assigned) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `packing-execute` via the route pipe (no constructor/inline check exists — the pipe alone decides) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.create-packing-task` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be in `picked` state (earlier states refuse — test-verified) | Picked fulfillment id manageable by the caller | None | None | `1` | Identifies the fulfillment (NOT an order or task) |

No query parameters, body fields, or validation-driven `422` (the 422 below is the service outcome, not validation).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/1/create-packing-task",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: packing task opened — HTTP 201

Returned via `ApiResponse::apiResponse(__('wms.packing.created'), 201, true, PackingTaskDetailResource::make($fresh-with-station))` (translated message). Verified by `test_create_packing_task_guards_status_and_single_open_task` (201 + `data.id` + fulfillment flipped to `packing`, `postJson` with `[]`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `wms.packing.created` | `en` → `"Packing task created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Packing-task DETAIL resource (keys verified) | `id`, `fulfillment_id`, `packing_station_id`, `station{id, code, name, warehouse_id}` (when loaded — unassigned at creation, so typically absent), `assigned_to`, `status`, `weight` (float/null), `dimensions`, `package_materials`, `notes`, `assigned_at`, `started_at`, `packed_at`, `verified_at`, `created_at`, `updated_at` |

```json
{
  "status": 201,
  "message": "Packing task created successfully.",
  "success": true,
  "data": {
    "id": 1,
    "fulfillment_id": 1,
    "packing_station_id": null,
    "assigned_to": null,
    "status": "pending",
    "weight": null,
    "dimensions": null,
    "package_materials": null,
    "notes": null,
    "assigned_at": null,
    "started_at": null,
    "packed_at": null,
    "verified_at": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so the 422s carry live text with NO `errors` map.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Empty body by a guest (verified by the packing 401 matrix on this exact path) | See 401 example |
| 403 | Authenticated caller lacks `packing-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced; same pipe as the picking family) | See 403 example |
| 403 | Out-of-scope `packing-execute` holder at the SCOPE layer (verbatim) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Foreign fulfillment (verified pattern on the sibling writes; cross-warehouse create-task twin asserts 403 on the same action family) | See 403-scope example |
| 404 | No fulfillment with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 409 | An OPEN packing task already exists (at-most-one contention — typed `WmsConflictException`, locale-proof; NO duplicate row) | `{message: string, status: boolean}` (NO `errors` map — `conflict()`-thrown `HttpException`) | None | Live engine text (exact string engine-dependent) | None | Second open task (verified → 409 + single-row proof) | See 409 example |
| 422 | Fulfillment in the WRONG status (only `picked` rows may open packing — service refusal) | `{message: string, status: boolean}` (NO `errors` map) | None | Live `wms.packing.create_status` text with the status (e.g. `en` → `"Cannot create packing task from fulfillment in status: pending"`) | None | Freshly-released row (verified → 422 with count proof) | See 422 example |
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

409 example (live text, no duplicate row):

```json
{
  "message": "Live contention text (exact string engine-dependent)",
  "status": false
}
```

422 example (live text with status, no map):

```json
{
  "message": "Cannot create packing task from fulfillment in status: pending",
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

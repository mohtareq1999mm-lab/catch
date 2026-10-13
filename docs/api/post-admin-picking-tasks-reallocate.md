# Endpoint Name

Picking Tasks – Reallocate Task (`POST /api/v1/admin/picking-tasks/{id}/reallocate`)

# Description

Moves one task to another same-warehouse placement with foreign targets refused, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/reallocate` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/reallocate` custom `Route::post(...)->name('api.admin.picking-tasks.reallocate')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@reallocate(ReallocateTaskRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST — code-verified order); `authorizeWarehouseScope(task-chain-warehouse, 'picking-execute')` (cross-warehouse untested on THIS action — the skip twin proves the shape; code-traced here); then `execution->reallocateTask(task, product_location_id)` — foreign-WAREHOUSE targets refused by the DOMAIN guard → 422 live (test-verified with a same-warehouse 200 + pin proof first); `RuntimeException` → 422 live. NOTE: only the TARGET's warehouse is domain-checked — the task-side scope check is the sole task guard (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe (no constructor/inline check exists besides the override sub-gate, which this action does NOT consult — no `resolveOverride` call here; code-verified absence) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.picking-tasks.reallocate` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200/422-engine.

## Request inputs

`ReallocateTaskRequest` runs before the body (single `product_location_id` rule — warehouse-match is a DOMAIN check, not validation; code-verified split).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `product_location_id` | body | integer | Required | No | `required\|integer\|exists:product_locations,id` (row must exist; SAME-warehouse match enforced by the domain guard, not here) | Existing placement id in the task's warehouse | None | None | `31` | New placement for the task |

## Request examples

Valid request moving within the warehouse (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/picking-tasks/1/reallocate",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "product_location_id": 31
  }
}
```

# Responses

## Success: task reallocated — HTTP 200

Returned via `ApiResponse::apiResponse('Picking task reallocated successfully.', 200, true, PickingTaskDetailResource::make($detail))` (literal message). Verified by `test_reallocate_moves_within_warehouse_and_refuses_foreign_target` (200 + pin proof, `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Picking task reallocated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (new placement reflected) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Picking task reallocated successfully.",
  "success": true,
  "data": {
    "id": 1,
    "product_location_id": 31,
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
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (verbatim) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse reallocation (code-traced shape) | See 403-scope example |
| 404 | No task with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `ReallocateTaskRequest` fails (missing/unknown `product_location_id`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Target placement in a DIFFERENT warehouse (domain guard — NOT validation; code-verified split) | `{message: string, status: boolean}` (NO `errors` map — `RuntimeException` → `unprocessable()`) | None | Live engine text (exact string engine-dependent) | None | Foreign target (verified → 422 on the foreign twin) | See 422-engine example |
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

422-validation example:

```json
{
  "message": "The product location id field is required.",
  "status": false,
  "errors": {
    "product_location_id": ["The product location id field is required."]
  }
}
```

422-engine example (live text, no map):

```json
{
  "message": "Live domain-guard text (exact string engine-dependent)",
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

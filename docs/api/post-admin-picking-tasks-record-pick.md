# Endpoint Name

Picking Tasks – Record Pick (`POST /api/v1/admin/picking-tasks/{id}/record-pick`)

# Description

Records a batch pick additively with claimant enforcement and no idempotency flag.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/record-pick` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/record-pick` custom `Route::post(...)->name('api.admin.picking-tasks.record-pick')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@recordPick(RecordPickRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST — code-verified order); `authorizeWarehouseScope(task-chain-warehouse, 'picking-execute')` (cross-warehouse untested on THIS action — the confirm twin proves the shape; code-traced here); then `batches->recordPick(task, quantity, notes, actorId, override)` — the service signals business refusals with BASE `\Exception` (caught WHOLESALE: `catch (\Exception)` → 422 with the message — "narrow by design" per the comment, since this endpoint performs no other fallible work; code-verified): non-claimant-without-override → 422 (service claimant rule — test-verified); over-pick → 422 (test-verified). VALID picks are ADDITIVE and NEVER idempotent-by-flag (two identical 1-unit calls advance 1→2 and flip `picking`→`picked` — test-verified; compare confirm's `op_seq` replay protection, which this endpoint LACKS — code-verified difference) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe (no constructor/inline check exists besides the override sub-gate) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` (batch pick payload — NO scanner location/product fields, unlike confirm) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.picking-tasks.record-pick` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200/403-override/422-engine.

## Request inputs

`RecordPickRequest` runs before the body (`authorize: true` — the gates above already ran).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (claimant-checked service-side) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `quantity` | body | number | Required | No | `required\|numeric\|gt:0` (strictly positive; over-REMAINING refused service-side — test-verified) | Any number > 0 | None | None | `1` | Picked amount (ADDITIVE — repeats accumulate, code-verified) |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:2000` | Any string or null | Service default | None | `"torn carton"` | Pick notes (test-verified passthrough) |
| `override` | body | boolean | Optional | No | `sometimes\|boolean` (truthy WITHOUT `fulfillment-override` permission → 403 via `resolveOverride`; shared helper — code-verified) | Truthy values | `false` | None | `false` | Override intent |

## Request examples

Valid batch pick (test-verified call):

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
    "notes": "torn carton"
  }
}
```

# Responses

## Success: pick recorded — HTTP 200 (additive progress, mirrored state)

Returned via `ApiResponse::apiResponse('Pick recorded successfully.', 200, true, PickingTaskDetailResource::make($detail))` (literal message). Verified: first unit 200 staying `picking`; second unit 200 flipping `picked` (both `postJson` — additive, never flag-idempotent).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Pick recorded successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (progress state as of this record) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Pick recorded successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "picking",
    "quantity_to_pick": 2,
    "quantity_picked": 1,
    "remaining": 1.0,
    "op_seq": 0,
    "scan_log": [],
    "notes": "torn carton"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (verbatim) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse record (code-traced shape) | See 403-scope example |
| 403 | `override` intent WITHOUT `fulfillment-override` permission (shared sub-gate) | `{message: string, status: boolean}` | None | `"Override requires fulfillment-override permission."` (verbatim WITH period) | None | Unauthorized override (same helper as claim/confirm — code-verified) | See 403-override example |
| 404 | No task with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `RecordPickRequest` fails (missing/non-positive `quantity`, overlong `notes`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Service business refusal — non-claimant-without-override, over-pick, or ANY other service `Exception` (wholesale catch BY DESIGN: "this endpoint performs no other fallible work" — source-commented; unexpected failures ALSO surface here as 422, code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Live engine text (exact strings engine-dependent) | None | Refused record (non-claimant + over-pick BOTH verified → 422) | See 422-engine example |
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

422-validation example:

```json
{
  "message": "The quantity field must be greater than 0.",
  "status": false,
  "errors": {
    "quantity": ["The quantity field must be greater than 0."]
  }
}
```

422-engine example (live text, no map):

```json
{
  "message": "Live service refusal text (exact string engine-dependent)",
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

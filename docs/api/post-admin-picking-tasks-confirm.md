# Endpoint Name

Picking Tasks – Confirm Pick (`POST /api/v1/admin/picking-tasks/{id}/confirm`)

# Description

Confirms a scanner pick with replay-safe sequencing and additive quantities, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/picking-tasks/{id}/confirm` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}/confirm` custom `Route::post(...)->name('api.admin.picking-tasks.confirm')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@confirm(ConfirmPickRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST — code-verified order); `authorizeWarehouseScope(task-chain-warehouse, 'picking-execute')` (verbatim-403 path — cross-warehouse confirms mutate NOTHING, test-verified); then `execution->confirm` with the scanner payload (`location`/`product` cast to string, `quantity` to float, `op_seq` default 0) + server-derived actor + override resolution — ALL business validation lives in the service (source-commented): unclaimed-without-override → 422 NOTHING mutated (test-verified); wrong location / over-pick / unknown barcode (`UnknownBarcodeException`) → 422 (test-verified triple); `RuntimeException` family (incl. BOTH scan exception types) → 422 live. PROGRESS semantics (test-verified): partial confirms keep `picking` with additive quantities; SAME `op_seq` replays to the SAME result with NO duplicate mutation (idempotent-by-sequence); completing confirms flip to `picked` AND mirror onto the fulfillment item |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe (no constructor/inline check exists besides the override sub-gate) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` (scanner payload) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.picking-tasks.confirm` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200/403-override/422-engine.

## Request inputs

`ConfirmPickRequest` runs before the body (`authorize: true` — the gates above already ran). Scanner-shape rules only; every semantic lives service-side.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (claimant-checked service-side) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Task id manageable by the caller | None | None | `1` | Identifies the picking task |
| `location` | body | string | Required | No | `required\|string\|max:255` (WHERE-scan: location barcode/code — matched service-side, NOT validated here) | Any string | None | None | `"PA-LOC-01"` | Scanned location |
| `product` | body | string | Required | No | `required\|string\|max:255` (WHAT-scan: product or variant SKU — matched service-side) | Any string | None | None | `"SKU-PICK-1"` | Scanned product |
| `quantity` | body | number | Required | No | `required\|numeric\|gt:0` (strictly positive; over-pick refused service-side — test-verified) | Any number > 0 | None | None | `1` | Picked amount (ADDITIVE across confirms — never idempotent-by-flag except `op_seq` replay) |
| `op_seq` | body | integer | Optional | Yes | `nullable\|integer\|min:0` (device op sequence for REPLAY protection — same seq replays to the same result, test-verified) | Integer ≥ 0 | `0` | None | `1` | Scanner sequence number |
| `override` | body | boolean | Optional | No | `sometimes\|boolean` (truthy WITHOUT `fulfillment-override` permission → 403 via `resolveOverride`; same sub-gate as claim — code-verified shared helper) | Truthy values | `false` | None | `false` | Override intent |

## Request examples

Valid scanner confirm (test-verified call):

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
    "op_seq": 1
  }
}
```

# Responses

## Success: pick confirmed — HTTP 200 (additive progress, mirrored quantities)

Returned via `ApiResponse::apiResponse('Pick confirmed successfully.', 200, true, PickingTaskDetailResource::make($detail))` (literal message). Verified: partial 200 staying `picking` with `quantity_picked: 1`; same-`op_seq` replay 200 with UNCHANGED quantity (no duplicate mutation); completing 200 flipping `picked` AND mirroring `fulfillmentItem.quantity_picked` (all `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (partials, replays, completions alike) |
| `message` | string | Always present | Literal (untranslated) | Always `"Pick confirmed successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PickingTaskDetailResource` (progress state as of this confirm) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Pick confirmed successfully.",
  "success": true,
  "data": {
    "id": 1,
    "status": "picking",
    "quantity_to_pick": 2,
    "quantity_picked": 1,
    "remaining": 1.0,
    "op_seq": 1,
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
| 403 | Out-of-scope `picking-execute` holder at the SCOPE layer (verbatim, NOTHING mutated) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse confirm (verified → 403 + zero-quantity proof) | See 403-scope example |
| 403 | `override` intent WITHOUT `fulfillment-override` permission (shared `resolveOverride` sub-gate — never silent elevation) | `{message: string, status: boolean}` | None | `"Override requires fulfillment-override permission."` (verbatim WITH period) | None | Unauthorized override (same helper as claim — code-verified) | See 403-override example |
| 404 | No task with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `ConfirmPickRequest` fails (missing/non-positive `quantity`, missing scans) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Service scan/business refusal (unclaimed-without-override, wrong location, over-pick, unknown barcode — ALL with NOTHING mutated; code-verified) | `{message: string, status: boolean}` (NO `errors` map — `unprocessable()`-thrown `HttpException`) | None | Live engine text (exact strings engine-dependent) | None | Refused scan (unclaimed + wrong-location + over-pick + unknown-barcode ALL verified → 422 with zero-mutation proofs) | See 422-engine example |
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

403-scope example (verbatim, nothing mutated):

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
  "message": "Live scan/business refusal text (exact string engine-dependent)",
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

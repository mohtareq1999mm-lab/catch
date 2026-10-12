# Endpoint Name

Fulfillments – Release Fulfillment (`POST /api/v1/admin/fulfillments/release`)

# Description

Releases one pending fulfillment for an order with replay-safe 201/200 semantics.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/fulfillments/release` |
| Path composition | `api/v1` prefix + `admin/fulfillments/release` custom `Route::post(...)->name('api.admin.fulfillments.release')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:fulfillment.create` (DOTTED name — code-verified, same family as `fulfillment.cancel`) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\FulfillmentController@release(ReleaseFulfillmentRequest $request)`: resolve-then-scope — explicit `warehouse_id` is scope-checked directly (FOREIGN target → verbatim 403 with ZERO rows, test-verified); ABSENT `warehouse_id` resolves the DEFAULT warehouse via the service (test-verified defaulting + `status`-tampering ignored — `status` has NO rule and never persists); unknown warehouse for an AUTHORIZED caller → 404 fixed (runs AFTER scope); unknown order → 404 with the CANONICAL `{status: false}` envelope (explicitly "never a business-rule refusal" — test-verified). Then a READ-ONLY replay probe (keyed lookup, else pending order+warehouse reuse — mirrors the service WITHOUT locking) sets the 200/201 EXPECTATION; the SERVICE stays the idempotency authority: keyed replays AND keyless pending-reuse both return 200 SAME row (test-verified, single row); fresh rows return 201 `pending` (test-verified + DB proof). Lost-race safety: MySQL-1062 duplicate-key on the idempotency UNIQUE backstop reloads the winner as a 200 replay, while ANY other `QueryException` (or a keyless call) rethrows → `Handler` 409 database-error — never a false success (BOTH paths test-pinned with mocked services). Domain refusals (unreleasable order, second-warehouse guard) → 422 live with ZERO rows (test-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `fulfillment.create` via the route pipe (a `view-fulfillment`-only caller fails it — test-verified 403 on release for viewers) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.release` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 403-scope → 404 (warehouse/order lookups) → 200/201/422-engine/409-race.

## Request inputs

`ReleaseFulfillmentRequest` runs before the body (`authorize: true` — the gates above already ran). `status` is NOT a rule — tampering ignored, never persisted (test-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `order_id` | body | integer | Required | No | `required\|integer` (NO `exists` — a missing order is a 404 RESOURCE miss by design, never a 422; source-commented) | Any integer (existing order for success) | None | None | `42` | The order to fulfill |
| `warehouse_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` | Existing warehouse id | DEFAULT warehouse via service (test-verified) | None | `1` | Target warehouse (explicit values are scope-checked) |
| `idempotency_key` | body | string | Optional | Yes | `nullable\|string\|max:64\|not_regex:/^auto-release-order-/` (the `auto-release-order-*` namespace is RESERVED for the automatic payment/COD mechanism — operators must never supply it; test-verified 422 + zero rows) | ≤ 64 chars, outside the reserved namespace | None (keyless pending-reuse still applies) | None | `"op-key-1"` | Replay key (UNIQUE backstop + probe) |

## Request examples

Valid request releasing a fulfillment (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/release",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "order_id": 42,
    "warehouse_id": 1
  }
}
```

# Responses

## Success: fulfillment released — HTTP 201 (fresh) or 200 (replay, SAME row)

Fresh rows return 201 `'Fulfillment released successfully.'`; replays (keyed OR keyless pending-reuse OR lost-race winner reload) return 200 `'Fulfillment already released.'` with the IDENTICAL row id (both messages literal; code-verified). Response carries the bounded DETAIL resource (not the slim row — code-verified). Verified: fresh 201 + `pending` + DB proof; default-warehouse 201; keyed replay 200-same-id + single-row proof; keyless replay 200-same-id; 1062-mock replay 200-same-id.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | `201` fresh / `200` replay (a lost race only mistakes the CODE, never the outcome — source-commented) |
| `message` | string | Always present | Literal (untranslated) | `"Fulfillment released successfully."` (201) / `"Fulfillment already released."` (200) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `FulfillmentDetailResource` (bounded detail — NO `allowed_actions`, NO `metadata`; test-pinned absence) | Full detail keys (see show doc for the table) |

```json
{
  "status": 201,
  "message": "Fulfillment released successfully.",
  "success": true,
  "data": {
    "id": 1,
    "fulfillment_number": "FF-000001",
    "order_id": 42,
    "warehouse_id": 1,
    "status": "pending",
    "priority": null,
    "assigned_to": null,
    "items": [],
    "picking_tasks": [],
    "packing_tasks": [],
    "packages": [],
    "shipments": [],
    "cancelled_by": null,
    "cancel_source": null,
    "notes": null,
    "picking_started_at": null,
    "picking_completed_at": null,
    "packing_started_at": null,
    "packing_completed_at": null,
    "ready_to_ship_at": null,
    "shipped_at": null,
    "delivered_at": null,
    "cancelled_at": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Empty body by a guest (verified by the 401 matrix → 401 on release) | See 401 example |
| 403 | Authenticated caller lacks `fulfillment.create` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Homed permissionless user (verified 403); homed viewer (verified 403 on release) | See 403 example |
| 403 | In-scope permission held but FOREIGN `warehouse_id` (resolve-then-scope, verbatim — ZERO rows) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Scoped operator targeting abroad (verified → 403 + zero-row proof); null-home holder (verified → 403 + zero-row proof) | See 403-scope example |
| 404 | Unknown `warehouse_id` for an AUTHORIZED caller (scope passed, lookup missed) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Authorized caller + unknown warehouse (code-traced) | See 404-warehouse example |
| 404 | Unknown `order_id` (uncaught `firstOrFail` → fixed string WITH `status: false` — explicitly "never a business-rule refusal"; code-verified + source-commented) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown order (verified for `999999` → 404 + `status: false` + zero rows) | See 404-order example |
| 409 | Non-duplicate DB failure mid-release (mock-pinned: error 2006 with a key present still surfaces as ERROR — never a false replay; code-verified + test-commented) | `{status: boolean, message: string}` (`Handler` `QueryException` shape — `status: false`, NO integer status; code-verified) | None | `"Database error occurred. Please check your request and try again."` | None | Non-1062 `QueryException` (verified → 409 + `status: false` + zero rows) | See 409 example |
| 422 | `ReleaseFulfillmentRequest` fails (missing/non-integer `order_id`, unknown `warehouse_id`, reserved-namespace key, oversize key) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (reserved namespace verified → 422 + zero rows) | See 422-validation example |
| 422 | Domain refusal (unreleasable order state, second-warehouse guard — live engine text, ZERO rows) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | Live engine text (exact strings engine-dependent) | None | Refused release (both verified → 422 + zero-row proofs) | See 422-engine example |
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

403-scope example (foreign target — verbatim, zero rows):

```json
{
  "message": "Forbidden warehouse operation.",
  "status": false
}
```

404-warehouse example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-order example (fixed string WITH `status: false`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

409 example (non-duplicate DB failure — `status: false`, no integer status):

```json
{
  "status": false,
  "message": "Database error occurred. Please check your request and try again."
}
```

422-validation example:

```json
{
  "message": "The order id field is required.",
  "status": false,
  "errors": {
    "order_id": ["The order id field is required."]
  }
}
```

422-engine example (live text, no map):

```json
{
  "message": "Live engine refusal text (exact string engine-dependent)",
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

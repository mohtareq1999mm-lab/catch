# Endpoint Name

Batches – Create Batch (`POST /api/v1/admin/batches`)

# Description

Creates one wave batch from fulfillments (single warehouse/flow/stage scope; idempotent on `idempotency_key` with 200 replay vs 201 create), returning 201 or 200 with batch detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/batches` (fixed path — no placeholders) |
| Path composition | `api/v1` prefix + `admin/batches` custom `Route::post(...)->name('api.admin.batches.store')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:batch.manage` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@store(CreateBatchRequest $request)` → validated payload → warehouse resolution: explicit `warehouse_id` → `Warehouse::whereKey->firstOrFail` (miss → 404 fixed) ELSE active-default via `resolveForNewFulfillment()` (`RuntimeException` → 422: no-default / deleted / inactive — code-verified); `authorizeWarehouseScope(target, 'batch.manage', asNotFound: FALSE)` (foreign target → 403 with ZERO batches created — test-authored); fulfillments collected by `fulfillment_ids` OR `order_ids` (unknown order ids simply match nothing — NO `exists` rule on `order_ids.*`, code-verified); blank `idempotency_key` trimmed to keyless (code-verified); warehouse-scoped replay probe sets the 200/201 distinction (a foreign-warehouse key NEVER leaks here — code-verified); then `BatchPickingService::createBatchFromFulfillments` — `InvalidArgumentException`/`RuntimeException` → 422, `WmsConflictException` is NOT thrown on this path (open-task conflicts surface as `RuntimeException` → 422, code-verified); duplicate-key `QueryException` with a key → scoped reload as replay (200), anything else rethrows (code-verified). Service, one transaction (code-verified): empty set → 422 `zero_fulfillments`; key replay → original batch (200, skips the pending guard since fulfillments moved on); key bound to ANOTHER warehouse → 422 `key_warehouse`; scope mix (non-`pending` status / warehouse / flow / stage) → 422 `scope_*` (pre-lock AND re-verified under lock — code-verified); vanished row → 422 `row_missing`; item with an open task → 422 `item_open_task`; zero pickable items → 422 `zero_pickable`; else tasks created (remaining quantities, location-grouped, sequenced) + fulfillments moved `pending → picking` via the transition owner. NO partial state survives any refusal (transaction rollback — test-authored zero-batch proofs) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `batch.manage` via the route pipe + scope check (a `batch.operate`-only picker gets 403 with zero batches — test-authored lifecycle split) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.store` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 422-warehouse-setup (default resolution throws BEFORE any lookup/scope — code-verified) → 404-warehouse (explicit miss; TOCTOU-only in practice since `exists` validates first) → 403-scope → 422-empty/replay-mismatch/scope/row/open-task/pickable → 200/201.

## Request inputs

`CreateBatchRequest` runs before the action (`authorize: true`). NOTE the rule asymmetry (code-verified): `fulfillment_ids.*` carries `exists` (unknown ids 422 at validation), `order_ids.*` does NOT (unknown order ids match nothing → downstream `zero_fulfillments` 422).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `fulfillment_ids` | body | array | Required w/o `order_ids` | No | `required_without:order_ids\|array\|min:1\|max:100`, items `integer\|distinct\|exists:fulfillments,id` | 1–100 known pending-fulfillment ids | None | Unknown ids 422 HERE at validation | `[11, 12]` | The fulfillments to batch (test-authored) |
| `order_ids` | body | array | Required w/o `fulfillment_ids` | No | `required_without:fulfillment_ids\|array\|min:1\|max:100`, items `integer\|distinct` (NO `exists` — code-verified) | 1–100 order ids | None | Unknown ids match nothing → `zero_fulfillments` 422 | `[21]` | Alternative selector (fulfillments resolved via `order_id`) |
| `warehouse_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` | Warehouse id or omitted (→ active default) | Active default | Unknown ids 422 at validation; foreign target → 403 with zero batches (test-authored) | `1` | Explicit batch warehouse |
| `type` | body | string | Optional | Yes | `nullable\|in:wave` (HTTP accepts `wave` only — code-verified) | `"wave"` or omitted | `"wave"` | Other values (e.g. `"bulk"`) → 422 (test-authored) | `"wave"` | Batch type |
| `idempotency_key` | body | string | Optional | Yes | `nullable\|string\|min:1\|max:64` (blank trims to keyless — code-verified) | Any string or omitted | Keyless (one call = one batch — test-authored double-POST → two batches) | Replay of a completed key → 200 SAME batch | `"wave-2026-001"` | Replay key (UNIQUE backstop + scoped reload race) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/batches",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "fulfillment_ids": [11, 12]
  }
}
```

# Responses

## Success: batch created (201) or replayed (200) — DETAIL resource

Fresh creation returns 201 with `wms.batch.created`; a completed-key replay returns 200 with `wms.batch.replay` and the SAME batch (service idempotency — controller-commented). Both carry `BatchDetailResource` with sequence-ordered tasks. Test-authored: 201 + `pending` + tasks; fulfillments moved to `picking`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | `201` fresh, `200` replay |
| `message` | string | Always present | Translated `wms.batch.created` / `wms.batch.replay` | `en` → `"Batch created successfully."` / `"Batch already created (idempotent replay)."` (verified in `resources/lang/en/wms.php:23-24`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `BatchDetailResource` | Full detail keys (see show doc); `tasks` non-empty on fresh creates |

```json
{
  "status": 201,
  "message": "Batch created successfully.",
  "success": true,
  "data": {
    "id": 2,
    "batch_number": "BATCH-20261014-ABC123",
    "warehouse_id": 1,
    "status": "pending",
    "type": "wave",
    "assigned_to": null,
    "total_items": 2,
    "picked_items": 0,
    "progress": 0,
    "tasks": [
      {"id": 5, "status": "pending", "sequence": 1},
      {"id": 6, "status": "pending", "sequence": 2}
    ],
    "notes": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); service refusals use the EXCEPTION envelope `{message, status: false}` (code-verified). No 409 exists on this path (open-task conflicts are `RuntimeException` → 422 here — code-verified; the 409s live on retry/assign).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on batch commands) | See 401 example |
| 403 | Authenticated caller lacks `batch.manage` (route pipe — the picker/executor split) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | `picking-execute`-only picker, which lacks `batch.manage` (test-authored 403 + zero batches) | See 403 example |
| 403 | Explicit `warehouse_id` outside the caller's scope (route verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Foreign target warehouse (test-authored 403 + zero batches) | See 403-scope example |
| 404 | Unknown explicit `warehouse_id` (lookup miss — no scope involved yet, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Ghost warehouse id that passed nothing (validation `exists` fires first in practice — this 404 is TOCTOU-only; code-verified) | See 404 example |
| 422 | Body validation failure | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Empty/unknown/duplicate `fulfillment_ids`, `type: bulk` (all test-authored 422) | See 422-validation example |
| 422 | Warehouse setup refusal (no default / deleted / inactive target) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.warehouse.no_default` (`"No default warehouse configured"`) / `deleted` (`"Warehouse #1 (WH-X) is deleted: cannot create new fulfillment"`) / `inactive` (same shape) — verified in `resources/lang/en/wms.php:250-252` | None | Misconfigured warehouse (code-traced) | See 422-warehouse example |
| 422 | Empty set (no fulfillments resolved — incl. unknown `order_ids`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.zero_fulfillments`: `en` → `"Cannot create a batch from zero fulfillments"` (verified in `resources/lang/en/wms.php:38`) | None | Empty input (code-traced) | See 422-empty example |
| 422 | Key bound to ANOTHER warehouse (refuses instead of leaking — code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.key_warehouse`: `en` → `"Idempotency key is already bound to warehouse 2, not 1"` (ids illustrative; verified in `resources/lang/en/wms.php:31`) | None | Cross-warehouse key reuse (code-traced) | See 422-key example |
| 422 | Scope mix — warehouse mix proven by test; status/flow/stage mixes code-traced (re-verified under lock; NO partial state survives — atomic rollback proven by the zero-batch assertion plus the orders/inventory-untouched test) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.scope_status` / `scope_warehouse` / `scope_flow` / `scope_stage`, e.g. `en` → `"Batch scope mismatch: fulfillment #11 warehouse 1 != 2"` (verified in `resources/lang/en/wms.php:40-45`) | None | Mixed set (warehouse-mix test-authored 422 + zero batches + untouched statuses) | See 422-scope example |
| 422 | Row vanished under lock | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.row_missing`: `en` → `"Batch creation: fulfillment row missing under lock"` (verified in `resources/lang/en/wms.php:39`) | None | TOCTOU race (code-traced) | See 422-row example |
| 422 | Item already has an open task (exactly-one-open-task guard, both flows) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.item_open_task`: `en` → `"Batch creation refused: fulfillment item #3 already has open picking task #5 (status: picking)"` (ids illustrative; verified in `resources/lang/en/wms.php:41`) | None | Pre-tasked item (code-traced) | See 422-opentask example |
| 422 | Zero pickable items (pending yet fully picked — refused before any write) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.batch.zero_pickable`: `en` → `"Cannot create a batch with zero pickable items"` (verified in `resources/lang/en/wms.php:42`) | None | Hollow set (code-traced) | See 422-pickable example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (missing permission — the picker split):

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

404 example (TOCTOU-only in practice):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "fulfillment_ids": ["The fulfillment ids field is required."]
  }
}
```

422-warehouse example (translated, no map):

```json
{
  "message": "No default warehouse configured",
  "status": false
}
```

422-empty example (translated, no map):

```json
{
  "message": "Cannot create a batch from zero fulfillments",
  "status": false
}
```

422-key example (translated, no map):

```json
{
  "message": "Idempotency key is already bound to warehouse 2, not 1",
  "status": false
}
```

422-scope example (translated, no map):

```json
{
  "message": "Batch scope mismatch: fulfillment #11 warehouse 1 != 2",
  "status": false
}
```

422-row example (translated, no map):

```json
{
  "message": "Batch creation: fulfillment row missing under lock",
  "status": false
}
```

422-opentask example (translated, no map):

```json
{
  "message": "Batch creation refused: fulfillment item #3 already has open picking task #5 (status: picking)",
  "status": false
}
```

422-pickable example (translated, no map):

```json
{
  "message": "Cannot create a batch with zero pickable items",
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

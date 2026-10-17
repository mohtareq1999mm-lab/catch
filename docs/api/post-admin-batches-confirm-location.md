# Endpoint Name

Batches – Confirm Location Pick (`POST /api/v1/admin/batches/{id}/confirm-location`)

# Description

P9-4 consolidated scanning command: ONE request scans a location (barcode) + product/variant (SKU or id) + quantity against a picking batch, then distributes that quantity sequentially across **all** matching open picking tasks at that location (multi-order consolidation — one scan covers every order sharing that bin). Each call runs inside a single database transaction with row locks, appends a structured scan-log audit entry per touched task, increments fulfillment items, refreshes batch progress (may auto-complete fulfillments), and returns the next task to pick.

Verified behaviors (runtime: `WmsBatchConsolidatedPickingTest`, 20 tests / 134 assertions — OK):

- One call can complete several tasks from several orders at once (e.g. quantity `10` for 3 orders → all 3 tasks `picked`).
- Partial quantity advances tasks IN `sequence` ORDER: full task (2/2) → `picked`, then the next task becomes `picking` with the remainder (`2/3`).
- Variant-aware: `product` may be a variant SKU; different variants of the same product at the same location are strictly disambiguated.
- Strict product matching: when several products share a location, only tasks for the scanned product are touched.
- Foreign-warehouse location barcode scan is rejected (422) — placements resolve only within the batch's warehouse.
- Every touched task receives a `scan_log` entry with `action: "confirm_location"` (structured audit).
- **Duplicate-scan protection (op_seq replay guard)**: with `op_seq > 0`, tasks already stamped `op_seq >= op_seq` are excluded from this scan's allocation, and a pure replay (every affected task already stamped) is an idempotent no-op returning `total_picked_now: 0` with NO `scan_log` append — never a double-pick (runtime-verified `test_confirm_location_op_seq_replay_is_an_idempotent_noop`).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/batches/{id}/confirm-location` |
| Path composition | `api/v1` prefix + `admin/batches/{id}/confirm-location` from `packages/marvel/src/Rest/Routes.php` (`Route::prefix('admin/batches')` group with group middleware `['api', 'auth:sanctum', 'throttle:admin']` and route-level `permission:batch.operate`; `whereNumber('id')` numeric constraint) — route:list-verified |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@confirmLocation(ConfirmLocationPickRequest $request, int $id)` → `FulfillmentBatch::whereKey($id)->firstOrFail` (404 on miss) → `authorizeWarehouseScope($batch->warehouse_id, 'batch.operate')` (403 write mode — NOT 404) → batch-status gate (`assigned\|in_progress\|picking` else 422) → `BatchPickingService::confirmLocationPick($batch, $validated, $actorId, false)` single transaction → success envelope `ApiResponse` with `wms.batch.location_confirmed` |
| Exception-to-error mapping (controller `catch` blocks) | `PickingValidationException` / `InvalidArgumentException` / `RuntimeException` / `UnknownBarcodeException` (subclass of RuntimeException) → 422 raw `wms.*` messages; `AuthorizationException` → Handler 403 catalogue branch; `ModelNotFoundException` → Handler 404 catalogue branch |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission (coarse) | `batch.operate` — Spatie `PermissionMiddleware` (route middleware). Missing → 403 `{"message": "User does not have the required permissions.", "status": false}` (en; suffix off — `display_permission_in_exception = false`, code-verified) |
| Permission (object-level, warehouse scope) | `WarehouseAccess::allows($user, $batch->warehouse_id, 'batch.operate')`: deny unless (a) caller also holds `manage-warehouse` (global cross-warehouse grant), or (b) caller's HOME warehouse equals the batch warehouse, or (c) the batch warehouse is in the caller's assigned warehouses relation. Fail-closed: null home, null target, or cross-warehouse all deny (D9-15 interim rule). Denial → 403 `AuthorizationException` → `{"message": "This action is unauthorized.", "status": false}` — the raw `Forbidden warehouse operation.` message is dropped by the Handler catalogue branch (code-verified chain) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`). NOTE: `lang` here comes from the `api` middleware GROUP (`CheckLangMiddleware`, `app/Http/Kernel.php:49`) — effect: `lang: ar` renders WMS messages in ar, `en` is the explicit default (source-verified chain) |
| Supported request formats | `application/json` body only |
| Rate limit | TWO limiters apply: route `throttle:admin` = 400/min keyed `$request->user()?->id ?? ip` with custom 429 envelope, PLUS group `throttle:api` = 60/min keyed same (both code-verified: `AppServiceProvider.php:133`, `RouteServiceProvider.php:60`) |
| Route name | `api.admin.batches.confirm-location` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` (case-insensitive, trimmed) | `en`, `ar` | `en` | None | `ar` | Renders WMS/message strings in that locale |
| `id` | path | integer | Required | No | `whereNumber('id')` — non-numeric segments match no route → 404 | Existing `fulfillment_batches.id` | None | None | `3` | Target picking batch |
| `location` | body | string | Required | No | `required\|string\|max:255` | A location BARCODE known to `BarcodeResolver::resolve()` and resolving `kind: location` | None | None | `"BAR-LOC-A01"` | Scanned location barcode (trimmed before resolve) |
| `product` | body | string | Required | No | `required\|string\|max:255` | Product SKU, numeric product id, variant SKU, or numeric variant id (resolved by `BarcodeResolver::resolveProduct`, fallback generic resolve) | None | None | `"SKU-PRD-1"` | Scanned product/variant identifier |
| `quantity` | body | numeric | Required | No | `required\|numeric\|min:0.01` | Any number ≥ 0.01 | None | None | `10` | Quantity being picked at this location in THIS scan |
| `op_seq` | body | integer | Optional | Yes | `nullable\|integer\|min:0` | Any integer ≥ 0 | `0` | None | `42` | Client operation sequence stamped into `scan_log`/tasks (`max(op_seq, task.op_seq)` — monotonic). **Replay guard**: tasks already stamped `op_seq >= op_seq` are excluded from this scan's allocation; a pure replay is an idempotent no-op (200, `total_picked_now: 0`) |

Validation failures → 422 with field `errors` (`location`, `product`, `quantity`). `quantity: 0` and `-2` are rejected (runtime-verified).

## Request examples

Full-location consolidated pick (runtime-verified payload shape):

```json
{
  "location": "BAR-LOC-A01",
  "product": "SKU-PRD-1",
  "quantity": 10
}
```

Variant pick by variant SKU:

```json
{
  "location": "BAR-LOC-A01",
  "product": "VAR-SKU-M",
  "quantity": 5
}
```

# Responses

## Success: location pick confirmed — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.batch.location_confirmed', ['count' => ...]), 200, true, $result)`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.location_confirmed` with `:count` = number of updated tasks | `en` → `"Location picking confirmed successfully (3 tasks updated)."` (ar translation available — code-verified) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Service result contract (below — executed in one transaction) | Every key below |
| `data.batch_id` | integer | Always present | Batch id | e.g. `3` |
| `data.batch_status` | string | Always present | Batch status AFTER `refreshBatchProgress()` (may have advanced/completed by this pick) | Batch workflow statuses (`in_progress`, `picking`, `completed`, …) |
| `data.location_barcode` | string | Always present | Echo of the scanned `location` input | e.g. `"BAR-LOC-A01"` |
| `data.scanned_product` | string | Always present | Echo of the scanned `product` input | e.g. `"SKU-PRD-1"` |
| `data.total_picked_now` | number | Always present | Quantity distributed in THIS request | `0` on a replay no-op |
| `data.remaining_at_location` | number | Always present | Remaining to pick at this location after this scan (`max(0, totalRemaining - quantity)`) | `0` when the location is fully cleared |
| `data.tasks_completed_count` | integer | Always present | How many tasks became `picked` in this call | e.g. `3` |
| `data.updated_task_ids` | array of integers | Always present | Ids of all tasks touched/advanced by this call | `[]` on a replay no-op |
| `data.next_task` | object \| null | Always present (nullable) | Next task to pick — `PickingTaskResource` (keys: `id`, `batch_id`, `fulfillment_item_id`, `order_id`, `product_location_id`, `quantity_to_pick`, `quantity_picked`, `remaining`, `status`, `sequence`, `assigned_to`, `assigned_user{id,name,email}`, `claimed_by`, `claimed_at`, `claim_expires_at`, `picked_at`, `is_consolidated`, `consolidated_total_quantity`, `orders_count`, `grouped_task_ids`, `created_at`, `updated_at`) | `null` when nothing remains in the batch |

### Side effects (all inside ONE transaction)

- Touched `picking_tasks`: `quantity_picked` incremented; `status` → `picked` (complete) / `picking` (partial); `picked_at` stamped when complete; `claimed_by`/`claimed_at` self-healed from the acting admin when null; `op_seq` advanced monotonically; `scan_log` appended entry `{op_seq, actor_id, action: "confirm_location", location, product, quantity, result: "ok", at}` (ISO-8601). Replays append NOTHING.
- `fulfillment_items`: `quantity_picked` incremented; `status` → `picked`/`picking`; `picked_at` when complete.
- Batch progress refreshed (`refreshBatchProgress` — may auto-advance fulfillments/batch), then `getNextTask` resolves `data.next_task`.

### Example 1 — full-location pick (3 orders at one bin, runtime-verified data keys)

```json
{
  "status": 200,
  "message": "Location picking confirmed successfully (3 tasks updated).",
  "success": true,
  "data": {
    "batch_id": 3,
    "batch_status": "in_progress",
    "location_barcode": "BAR-LOC-A01",
    "scanned_product": "SKU-PRD-1",
    "total_picked_now": 10,
    "remaining_at_location": 0,
    "tasks_completed_count": 3,
    "updated_task_ids": [101, 102, 103],
    "next_task": null
  }
}
```

### Example 2 — partial pick (4 of 10; task 1 completes, task 2 partially advances)

```json
{
  "status": 200,
  "message": "Location picking confirmed successfully (2 tasks updated).",
  "success": true,
  "data": {
    "batch_id": 3,
    "batch_status": "in_progress",
    "location_barcode": "BAR-LOC-A01",
    "scanned_product": "SKU-PRD-1",
    "total_picked_now": 4,
    "remaining_at_location": 6,
    "tasks_completed_count": 1,
    "updated_task_ids": [101, 102],
    "next_task": {
      "id": 102,
      "batch_id": 3,
      "fulfillment_item_id": 55,
      "order_id": 900,
      "product_location_id": 12,
      "quantity_to_pick": 3,
      "quantity_picked": 2,
      "remaining": 1,
      "status": "picking",
      "sequence": 2,
      "assigned_to": 7,
      "assigned_user": { "id": 7, "name": "Operator", "email": "op@example.test" },
      "claimed_by": 7,
      "claimed_at": "2026-10-17T10:00:00+00:00",
      "claim_expires_at": null,
      "picked_at": null,
      "is_consolidated": true,
      "consolidated_total_quantity": 3,
      "orders_count": 3,
      "grouped_task_ids": [101, 102, 103],
      "created_at": "2026-10-17T09:55:00+00:00",
      "updated_at": "2026-10-17T10:00:00+00:00"
    }
  }
}
```

`next_task` example rows are modeled from the code-verified `PickingTaskResource` + test-verified field names; the outer `data` keys are runtime-verified (`total_picked_now`, `remaining_at_location`, `tasks_completed_count`, `updated_task_ids`, task statuses, `scan_log` entries).

# Errors

No `422` field-error contract on business refusals (single-message envelope); FormRequest validation adds `errors`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions / tests | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` | None | `test_confirm_location_rejects_unauthenticated_request` → 401 | See 401 |
| 403 | Authenticated caller lacks `batch.operate` (route middleware) | `{message: string, status: boolean}` | None | `"User does not have the required permissions."` | None | `test_confirm_location_requires_batch_operate_permission` → 403 | See 403a |
| 403 | Has `batch.operate` but warehouse scope denies (batch outside home/assigned warehouses; fail-closed) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` | None | `test_confirm_location_rejects_foreign_warehouse_operator` → 403 | See 403b |
| 404 | No batch with `{id}` (`firstOrFail` → `ModelNotFoundException` → catalogue) | `{message: string, status: boolean}` | None | `"Resource Not Found"` | None | Code-verified chain (`Handler` `ModelNotFoundException` branch, message `message.ERROR.RESOURCE_NOT_FOUND`) | See 404 |
| 404 | Non-numeric `{id}` (`whereNumber` constraint — route not matched) | `{message: string, status: boolean}` | None | `"Not Found"` | None | Code-traced (`Handler` `NotFoundHttpException` branch, message `message.ERROR.HTTP_NOT_FOUND`); no test pin | See 404 |
| 422 | Body validation (missing `location`/`product`/`quantity`, `quantity < 0.01`, types) | `{message, status: false, errors: {location\|product\|quantity: [..]}}` | None | `"The given data was invalid."` | Yes | `quantity: 0` and `quantity: -2` → 422 (runtime-verified) | See 422a |
| 422 | Unknown location OR product barcode (`BarcodeResolver::resolve/resolveProduct` throw `UnknownBarcodeException` → controller `RuntimeException` catch) | `{message: string, status: false}` (raw passthrough, no `errors`) | None | `"Unknown barcode: :code."` (code capped at 200 chars) | None | Location: `test_confirm_location_rejects_nonexistent_or_foreign_location_barcode` → 422; product: `test_confirm_location_rejects_non_matching_product_barcode` → 422 | See 422b |
| 422 | Scanned `location` resolves to a NON-location barcode (fulfillment/order/package) | `{message: string, status: false}` | None | `"Location not found."` | None | Code-verified (`wms.picking.location_not_found`; template takes no `:scanned` placeholder — `__()` replace array is inert) | See 422b |
| 422 | No open matching tasks (no placements for product at location, product not matching any task, or FOREIGN-WAREHOUSE location barcode — placements resolve only within `batch.warehouse_id`) | `{message: string, status: false}` | None | `"No open picking tasks found for this product at this location in this batch."` | None | Foreign case: `test_confirm_location_rejects_nonexistent_or_foreign_location_barcode` → 422; product: `test_confirm_location_rejects_non_matching_product_barcode` → 422 | See 422c |
| 422 | `quantity` exceeds total remaining at that location for the scanned product | `{message: string, status: false}` | None | `"Picked quantity (:quantity) exceeds remaining quantity (:remaining)"` | None | `test_confirm_location_rejects_quantity_greater_than_total_remaining` → 422 | See 422d |
| 422 | Batch status gate — status NOT in `assigned\|in_progress\|picking` (e.g. fresh `pending`/created-not-started, or already `completed`) | `{message: string, status: false}` | None | `"Cannot assign batch in status: :status"` | None | `test_confirm_location_rejects_unstarted_batch` → 422 | See 422e |
| 429 | Exceeding `throttle:admin` (400/min) | `{success: boolean, message: string, data: null}` | None | `"Too many requests. Please try again later."` | None | Custom limiter response (code-verified) | See 429 |
| 429 | Exceeding group `throttle:api` (60/min) | `{message: string, ...}` | None | Framework default ("Too Many Attempts.") | None | Generic limiter — envelope NOT project-customized | — |
| 500 | Unmapped exception | `{message: string, status: boolean}` | None | `"Internal Server Error"` (app.debug off) | None | `message.ERROR.INTERNAL` (code-verified) | See 500 |

401 example:

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403a example (missing `batch.operate`):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403b example (foreign-warehouse scope denial):

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

422a example (validation):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "quantity": ["The quantity must be at least 0.01."]
  }
}
```

422b example (unknown barcode):

```json
{
  "message": "Unknown barcode: NON-EXISTENT-LOC.",
  "status": false
}
```

422c example (no open matching tasks / foreign-location scan):

```json
{
  "message": "No open picking tasks found for this product at this location in this batch.",
  "status": false
}
```

422d example (quantity exceeds remaining — runtime scenario: 10 scanned vs 3 remaining):

```json
{
  "message": "Picked quantity (10) exceeds remaining quantity (3)",
  "status": false
}
```

422e example (batch not started):

```json
{
  "message": "Cannot assign batch in status: pending",
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

500 example (production):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

# Notes

- **Idempotency (op_seq replay guard)**: with `op_seq > 0`, tasks already stamped `op_seq >= op_seq` never receive this scan's quantity again; when ALL affected tasks are already stamped, the request is an idempotent no-op (200, `total_picked_now: 0`, `updated_task_ids: []`, no `scan_log` append). Runtime-verified.
- **Concurrency**: task rows are locked `lockForUpdate` inside one transaction; sequential allocation across tasks ordered by `sequence`.
- **Rollback**: any thrown refusal rolls the whole transaction back (no partial task updates on 422).
- **Deliberately NOT enforced here** (verified differences vs the legacy single-task confirm `PickingExecutionService::confirm`): no claim-ownership gate (`task_claimed_by_other`/`task_not_claimed` rejected in legacy; here ANY operator with `batch.operate` + warehouse scope may pick across other operators' claims, with claim self-heal); no execution-time placement revalidation (legacy `assertPlacementStillValid`). These require an owner decision if they should be added.
- Message catalogue keys: `wms.batch.pick_positive`, `wms.batch.pick_exceeds`, `wms.batch.location_no_tasks`, `wms.batch.assign_status`, `wms.batch.location_confirmed`, `wms.picking.location_not_found`, `wms.barcode.unknown` (`resources/lang/en/wms.php`, `resources/lang/ar/wms.php`); `message.ERROR.*` (`resources/lang/en/message.php`).
- Legacy single-task endpoint `POST /api/v1/admin/picking-tasks/{id}/confirm` remains and is regression-protected (`test_legacy_single_task_confirm_still_works_without_regression`).

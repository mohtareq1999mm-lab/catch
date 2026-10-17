# Endpoint Name

Batches – Next Task (`GET /api/v1/admin/batches/{id}/next-task`)

# Description

Returns the batch's lowest-sequence pending task, or a translated empty message when none remains, returning 200 either way.

When the returned task sits at a shared placement (`product_location_id` set), the response additionally carries consolidation metadata: all sibling open tasks at that location for the SAME product AND the exact same variant (never mixed across variants) are grouped, with their combined remaining quantity, order count, and task ids — so a picker knows one scan covers several orders (runtime-verified).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/batches/{id}/next-task` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/batches/{id}/next-task` custom `Route::get(...)->name('api.admin.batches.next-task')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@nextTask(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(batch.warehouse_id, 'view-fulfillment', asNotFound: TRUE)` (cross-warehouse → 404, test-authored); `BatchPickingService::getNextTask` = lowest-`sequence` `pending` task (`pending()` + `bySequence()` scopes — code-verified) or `null` → `null` returns 200 with the translated empty message and NO `data` key (code-verified `apiResponse` without data), else 200 `PickingTaskResource`. When the task has a `product_location_id`, the service attaches `consolidated_info` built from sibling tasks at the same placement (code-verified `BatchPickingService.php:952-976` —see Notes), then the resource maps it to `is_consolidated`/`consolidated_total_quantity`/`orders_count`/`grouped_task_ids`. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`, from the `api` middleware GROUP — `CheckLangMiddleware`). Effect: `lang: ar` renders `wms.batch.next_task` / `message.MESSAGE.BATCH_NO_PENDING_TASKS` in ar (both have ar translations — code-verified) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.next-task` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-lookup → 404-scope → 200 (with or without a task). No 403-scope exists on this read; no validation, no 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Batch id manageable by the caller | None | None | `2` | Identifies the batch |

No query parameters, body fields, or validation-driven `422`.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/batches/2/next-task",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success with a pending task — HTTP 200 (single `PickingTaskResource`)

Returned via `ApiResponse::apiResponse(__('wms.batch.next_task'), 200, true, PickingTaskResource::make(...))` — translated at the call site (`en` → `"Next picking task fetched successfully."`, verified in `resources/lang/en/wms.php:21`). Test-authored: lowest-sequence pending task first.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.next_task` | `en` → `"Next picking task fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Present when a pending task exists | Lowest-sequence pending task | `PickingTaskResource` (full key list below) |

`data` keys (`PickingTaskResource` — code-verified): `id`, `batch_id`, `fulfillment_item_id`, `order_id`, `product_location_id`, `quantity_to_pick`, `quantity_picked`, `remaining` (float), `status`, `sequence` (int or null), `assigned_to` (int or null), `assigned_user` (`{id, name, email}` or null), `claimed_by`, `claimed_at` (ISO-8601 or null), `claim_expires_at` (ISO-8601 or null), `picked_at` (ISO-8601 or null), `is_consolidated`, `consolidated_total_quantity`, `orders_count`, `grouped_task_ids`, `created_at`, `updated_at`.

Consolidation keys (runtime-verified):

| Key | Semantic | Notes |
| --- | --- | --- |
| `is_consolidated` | boolean | `true` when MORE THAN ONE open sibling task shares the task's `product_location_id` with the same fulfillment-item `product_id` and the EXACT same variant (`product_variant_id` — null variant matches `whereNull`, never mixed across variants). Runtime: 3 orders same product/location → `true`; single task → `false` |
| `consolidated_total_quantity` | number | Sum of REMAINING (`quantity_to_pick` − `quantity_picked`) across the grouped siblings. Runtime: 2+3+5 → `10.0`; strict variant grouping: Small 2+3 → `5.0` while Large 7 is EXCLUDED; single → `1.0` |
| `orders_count` | integer | Distinct non-null `order_id`s among the group (min 1). Runtime: `3` (consolidated), `2` (two Small orders), `1` (single) |
| `grouped_task_ids` | array of integers | Ids of all grouped sibling tasks. Runtime: count `3` / `2` / single → `[own id]` |

Sibling set definition (code-verified `BatchPickingService.php:957-968`): same `product_location_id`, status in `pending|assigned|picking` (NOT `picked` — the metadata shrinks as siblings get picked), same fulfillment-item `product_id`, same variant (or both null).

Fallback mapping (when the task has NO placement, `product_location_id` null — code-verified `PickingTaskResource`): `is_consolidated: false`, `consolidated_total_quantity` = the task's own remaining, `orders_count: 1`, `grouped_task_ids` = `[id]`.

```json
{
  "status": 200,
  "message": "Next picking task fetched successfully.",
  "success": true,
  "data": {
    "id": 6,
    "batch_id": 3,
    "fulfillment_item_id": 55,
    "order_id": 900,
    "product_location_id": 12,
    "quantity_to_pick": 2,
    "quantity_picked": 0,
    "remaining": 2.0,
    "status": "pending",
    "sequence": 1,
    "assigned_to": 7,
    "assigned_user": { "id": 7, "name": "Operator", "email": "op@example.test" },
    "claimed_by": null,
    "claimed_at": null,
    "claim_expires_at": null,
    "picked_at": null,
    "is_consolidated": true,
    "consolidated_total_quantity": 10.0,
    "orders_count": 3,
    "grouped_task_ids": [6, 7, 8],
    "created_at": "2026-10-17T09:55:00+00:00",
    "updated_at": "2026-10-17T09:55:00+00:00"
  }
}
```

Consolidation values above are runtime-verified (`is_consolidated: true`, total `10.0`, `orders_count: 3`, three `grouped_task_ids` — `test_next_task_returns_consolidated_metadata_when_multiple_orders_share_location`); other field values are representative of the code-verified resource shape.

## Success with NO pending task — HTTP 200 (NO `data` key)

Returned via `ApiResponse::apiResponse(__('message.MESSAGE.BATCH_NO_PENDING_TASKS'), 200, true)` — translated message with NO data (`ApiResponse` omits `data` when empty — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` |
| `message` | string | Always present | Translated `message.MESSAGE.BATCH_NO_PENDING_TASKS` | `en` → `"No pending tasks in this batch."` (verified in `resources/lang/en/message.php:632`) |
| `success` | boolean | Always present | Outcome flag | Always `true` |

```json
{
  "status": 200,
  "message": "No pending tasks in this batch.",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on batch reads) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No batch with `{id}` (lookup miss — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Cross-warehouse batch (scope anti-enumeration — reads NEVER 403 at the scope layer, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign batch id (test-authored 404) | See 404-scope example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
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

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-scope example (indistinguishable from a miss — by design):

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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

# Notes

- Runtime evidence (2026-10-17, `catch_verify`): `tests/Feature/Wms/WmsBatchConsolidatedPickingTest.php` — **OK (20 tests, 134 assertions)**; includes the 3 next-task tests pinning the consolidation contract: `10.0`/`3`/`3 ids` (multi-order), `1.0`/`1` (single), `5.0`/`2`/`2 ids` (strict variant grouping — Large variant's 7 never leaks into the Small group). Route additionally `route:list`-verified the same day (`GET|HEAD api/v1/admin/batches/{id}/next-task api.admin.batches.next-task › Api\Admin\Wms\BatchController@next…`).
- `assigned_to`/`assigned_user` on this endpoint resolve from the BATCH assignment (`batch.assigned_to` / `batch.assignedUser`) — the task belongs to a batch (code-verified `PickingTaskResource` branch).
- Backward compatibility: the four consolidation keys are ADDITIVE; standard task keys are untouched (`test_next_task_returns_consolidated_metadata_when_multiple_orders_share_location` asserts `id`, `batch_id`, `product_location_id`, `status` still present — runtime-verified).
- Empty path semantics: `getNextTask` returns `null` only when the batch has NO `pending` task left (`pending()` scope); tasks in `assigned`/`picking` do NOT satisfy the next-task selector (code-verified).
- Messages locale-checked (code-verified): `wms.batch.next_task` en `"Next picking task fetched successfully."` / ar `"تم جلب مهمة الالتقاط التالية بنجاح."` ; `message.MESSAGE.BATCH_NO_PENDING_TASKS` en `"No pending tasks in this batch."` / ar `"لا توجد مهام معلقة في هذه الدفعة."` .


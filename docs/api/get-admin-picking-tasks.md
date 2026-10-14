# Endpoint Name

Picking Tasks – List Tasks (`GET /api/v1/admin/picking-tasks`)

# Description

Lists picking tasks in reverse-id order inside the caller's warehouse scope with batch/fulfillment/status/mine filters, returning 200 with a paginated flat array.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/picking-tasks` (fixed path — no placeholders) |
| Path composition | `api/v1` prefix + `admin/picking-tasks` custom `Route::get(...)->name('api.admin.picking-tasks.index')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:picking-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@index(ListPickingTasksRequest $request)` → validated filters → `applyWarehouseScope`: `manage-warehouse` holders see everything (optional `?warehouse_id` honored as a plain filter, NO scope authorization on it — code-verified); scoped callers are confined to home (`warehouseIdFor`, null home → 403 catalogue) with `?warehouse_id` probed against scope (`≠ home` → 404 anti-enumeration via `asNotFound: TRUE`, code-verified); `batch_id`/`fulfillment_id` narrow with lookup + scope check (404 anti-enumeration); `status`/`mine` narrow; `limit` caps at 100 (default 15); `orderByDesc('id')` paginated → 200 `PickingTaskResource` collection. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` (route pipe + scope check — BOTH the same permission; a `view-fulfillment`-only caller gets 200 on batch reads but 403 here — test-authored permission split) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.picking-tasks.index` (explicit named route; `route:list` verified; name-pinned in `WmsRbacFoundationTest`) |

Order matters: 401 → 403-route → 422-validation → 403-homeless / 404-scope-filter → 404-batch/fulfillment (TOCTOU race only — see below) → 200.

## Request inputs

`ListPickingTasksRequest` runs before the action (`authorize: true`). Unknown `batch_id`/`fulfillment_id`/`warehouse_id` fail at VALIDATION (`exists` rules → 422 with `errors` map); the in-action `firstOrFail` 404s below fire only for TOCTOU races where the row vanishes after validation (code-verified split).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `warehouse_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` (unknown ids 422 HERE at validation) | Warehouse id or omitted | `null` | Globals: plain filter; scoped: probed (`≠ home` → 404) | `4` | Narrows listing to one warehouse |
| `batch_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:fulfillment_batches,id` | Batch id or omitted | `null` | Non-null → lookup + scope check (404 anti-enumeration) | `9` | Narrows listing to one batch |
| `fulfillment_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:fulfillments,id` | Fulfillment id or omitted | `null` | Non-null → lookup + scope check (404 anti-enumeration) | `12` | Narrows listing to one fulfillment |
| `status` | query | string | Optional | Yes | `nullable\|in:pending,assigned,picking,picked,skipped,cancelled` | One status or omitted | `null` | None | `assigned` | Narrows listing to one status |
| `mine` | query | boolean | Optional | Yes | `nullable\|boolean` | Truthy or omitted | `null`/falsy | Truthy → `claimed_by = actorId` | `1` | Only tasks claimed by the caller (test-authored) |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | `1`–`100` | `15` | None | `50` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/picking-tasks?status=assigned&mine=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: tasks listed — HTTP 200 (flat paginated array)

Returned via `ApiResponse::apiResponse(__('wms.picking.fetched_many'), 200, true, PickingTaskResource::collection($paginated))`. The resource collection serializes as a FLAT array under `data` (no paginator envelope inside this contract — test-commented, test-authored). Scoping proof: Warehouse B tasks exist yet never leak into a Warehouse A listing (test-authored).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.picking.fetched_many` | `en` → `"Picking tasks fetched successfully."` (verified in `resources/lang/en/wms.php:147`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present on success | Task rows, newest first | Flat `PickingTaskResource[]` (possibly `[]`) |
| `data[].id` | integer | Per row | Task id | — |
| `data[].batch_id` | integer/null | Per row | Owning batch | `null` for order-flow tasks |
| `data[].fulfillment_item_id` | integer | Per row | Fulfillment item | — |
| `data[].order_id` | integer | Per row | Order id | — |
| `data[].product_location_id` | integer/null | Per row | Pinned placement | — |
| `data[].quantity_to_pick` | string (decimal) | Per row | Required quantity | `decimal:2` cast serializes as e.g. `"2.00"` (code-verified) |
| `data[].quantity_picked` | string (decimal) | Per row | Picked so far | `decimal:2` cast serializes as e.g. `"0.00"` (code-verified) |
| `data[].remaining` | number | Per row | `quantity_to_pick − quantity_picked` | Computed float |
| `data[].status` | string | Per row | Task status | `pending`/`assigned`/`picking`/`picked`/`skipped`/`cancelled` |
| `data[].sequence` | integer/null | Per row | Batch sequence | — |
| `data[].claimed_by` | integer/null | Per row | Claimant user id | — |
| `data[].claimed_at` | string/null | Per row | Claim time | ISO-8601 |
| `data[].claim_expires_at` | string/null | Per row | Lease expiry | ISO-8601 |
| `data[].picked_at` | string/null | Per row | Completion time | ISO-8601 |
| `data[].created_at` | string/null | Per row | Creation time | ISO-8601 |
| `data[].updated_at` | string/null | Per row | Update time | ISO-8601 |

```json
{
  "status": 200,
  "message": "Picking tasks fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 7,
      "batch_id": null,
      "fulfillment_item_id": 3,
      "order_id": 11,
      "product_location_id": 5,
      "quantity_to_pick": "2.00",
      "quantity_picked": "0.00",
      "remaining": 2,
      "status": "assigned",
      "sequence": 1,
      "claimed_by": 7,
      "claimed_at": "2026-10-14T10:00:00+00:00",
      "claim_expires_at": "2026-10-14T10:15:00+00:00",
      "picked_at": null,
      "created_at": "2026-10-14T09:00:00+00:00",
      "updated_at": "2026-10-14T10:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); all other errors use the EXCEPTION envelope `{message, status: false}` (code-verified via `Handler`).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | E.g. `view-fulfillment`-only viewer (test-authored 403 on this URI) | See 403 example |
| 403 | Scoped caller with NO home warehouse (fail-closed; controller verbatim discarded by Handler — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Homeless staffer (code-traced) | See 403-scope example |
| 404 | Scoped caller probing a foreign `?warehouse_id` (anti-enumeration, `asNotFound: TRUE` — code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (`message.ERROR.RESOURCE_NOT_FOUND`) | None | Cross-warehouse filter probe (pattern test-authored on sibling reads) | See 404-scope example |
| 404 | `batch_id`/`fulfillment_id` row vanished post-validation (TOCTOU race; unknown ids 422 at validation instead — code-verified split) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Raced lookup miss (code-traced) | See 404 example |
| 422 | Filter validation failure (`exists`/`in`/`min`/`max` rules) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | E.g. unknown `warehouse_id`/`batch_id`/`fulfillment_id`, bad `status`, `limit > 100` (code-traced) | See 422-validation example |
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

403-scope example (catalogue text — the controller verbatim is discarded by the Handler, code-verified):

```json
{
  "message": "This action is unauthorized.",
  "status": false
}
```

404-scope example (fixed string, anti-enumeration):

```json
{
  "message": "Resource Not Found",
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

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "status": ["The selected status is invalid."]
  }
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

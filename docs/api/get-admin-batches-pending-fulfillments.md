# Endpoint Name

Batches – Pending Fulfillments (`GET /api/v1/admin/batches/pending-fulfillments`)

# Description

Returns pending fulfillments available for batch creation in one warehouse (default warehouse when unfiltered), returning 200 with a fulfillment collection.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/batches/pending-fulfillments` (fixed path — no placeholders; matched literally BEFORE `{id}` routes by registration order — a numeric segment here never reaches this action) |
| Path composition | `api/v1` prefix + `admin/batches/pending-fulfillments` custom `Route::get(...)->name('api.admin.batches.pending-fulfillments')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@pendingFulfillments(Request $request)` — NO request validation (plain `Request`; `warehouse_id`/`limit` parsed manually — code-verified); `limit` clamped `1–100` (default `10`); warehouse resolution: explicit `?warehouse_id` → `Warehouse::whereKey->firstOrFail` (miss → 404 fixed — plain lookup, so a soft-deleted id 404s; an INACTIVE id passes with NO status gate — code-verified) ELSE active-default warehouse via `WarehouseService::resolveForNewFulfillment()` (`RuntimeException` → 422: `no_default` when none, `inactive` when the default itself is inactive — code-verified; `deleted` is unreachable here since trashed defaults are excluded); then `authorizeWarehouseScope(resolvedId, 'view-fulfillment', asNotFound: TRUE)` (foreign → 404 anti-enumeration, test-authored; scope denials on this read are ALWAYS 404 — there is no 403-homeless path here, code-verified); `getPendingFulfillments(resolvedId, limit)` = `status = pending` in that warehouse with items/placement/location eager-loads → 200 `FulfillmentResource` collection. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.pending-fulfillments` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-warehouse (explicit miss) XOR 422-no-default (unfiltered without a default — branch-exclusive) → 404-scope → 200. No validation, no 422-validation, no 403-scope on this read.

## Request inputs

No `FormRequest` — plain `Request` with manual parsing (no validation `422` with an `errors` map exists on this action).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `warehouse_id` | query | integer | Optional | Yes | `(int) get('warehouse_id', 0) ?: null`; non-null → existence lookup (miss → 404) + scope check (foreign → 404) | Warehouse id or omitted | `null` → active-default warehouse | None | `4` | Which warehouse's pending queue to read (test-authored foreign → 404) |
| `limit` | query | integer | Optional | Yes | Clamped `min(max((int) get('limit', 10), 1), 100)` — never errors, just clamps (code-verified) | Any integer | `10` | None | `5` | Max fulfillments returned |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/batches/pending-fulfillments",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: pending fulfillments — HTTP 200 (collection, possibly empty)

Returned via `ApiResponse::apiResponse(__('wms.batch.pending_fulfillments'), 200, true, FulfillmentResource::collection(...))` — translated at the call site (`en` → `"Pending fulfillments fetched successfully."`, verified in `resources/lang/en/wms.php:22`). Test-authored: 200 non-empty for the home warehouse.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.pending_fulfillments` | `en` → `"Pending fulfillments fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present on success | Pending fulfillments (capped by `limit`) | `FulfillmentResource[]` (possibly `[]`) |
| `data[].id` | integer | Per row | Fulfillment id | — |
| `data[].fulfillment_number` | string | Per row | Human number | — |
| `data[].order_id` | integer | Per row | Order id | — |
| `data[].warehouse_id` | integer | Per row | Owning warehouse | Always the resolved warehouse |
| `data[].status` | string | Per row | Fulfillment status | Always `"pending"` here |
| `data[].priority` | mixed | Per row | Priority | — |
| `data[].assigned_to` | integer/null | Per row | Assignee | — |
| `data[].items_count` | integer | Per row | Item count | `0` (no count eager-load on this path — code-verified) |
| `data[].shipments_count` | integer | Per row | Shipment count | `0` (same — code-verified) |
| `data[].created_at` | string/null | Per row | Creation time | ISO-8601 |
| `data[].updated_at` | string/null | Per row | Update time | ISO-8601 |

```json
{
  "status": 200,
  "message": "Pending fulfillments fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 11,
      "fulfillment_number": "FUL-1A2B3C",
      "order_id": 21,
      "warehouse_id": 1,
      "status": "pending",
      "items_count": 0,
      "shipments_count": 0
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action (the single 422 is the translated no-default text).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on batch reads) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | Unknown `?warehouse_id` (explicit lookup miss — code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Ghost warehouse filter (code-traced) | See 404 example |
| 404 | Foreign `?warehouse_id` (scope anti-enumeration — reads NEVER 403 at the scope layer, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Cross-warehouse probe (test-authored 404, rows never leaked) | See 404-scope example |
| 422 | No active-default warehouse configured (unfiltered call with no default, or an inactive default — translated service text, code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.warehouse.no_default` (`"No default warehouse configured"`) / `wms.warehouse.inactive` (`"Warehouse #1 (WH-X) is not active: cannot create new fulfillment"`, ids illustrative) — verified in `resources/lang/en/wms.php:251-252` | None | Misconfigured warehouse setup (code-traced) | See 422 example |
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

422 example (translated, no map):

```json
{
  "message": "No default warehouse configured",
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

# Endpoint Name

Locations – Empty Products (`DELETE /api/v1/admin/locations/{location}/products`)

# Description

Empties one location of its eligible placement hints inside a single transaction after ordered safety checks, keeping the location row itself intact, returning 200 with a truthful payload (including idempotent already-empty replays).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin/locations/{location}/products` (explicit `{location}` placeholder with `whereNumber('location')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/locations/{location}/products` custom `Route::delete(...)->name('api.admin.locations.products.destroy')` in `packages/marvel/src/Rest/Routes.php:578` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-location` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\LocationController@emptyProducts(int $location)` → `whereKey->firstOrFail` (miss → 404 fixed; validation does not exist, so NOTHING precedes the lookup — code-verified); `authorizeWarehouseScope(warehouse_id, 'manage-location')` with `asNotFound: FALSE` (controller throws `AuthorizationException('Forbidden warehouse operation.')` but `Handler:173-175` maps it to catalogue `This action is unauthorized.` — verbatim DISCARDED, code-verified); then `LocationEmptyService::empty($loc)` in try/catch (`InvalidArgumentException` → translated 422 with NO `errors` map; `QueryException` with MySQL-1451 → 422 `empty_history` backstop, any OTHER `QueryException` rethrows — code-verified); success (INCLUDING already-empty idempotent replays) → 200 with `{location_id, warehouse_id, removed, removed_ids, remaining}` payload. Central stock (`products.stock_quantity` / `products.reserved_quantity`), reservations, and order inventory state are NEVER touched; the `Location` row itself survives with status/type/warehouse intact (code-verified from service + controller) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-location` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.locations.products.destroy` (explicit named route; `route:list` verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `location` | path | integer | Required | No | `whereNumber('location')` + `int $location`; must be guard-clean (no physical stock, no open `pending/assigned/picking` tasks, no unpicked commitment, no pending return, no picking history) | Emptiable location id manageable by the caller | None | None | `1` | Identifies the location (NOT a product — products ride in placement rows, never in the path) |

No query parameters, body fields, or validation-driven `422` (all 422s below are translated service-guard outcomes, not validation). Behavioral contracts: code-traced against `LocationController@emptyProducts` + `LocationEmptyService::empty`; route existence `route:list` verified for the documented URI; `tests/Feature/Wms/WmsLocationEmptyTest.php` authors the behavior (both URIs registered, 401/403/scope/404 statuses status-only, success + idempotency + stock/hint/tasks/commitment/history/return message assertions) but was NOT re-run green in this session (DB env failure: missing `catch.*` tables; no live HTTP verification in this session).

> Scope note: this file documents ONLY the requested `api.admin.locations.products.destroy` URI above (TARGET). The sibling alias `DELETE /api/v1/admin/wms/locations/{location}/products` (`api.admin.wms.locations.products.destroy`, `Routes.php:584`, same handler + permission, `route:list` verified) is RELATED evidence and behaves identically, but is out of scope for this file.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin/locations/1/products",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: placements removed — HTTP 200 (bin survives, counts truthful)

Returned via `ApiResponse::apiResponse($removed > 0 ? __('wms.location.emptied') : __('wms.location.already_empty'), 200, true, [...])` (translated message — never a raw `wms.` key; code-verified + test-authored). Only eligible zero-stock hints (`quantity == 0` AND `allocated_hint == 0`) are deleted in ONE transaction with deterministic lock order (`location → placements → tasks/commitments/returns`, all `lockForUpdate` — code-verified); `remaining` is recounted inside the transaction before commit (phantom-safe — code-verified). The `Location` row is never deleted or deactivated by this operation.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including already-empty replays) |
| `message` | string | Always present | Translated `wms.location.emptied` / `wms.location.already_empty` | `en` → `"Location emptied successfully."` / `"Location is already empty."` (verified in `resources/lang/en/wms.php:259-260`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Truthful payload | `location_id` (int), `warehouse_id` (int), `removed` (int — rows deleted), `removed_ids` (int[] — placement ids), `remaining` (int — recounted) |
| `data.location_id` | integer | Always present on success | Emptied location id | Echo of the path id |
| `data.warehouse_id` | integer | Always present on success | Owning warehouse id | Echo of `locations.warehouse_id` |
| `data.removed` | integer | Always present on success | Placement rows deleted | `0` on already-empty replay |
| `data.removed_ids` | array | Always present on success | Deleted placement ids | `[]` on already-empty replay |
| `data.remaining` | integer | Always present on success | Placements left | `0` on clean empty (recounted, not hardcoded) |

```json
{
  "status": 200,
  "message": "Location emptied successfully.",
  "success": true,
  "data": {
    "location_id": 1,
    "warehouse_id": 1,
    "removed": 2,
    "removed_ids": [11, 12],
    "remaining": 0
  }
}
```

Already-empty replay (still 200, different message):

```json
{
  "status": 200,
  "message": "Location is already empty.",
  "success": true,
  "data": {
    "location_id": 1,
    "warehouse_id": 1,
    "removed": 0,
    "removed_ids": [],
    "remaining": 0
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action (all 422s are translated service-guard texts with NO `data` key — `ApiResponse` omits `data` when empty, code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (code-traced; test-authored status-only on both URIs) | See 401 example |
| 403 | Authenticated caller lacks `manage-location` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission, incl. `view-location`-only holder (code-traced; test-authored status-only) | See 403 example |
| 403 | Out-of-scope `manage-location` holder at the SCOPE layer (controller throws `AuthorizationException('Forbidden warehouse operation.')`, but `Handler:173-175` maps ANY `AuthorizationException` to the catalogue — the verbatim string is DISCARDED, code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`, code-verified) | None | Cross-warehouse empty (code-traced shape; test-authored status-only with location provably surviving) | See 403-scope example |
| 404 | No location with `{location}` (lookup miss — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string, `message.ERROR.RESOURCE_NOT_FOUND` via `ModelNotFoundException` branch — code-verified) | None | Unknown numeric id (code-traced; test-authored status-only for `999999`) | See 404 example |
| 404 | Non-numeric `{location}` matches NO route (`whereNumber` constraint) | `{message: string, status: boolean}` | None | Framework passthrough (`translateHttpMessage` returns the raw route-miss text verbatim; `"Not Found"` only when the exception message is empty via `HTTP_NOT_FOUND` — code-verified Handler:112-121, literal NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | ANY placement holds physical stock (`quantity > 0` OR `allocated_hint > 0` — either trips it; all-or-nothing, even eligible rows survive; code-verified OR + service comment) | `{status: integer, message: string, success: boolean}` (NO `errors` map, NO `data` key) | None | Translated `wms.location.empty_stock` WITH the location id via `['location' => id]` (note: `:location` placeholder, NOT `:id` — code-verified): `en` → `"Cannot empty location #1: location holds physical stock. Transfer or consume stock first."` (id illustrative) | None | Stocked location (code-traced; test-authored incl. exact message + zero-partial-change proofs for stock and hint) | See 422-stock example |
| 422 | OPEN picking tasks (`pending`/`assigned`/`picking` ONLY — `LocationEmptyService::OPEN_TASK_STATUSES`, mirrors destroy/transfer guards; never `claimed`, no such status; code-verified) reference the location's placements (locked read is authoritative) | `{status: integer, message: string, success: boolean}` (NO `errors` map, NO `data` key) | None | Translated `wms.location.empty_tasks` WITH the id via `['id' => id]`: `en` → `"Cannot empty location #1: open picking tasks reference this location. Complete, skip, or reallocate them first."` (id illustrative) | None | Location with live picks (code-traced; test-authored per-status for all three) | See 422-tasks example |
| 422 | Unpicked fulfillment commitment (`quantity_picked < quantity` on a `pending`/`picking` fulfillment — `LocationEmptyService::ACTIVE_FULFILLMENT_STATUSES`; pre-task window pins the bin even before a task exists; code-verified) references the location's placements | `{status: integer, message: string, success: boolean}` (NO `errors` map, NO `data` key) | None | Translated `wms.location.empty_commitment` WITH the id: `en` → `"Cannot empty location #1: an unpicked fulfillment commitment references this location. Create picking tasks and reallocate them elsewhere first, then empty."` (id illustrative) | None | Committed location (code-traced; test-authored) | See 422-commitment example |
| 422 | Pending return (`return_items.product_location_id` pinned with `restocked_at IS NULL`; completed returns are history rows via FK SET NULL and do NOT block — code-verified service comment) references the location's placements | `{status: integer, message: string, success: boolean}` (NO `errors` map, NO `data` key) | None | Translated `wms.location.empty_return` WITH the id: `en` → `"Cannot empty location #1: a pending return references this location. Complete or reallocate the return first, then empty."` (id illustrative) | None | Return-blocked location (code-traced; test-authored for both active-block and completed-pass cases) | See 422-return example |
| 422 | Picking HISTORY exists (any status — FK RESTRICT on `picking_tasks.product_location_id` would 500, so refusal points at deactivation as the history-preserving path; the MySQL-1451 backstop in the controller catch is a race-only path converging on this SAME text with no isolated test — code-verified) | `{status: integer, message: string, success: boolean}` (NO `errors` map, NO `data` key) | None | Translated `wms.location.empty_history` (NO id interpolation): `en` → `"Cannot empty location: it has picking history. Deactivate it instead to preserve the audit trail."` | None | Historied location (service guard code-traced + test-authored incl. deactivation still working afterwards; 1451 path code-traced only) | See 422-history example |
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

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-constraint example (framework passthrough — live route-miss returns the raw Symfony text verbatim; `"Not Found"` is only the empty-message `HTTP_NOT_FOUND` case, NOT live-verified):

```json
{
  "message": "Not Found",
  "status": false
}
```

422-stock example (translated WITH id, no map, no data):

```json
{
  "status": 422,
  "message": "Cannot empty location #1: location holds physical stock. Transfer or consume stock first.",
  "success": false
}
```

422-tasks example (translated WITH id, no map, no data):

```json
{
  "status": 422,
  "message": "Cannot empty location #1: open picking tasks reference this location. Complete, skip, or reallocate them first.",
  "success": false
}
```

422-commitment example (translated WITH id, no map, no data):

```json
{
  "status": 422,
  "message": "Cannot empty location #1: an unpicked fulfillment commitment references this location. Create picking tasks and reallocate them elsewhere first, then empty.",
  "success": false
}
```

422-return example (translated WITH id, no map, no data):

```json
{
  "status": 422,
  "message": "Cannot empty location #1: a pending return references this location. Complete or reallocate the return first, then empty.",
  "success": false
}
```

422-history example (translated, no id, no map, no data):

```json
{
  "status": 422,
  "message": "Cannot empty location: it has picking history. Deactivate it instead to preserve the audit trail.",
  "success": false
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

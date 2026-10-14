# Endpoint Name

Inventory – Stock Overview (`GET /api/v1/admin/inventory/overview`)

# Description

Returns SKU-level inventory stats without double-counting parent aggregates, returning 200. Scope is split by design (code-verified): `active_bins` and `today_transactions` are confined to the caller's warehouse scope, while the three stock totals are GLOBAL catalog sums for every caller, scoped included.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/inventory/overview` (fixed path — no placeholders, no `whereNumber`; unknown sub-paths under `admin/inventory` match NO route) |
| Path composition | `api/v1` prefix + `admin/inventory/overview` custom `Route::get(...)->name('api.admin.inventory.overview')` in `packages/marvel/src/Rest/Routes.php:708` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-location` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\InventoryController@overview(Request $request)` → scope branch FIRST (nothing precedes it — no lookup, no validation, code-verified): `manage-warehouse` holders read optional `?warehouse_id` (non-null → `authorizeWarehouseScope(scope, 'view-location', asNotFound: TRUE)` 404 anti-enumeration path) else unscoped (`null` = all warehouses); non-global callers are forced to home warehouse (`warehouseIdFor`, null home → 403 catalogue — the thrown verbatim is DISCARDED, code-verified) and their `?warehouse_id` is IGNORED (code-verified — never read in that branch); then four queries in three groups → 200 with `{total_sku_units, simple_units, variant_units, active_bins, today_transactions, warehouse_id}`. SCOPE QUIRK (code-verified): the two stock sums (`Product::whereDoesntHave('variations')->sum('stock_quantity')` + `ProductVariant::sum('stock_quantity')`) carry NO warehouse filter — every caller, scoped included, sees GLOBAL catalog stock totals; only `active_bins` and `today_transactions` are warehouse-scoped. NOTHING is written (pure read — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-location` (route pipe + scope check — BOTH the same permission; `manage-warehouse` is NOT required but widens scope to global + optional narrow) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.inventory.overview` (explicit named route; `route:list` verified) |

Order matters (branches are per caller type): 401 → 403-route → scoped callers: 403-homeless; global holders: 404-scope-filter (defensive-only — see table) → 200. No 404 lookup and no 422 exist on this action (no model lookup, no `FormRequest`, no `unprocessable()` call — code-verified).

## Request inputs

No `FormRequest` — plain `Request` with manual parsing (no validation `422` with an `errors` map exists on this action).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `warehouse_id` | query | integer | Optional | Yes | `(int) get('warehouse_id', 0) ?: null`; read ONLY for `manage-warehouse` holders (non-null → scope-authorized with 404 anti-enumeration); IGNORED for scoped callers (forced to home — code-verified); non-numeric/`0`/absent → `null` → unscoped for globals | Warehouse id or omitted | `null` (scoped: home; global: all warehouses) | None | `4` | Narrows `active_bins`/`today_transactions` (globals only; stock totals stay global regardless — see quirk above) |

No body fields. `?warehouse_id=abc` behaves as absent (`(int)` casts to `0` → `null`).

## Request examples

Basic read (scoped caller sees home-warehouse bins/transactions + global stock totals):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/inventory/overview",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

Global narrow:

```json
{
  "method": "GET",
  "url": "/api/v1/admin/inventory/overview?warehouse_id=4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: overview returned — HTTP 200 (read-only, nothing written)

Returned via `ApiResponse::apiResponse(__('wms.stock.overview_fetched'), 200, true, [...])` (translated message — never a raw `wms.` key; code-verified). Variable parents are EXCLUDED from stock totals (their stock lives on variants — controller-commented, test-authored with a `999` parent trap); `total_sku_units` is always exactly `simple_units + variant_units` (test-authored). `today_transactions` counts `inventory_operations` with `status = 'completed'` (`InventoryOperation::STATUS_COMPLETED`, code-verified) whose `created_at` falls on `today()` in the app timezone (code-verified). `warehouse_id` echoes the effective scope (`null` = global unfiltered — the key is always present).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.stock.overview_fetched` | `en` → `"Inventory overview fetched successfully."` (verified in `resources/lang/en/wms.php:211`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Payload | Six keys below (verified in controller) |
| `data.total_sku_units` | integer | Always present | Stock-owning units, no double-count | Always `simple_units + variant_units` (test-authored); GLOBAL sum for every caller (scope quirk — code-verified) |
| `data.simple_units` | integer | Always present | `SUM(products.stock_quantity)` over products WITHOUT variants | GLOBAL sum (code-verified) |
| `data.variant_units` | integer | Always present | `SUM(product_variants.stock_quantity)` over every variant row | GLOBAL sum (code-verified) |
| `data.active_bins` | integer | Always present | `COUNT(locations)` with `status = 'active'` (`Location::STATUS_ACTIVE`) | Scoped to `warehouse_id` when non-null (code-verified) |
| `data.today_transactions` | integer | Always present | `COUNT(inventory_operations)` completed TODAY (app timezone) | Scoped to `warehouse_id` when non-null (code-verified) |
| `data.warehouse_id` | integer/null | Always present | Effective scope | Home id for scoped callers, narrowed id or `null` (global) for `manage-warehouse` holders |

```json
{
  "status": 200,
  "message": "Inventory overview fetched successfully.",
  "success": true,
  "data": {
    "total_sku_units": 95,
    "simple_units": 10,
    "variant_units": 85,
    "active_bins": 3,
    "today_transactions": 2,
    "warehouse_id": 1
  }
}
```

(Values fixture-derived from `test_overview_does_not_double_count` — simple `10` + variants `20+50+15=85`; the test enforces `total == simple + variant` and parent exclusion, not these exact absolutes.)

Global unfiltered (same message, `warehouse_id: null`, bins/transactions unscoped):

```json
{
  "status": 200,
  "message": "Inventory overview fetched successfully.",
  "success": true,
  "data": {
    "total_sku_units": 95,
    "simple_units": 10,
    "variant_units": 85,
    "active_bins": 5,
    "today_transactions": 2,
    "warehouse_id": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation and no business refusal exist on this action, so no 422 of any kind exists here (code-verified — no `FormRequest`, no `unprocessable()`). All errors use the EXCEPTION envelope `{message, status: false}` (code-verified via `Handler`).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (code-traced; `WmsRbacCompletionTest::test_every_wms_route_requires_authentication` sweeps all `Api\Admin\Wms` routes incl. this one) | See 401 example |
| 403 | Authenticated caller lacks `view-location` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced; `WmsRbacCompletionTest::test_every_wms_route_requires_permission` sweeps all `Api\Admin\Wms` routes incl. this one) | See 403 example |
| 403 | Scoped caller with NO home warehouse (fail-closed `warehouseIdFor → null` → `AuthorizationException('Forbidden warehouse operation.')`, but `Handler:173-175` maps ANY `AuthorizationException` to the catalogue — the verbatim string is DISCARDED, code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`, code-verified) | None | Homeless staffer with `view-location` (code-traced only — no current test asserts this path on this endpoint) | See 403-scope example |
| 404 | Defensive-only: global holder narrowing to a denied `?warehouse_id` (404 anti-enumeration via `authorizeWarehouseScope(..., asNotFound: TRUE)` → `NotFoundHttpException('Resource Not Found')` → catalogue; code-verified path but UNREACHABLE when the permission catalogue is complete — the route pipe already guarantees `view-location` and `allows()` returns unconditionally for `manage-warehouse` holders, so the deny cannot fire; fail-closed denial remains possible only when permission rows are missing; retained as a defensive contract) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (`message.ERROR.RESOURCE_NOT_FOUND`, code-verified) | None | Only a global holder failing the scope check (route gate would 403 first when rows exist — code-traced path, no live test) | See 404-scope example |
| 404 | Unknown sub-path under `admin/inventory` matches NO route (fixed path — no placeholder, no constraint) | `{message: string, status: boolean}` | None | Framework passthrough (`translateHttpMessage` returns the raw route-miss text verbatim; `"Not Found"` only when the exception message is empty via `HTTP_NOT_FOUND` — code-verified Handler:112-121, literal NOT live-verified) | None | E.g. `GET /api/v1/admin/inventory/unknown` | See 404-constraint example |
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

404-constraint example (ILLUSTRATIVE — framework passthrough: a live route-miss returns the raw Symfony text verbatim; `"Not Found"` is only the empty-message `HTTP_NOT_FOUND` case and is NOT live-verified):

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

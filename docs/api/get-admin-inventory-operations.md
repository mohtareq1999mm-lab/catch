# Endpoint Name

Inventory – List Operations (`GET /api/v1/admin/inventory/operations`)

# Description

Lists ledger inventory operations (receipts, transfers, initial placements) newest-first with filters and pagination, returning 200. Read-only; warehouse-scoped by caller type (code-verified).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/inventory/operations` (fixed path — no placeholders, no `whereNumber`; `/operations/{key}` with an extra segment matches the sibling show route, not this one) |
| Path composition | `api/v1` prefix + `admin/inventory/operations` custom `Route::get(...)->name('api.admin.inventory.operations.index')` in `packages/marvel/src/Rest/Routes.php:710` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-location` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\InventoryController@operations(ListInventoryOperationsRequest $request)` (`InventoryController.php:273`, code-verified) → validated query FIRST (validation 422 precedes everything); then scope branch: `manage-warehouse` holders read optional validated `warehouse_id` (non-null → `authorizeWarehouseScope(scope, 'view-location', asNotFound: TRUE)` 404 anti-enumeration path, then `where('warehouse_id', scope)`; null → unscoped, all warehouses); non-global callers resolve home via `warehouseIdFor` (null home → `AuthorizationException('Forbidden warehouse operation.')` → 403 catalogue, verbatim DISCARDED, code-verified), then check requested `warehouse_id` (non-null AND !== home → `authorizeWarehouseScope(requested, 'view-location', TRUE)` → 404 anti-enumeration, code-verified) and force `where('warehouse_id', home)` (foreign filter never applied as rows — test-verified); then optional filters (`operation`, `status`, `product_id`, `product_variant_id`, `source_location_id`, `destination_location_id`, `reference` LIKE `%…%`, `date_from`/`date_to` on `created_at` date) → `orderByDesc('id')` → `paginate(perPage)` → 200 `InventoryOperationResource::collection(...)` payload. NOTHING is written (pure read — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-location` (route pipe + scope check — BOTH the same permission; `manage-warehouse` is NOT required but widens scope to global + optional narrow) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.inventory.operations.index` (explicit named route) |

Order matters: 401 → 403-route → 422-validation (`FormRequest` precedes scope) → scoped callers: 403-homeless, else 404-foreign-filter; global holders: 404-scope-filter (defensive-only — see table) → 200. No single-row 404 and no business-refusal 422 exist on this action (no `firstOrFail`, no `unprocessable()` call in `operations` — code-verified).

## Request inputs

`ListInventoryOperationsRequest::authorize()` returns `true` (route middleware is the gate — code-verified). `rules()` below are code-verified from `app/Http/Requests/Admin/Wms/ListInventoryOperationsRequest.php`.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `warehouse_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id`; globals: non-null → scope-authorized + applied; scoped: foreign value → 404, home applied regardless | Warehouse id or omitted | `null` (scoped: home; global: all warehouses) | None | `4` | Narrows ledger to one warehouse (scoped callers can only read home — foreign → 404) |
| `operation` | query | string | Optional | Yes | `nullable\|string\|in:receipt,transfer,initial_placement` | `receipt`, `transfer`, `initial_placement` | None (unfiltered) | None | `receipt` | Filters by operation kind |
| `status` | query | string | Optional | Yes | `nullable\|string\|in:processing,completed,failed` | `processing`, `completed`, `failed` | None (unfiltered) | None | `completed` | Filters by ledger status |
| `product_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:products,id` | Existing product id | None (unfiltered) | None | `9` | Filters by product |
| `product_variant_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:product_variants,id` | Existing variant id | None (unfiltered) | None | `17` | Filters by SKU row |
| `source_location_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:locations,id` | Existing location id | None (unfiltered) | None | `12` | Filters by source bin (transfers) |
| `destination_location_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:locations,id` | Existing location id | None (unfiltered) | None | `14` | Filters by destination bin (receipts/transfers) |
| `reference` | query | string | Optional | Yes | `nullable\|string\|max:255`; applied as LIKE `%…%` (substring, code-verified) | Any string ≤255 chars | None (unfiltered) | None | `PO-104` | Substring match on reference |
| `date_from` | query | date | Optional | Yes | `nullable\|date` (`whereDate('created_at', '>=', …)`, code-verified) | Any parseable date | None (unfiltered) | None | `2026-10-01` | Ledger rows created on/after this date |
| `date_to` | query | date | Optional | Yes | `nullable\|date\|after_or_equal:date_from` (`whereDate('created_at', '<=', …)`, code-verified) | Date ≥ `date_from` | None (unfiltered) | Requires `date_from` when both sent (else `after_or_equal` fails → 422) | `2026-10-14` | Ledger rows created on/before this date |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100`; effective perPage = `min((int)(validated('limit') ?? 15), 100)` (code-verified double cap) | 1–100 | `15` | None | `2` | Page size |

No body fields. Eager loads on this action (code-verified): `product:id,name,sku`, `productVariant:id,sku,title`, `warehouse:id,name,code`, `sourceLocation:id,code,name`, `destinationLocation:id,code,name`, ordered id DESC.

## Request examples

Basic list (scoped caller sees home-warehouse rows):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/inventory/operations",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

Filtered + paginated (global holder):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/inventory/operations?operation=receipt&status=completed&limit=2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

Scoped foreign-filter probe (→ 404 anti-enumeration, test-verified):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/inventory/operations?warehouse_id=99",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: operations listed — HTTP 200 (read-only, nothing written)

Returned via `ApiResponse::apiResponse(__('wms.stock.operations_fetched'), 200, true, InventoryOperationResource::collection(paginate))` (translated message — never a raw `wms.` key; code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.stock.operations_fetched` | `en` → `"Stock operations fetched successfully."` (verified in `resources/lang/en/wms.php:237`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present on success | Paginated `InventoryOperationResource` item list, `orderByDesc('id')` | `[]` when none (still 200); tests read the item list via `data` (`assertCount(2, json('data'))` in `WmsInventoryOperationsListTest::test_manager_can_list_and_paginate_operations` — test-verified) |
| `data[].id` | integer | Per row | Ledger row id | — |
| `data[].key` | string/null | Per row | `idempotency_key` (use for show resolution) | e.g. `"rel-Ab12Cd34Ef"` |
| `data[].operation` | string | Per row | Operation kind | `receipt`, `transfer`, `initial_placement` (code-verified model constants) |
| `data[].status` | string | Per row | Ledger status | `processing`, `completed`, `failed` (code-verified model constants) |
| `data[].product_id` | integer | Per row | Owning product | — |
| `data[].product_variant_id` | integer/null | Per row | SKU identity | `null` = simple row |
| `data[].warehouse_id` | integer/null | Per row | Owning warehouse | Always set by writes in practice |
| `data[].source_location_id` | integer/null | Per row | Source bin | Set on transfers |
| `data[].destination_location_id` | integer/null | Per row | Destination bin | Set on receipts/transfers |
| `data[].quantity` | number | Per row | Ledger quantity (float) | `decimal:2` cast → float in resource |
| `data[].reference` | string/null | Per row | Caller reference | e.g. `"PO-S1"` |
| `data[].product` | object/null | When relation loaded | `{id, name, sku, product_type}` — on THIS action only `id,name,sku` are eager-loaded so `product_type` is `null` (code-verified eager list) | — |
| `data[].variant` | object/null | When relation loaded | `{id, sku, price, stock_quantity, attributes}` — on THIS action only `id,sku,title` are eager-loaded and `attributeProducts` is NOT loaded, so `attributes` is `[]` and `price`/`stock_quantity` fall back to `0` (code-verified eager list + resource fallback) | — |
| `data[].warehouse` | object/null | When relation loaded | `{id, name, code}` | — |
| `data[].source_location` | object/null | When relation loaded | `{id, code, name}` | — |
| `data[].destination_location` | object/null | When relation loaded | `{id, code, name}` | — |
| `data[].result` | object/array/null | Per row | Stored stored-result payload | e.g. `{received_quantity: 8}` |
| `data[].created_at` | string/null | Per row | ISO-8601 timestamp | — |
| `data[].updated_at` | string/null | Per row | ISO-8601 timestamp | — |

```json
{
  "status": 200,
  "message": "Stock operations fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 31,
      "key": "op-test-Ab12Cd34Ef56",
      "operation": "receipt",
      "status": "completed",
      "product_id": 9,
      "product_variant_id": null,
      "warehouse_id": 1,
      "source_location_id": null,
      "destination_location_id": 14,
      "quantity": 10,
      "reference": "PO-104",
      "product": { "id": 9, "name": "Widget", "sku": "W-1", "product_type": null },
      "variant": null,
      "warehouse": { "id": 1, "name": "Main", "code": "WH-1" },
      "source_location": null,
      "destination_location": { "id": 14, "code": "A-01", "name": "Bin A-01" },
      "result": { "received_quantity": 10 },
      "created_at": "2026-10-14T11:00:00+00:00",
      "updated_at": "2026-10-14T11:00:00+00:00"
    }
  ]
}
```

(Illustrative ids/codes; shape code-traced from `InventoryOperationResource`, message literal verified.)

# Errors

No error-code field; every error below has Error code `None`. Envelope split (code-verified via `Handler` + `ApiResponse`): success uses the `apiResponse` envelope `{status, message, success, data}`; ALL errors below use the EXCEPTION envelope `{message, status: false}` (+ `errors` map ONLY on the 422-validation row). No business-refusal 422 exists on this action (code-verified — no `unprocessable()` in `operations`).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, verified in `resources/lang/en/message.php:575`) | None | No valid token (code-traced; `WmsInventoryOperationsListTest::test_unauthenticated_request_is_rejected` asserts 401 on this URI — test-verified) | See 401 example |
| 403 | Authenticated caller lacks `view-location` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced; `WmsInventoryOperationsListTest::test_forbidden_without_view_location_permission` asserts 403 on this URI — test-verified) | See 403 example |
| 403 | Scoped caller with NO home warehouse (fail-closed `warehouseIdFor` → null → `AuthorizationException('Forbidden warehouse operation.')`, but Handler:173-175 maps ANY `AuthorizationException` to the catalogue — the verbatim string is DISCARDED, code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`, verified in `resources/lang/en/message.php:577`) | None | Homeless staffer with `view-location` (code-traced only — no current test asserts this path on this endpoint) | See 403-scope example |
| 404 | Scoped caller filtering by a foreign `?warehouse_id` (404 anti-enumeration via `authorizeWarehouseScope(requested, 'view-location', asNotFound: TRUE)` → `NotFoundHttpException('Resource Not Found')` → catalogue; code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (`message.ERROR.RESOURCE_NOT_FOUND`, verified in `resources/lang/en/message.php:579`) | None | `warehouse_id` ≠ home for non-`manage-warehouse` callers (`WmsInventoryOperationsListTest::test_scoped_user_cannot_view_foreign_warehouse_operations:200-202` asserts this 404 — test-verified) | See 404-scope example |
| 404 | Defensive-only: global holder narrowing to a denied `?warehouse_id` (same 404 anti-enumeration call; code-verified path but UNREACHABLE when the permission catalogue is complete — the route pipe already guarantees `view-location`; retained as a defensive contract, no live test) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (same catalogue) | None | Only a global holder failing the scope check (code-traced path) | See 404-scope example |
| 422 | `FormRequest` validation failure (fires BEFORE scope/filters — code-verified order) | `{message: string, status: boolean, errors: object}` (ONLY row with an `errors` map — `ValidationException` branch, code-verified Handler:178-186) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`, verified in `resources/lang/en/message.php:585`) | Per-field arrays, e.g. `operation: ["The selected operation is invalid."]`, `date_to: ["The date to must be a date after or equal to date from."]`, `limit: ["The limit must be at least 1."]` (illustrative framework texts — exact per-rule strings NOT live-verified in this session) | Bad `operation`/`status`, ghost `*_id` (`exists` fails), `reference` >255, unparseable dates, `date_to` < `date_from`, `limit` out of 1–100 (code-traced rules) | See 422 example |
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

422 example (ONLY envelope with an `errors` map; top message fixed, field texts illustrative):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "operation": ["The selected operation is invalid."]
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

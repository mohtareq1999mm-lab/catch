# Endpoint Name

Inventory – Product Placements (`GET /api/v1/admin/products/{product}/placements`)

# Description

Returns one product with ALL its location placements (the multi-location read model) inside the caller's warehouse scope, returning 200 with a `{product, placements}` payload (empty set → 200 with `[]`).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/products/{product}/placements` (explicit `{product}` placeholder with `whereNumber('product')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/products/{product}/placements` custom `Route::get(...)->name('api.admin.products.placements')` in `packages/marvel/src/Rest/Routes.php:703` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-location` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\InventoryController@productPlacements(Request $request, int $product)` → product lookup FIRST (`Product::whereKey->with(variations…)->firstOrFail`, miss → 404 translated `wms.stock.product_not_found` via explicit catch — NOT the fixed string, code-verified); then scope branch: `manage-warehouse` holders read optional `?warehouse_id` (non-null → `authorizeWarehouseScope(scope, 'view-location', asNotFound: TRUE)` 404 anti-enumeration path) else unscoped (ALL warehouses); non-global callers are forced to home warehouse (`warehouseIdFor`, null home → 403 catalogue — the thrown verbatim is DISCARDED, code-verified) and their `?warehouse_id` is IGNORED (code-verified — never read in that branch); then optional `?product_variant_id` check (unknown id or id of another product → 422 `wms.stock.variant_mismatch` via `unprocessable()` exception — NOT an `apiResponse` envelope, code-verified); then ONE scoped placement query (`product_id` + optional `warehouse_id` + optional `product_variant_id`, `orderBy('id')`, eager `location` + `variant…`) → 200 `ProductPlacementsResource` payload. NOTHING is written (pure read — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-location` (route pipe + scope check — BOTH the same permission; `manage-warehouse` is NOT required but widens scope to global + optional narrow) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.products.placements` (explicit named route; `route:list` verified; pinned by `WmsRbacCompletionTest`) |

Order matters (branches are per caller type, not sequential steps): 401 → 403-route → 404-product (lookup precedes ALL scope logic, so unknown ids 404 even for out-of-scope/homeless callers) → scoped callers: 403-homeless; global holders: 404-scope-filter (defensive-only — see table) → 422-variant → 200.

## Request inputs

No `FormRequest` — plain `Request` with manual parsing (no validation `422` with an `errors` map exists on this action; the single 422 is the `unprocessable()` exception below).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `product` | path | integer | Required | No | `whereNumber('product')` + `int $product`; must exist (else 404 translated, code-verified) | Existing product id | None | None | `9` | The product whose placements are read (catalog header is global; only placements are scoped) |
| `warehouse_id` | query | integer | Optional | Yes | `(int) get('warehouse_id', 0) ?: null`; read ONLY for `manage-warehouse` holders (non-null → scope-authorized with 404 anti-enumeration); IGNORED for scoped callers (forced to home — code-verified); non-numeric/`0`/absent → `null` → unscoped for globals | Warehouse id or omitted | `null` (scoped: home; global: all warehouses) | None | `4` | Narrows placements to one warehouse (globals only) |
| `product_variant_id` | query | integer | Optional | Yes | Non-blank → `(int)`; blank/`''`/absent → `null` (ignored); unknown id or variant of ANOTHER product → 422 (code-verified) | Variant id of this product or omitted | `null` (all SKUs) | None | `17` | Narrows placements to one SKU |

No body fields. `?warehouse_id=abc` behaves as absent (`(int)` casts to `0` → `null`); `?product_variant_id=abc` casts to `0` → 422 mismatch (no variant `0` exists).

## Request examples

Basic read (scoped caller sees home-warehouse placements):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/products/9/placements",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

Global narrow + SKU narrow:

```json
{
  "method": "GET",
  "url": "/api/v1/admin/products/9/placements?warehouse_id=4&product_variant_id=17",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: placements returned — HTTP 200 (read-only, nothing written)

Returned via `ApiResponse::apiResponse(__('wms.stock.placements_fetched'), 200, true, ProductPlacementsResource…)` (translated message — never a raw `wms.` key; code-verified). Scope confines PLACEMENTS only — the product header is global catalog data (a foreign-homed reader gets 200 with `placements: []` — status + empty-array test-authored in `scopes_and_404s`; the header content for that caller is code-verified, not assertion-verified). Central stock is reported, never mutated; placement `quantity`/`allocated_hint` sums are informational and never written back (resource-commented, code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.stock.placements_fetched` | `en` → `"Product placements fetched successfully."` (verified in `resources/lang/en/wms.php:231`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Payload | `product` + `placements` (keys verified in `ProductPlacementsResource`) |
| `data.product.id` | integer | Always present | Product id | Echo of the path id |
| `data.product.sku` | string/null | Always present | Product SKU | `null` when unset |
| `data.product.product_type` | string/null | Always present | Product type | e.g. `"simple"` |
| `data.product.has_variants` | boolean | Always present | Variant flag | `false` for simple products (test-authored) |
| `data.product.stock_quantity` | integer | Always present | Authority stock (SKU-level for simple) | Central authority, reported only |
| `data.product.reserved_quantity` | integer | Always present | Central reservations | Reported only |
| `data.product.available_quantity` | integer | Always present | `max(0, stock − reserved)` | Computed, test-authored (`30 − 4 = 26`) |
| `data.product.in_stock` | boolean | Always present | Stock flag | `in_stock` column or `stock > 0` |
| `data.product.variants` | array | Always present here | Per-variant stock + attributes | Controller always eager-loads `variations`, so `[]` for simple (never `null` on this action — code-verified); each `{id, sku, stock_quantity, reserved_quantity, available_quantity, in_stock, attributes: [{attribute, value}]}` |
| `data.placements` | array | Always present | Placement hint rows in scope, `orderBy('id')` | `[]` when none (still 200 — test-authored) |
| `data.placements[].id` | integer | Per row | Placement id | `product_locations.id` |
| `data.placements[].product_id` | integer | Per row | Owning product | Echo of path id |
| `data.placements[].product_variant_id` | integer/null | Per row | SKU identity | `null` = simple row (test-authored) |
| `data.placements[].sku` | string/null | Per row | Row SKU | Variant SKU when set, else product SKU |
| `data.placements[].variant_attributes` | array/null | Per row | Variant attributes | `null` for simple rows |
| `data.placements[].location_id` | integer | Per row | Bin id | — |
| `data.placements[].location_code` | string/null | Per row | Bin code | e.g. `"A-01"` (test-authored) |
| `data.placements[].warehouse_id` | integer | Per row | Owning warehouse | Always the row's warehouse (test-authored: all equal scope) |
| `data.placements[].quantity` | number | Per row | Hint quantity (float) | Informational only |
| `data.placements[].allocated_hint` | number | Per row | Hint allocation (float) | Informational only — NOT authoritative reservations |
| `data.placements[].allocated_hint_note` | string | Per row | Fixed disclaimer | Always `"informational-only"` (test-authored) |

```json
{
  "status": 200,
  "message": "Product placements fetched successfully.",
  "success": true,
  "data": {
    "product": {
      "id": 9,
      "sku": "SKU-ML-A",
      "product_type": "simple",
      "has_variants": false,
      "stock_quantity": 30,
      "reserved_quantity": 4,
      "available_quantity": 26,
      "in_stock": true,
      "variants": []
    },
    "placements": [
      {
        "id": 11,
        "product_id": 9,
        "product_variant_id": null,
        "sku": "SKU-ML-A",
        "variant_attributes": null,
        "location_id": 2,
        "location_code": "A-01",
        "warehouse_id": 1,
        "quantity": 10,
        "allocated_hint": 0,
        "allocated_hint_note": "informational-only"
      }
    ]
  }
}
```

Empty set (still 200, same message):

```json
{
  "status": 200,
  "message": "Product placements fetched successfully.",
  "success": true,
  "data": {
    "product": {
      "id": 10,
      "sku": "SKU-ML-B",
      "product_type": "simple",
      "has_variants": false,
      "stock_quantity": 0,
      "reserved_quantity": 0,
      "available_quantity": 0,
      "in_stock": true,
      "variants": []
    },
    "placements": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action. Envelope split (code-verified via `Handler`): success uses the `apiResponse` envelope `{status, message, success, data}`; ALL errors below (404-product included) use the EXCEPTION envelope `{message, status: false}` — the translated strings pass through `translateHttpMessage` verbatim, while the 422 travels via the `HttpException` branch (no `success` key, no integer `status` key).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (code-traced; RBAC 401-sweep covers all WMS URIs incl. this one) | See 401 example |
| 403 | Authenticated caller lacks `view-location` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced; RBAC 403-sweep covers all WMS URIs incl. this one) | See 403 example |
| 403 | Scoped caller with NO home warehouse (fail-closed `warehouseIdFor → null` → `AuthorizationException('Forbidden warehouse operation.')`, but `Handler:173-175` maps ANY `AuthorizationException` to the catalogue — the verbatim string is DISCARDED, code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`, code-verified) | None | Homeless staffer with `view-location` (code-traced only — no current test asserts this path on this endpoint) | See 403-scope example |
| 404 | No product with `{product}` (explicit catch → translated `wms.stock` key — NOT the fixed string; fires BEFORE any scope logic, code-verified) | `{message: string, status: boolean}` | None | Translated `wms.stock.product_not_found`: `en` → `"Product not found."` (verified in `resources/lang/en/wms.php:213`; Handler passes the custom string through verbatim — code-verified) | None | Unknown numeric id, e.g. `999999` (code-traced; test-authored 404 status-only in `scopes_and_404s`) | See 404-product example |
| 404 | Defensive-only: global holder narrowing to a denied `?warehouse_id` (404 anti-enumeration via `authorizeWarehouseScope(..., asNotFound: TRUE)` → `NotFoundHttpException('Resource Not Found')` → catalogue; code-verified path but UNREACHABLE in practice — the route pipe already guarantees `view-location` and `allows()` returns unconditionally for `manage-warehouse` holders, so the deny cannot fire; retained as a defensive contract) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (`message.ERROR.RESOURCE_NOT_FOUND`, code-verified) | None | Only a global holder missing `view-location` at scope-check time (route gate would 403 first — code-traced path, no live test) | See 404-scope example |
| 404 | Non-numeric `{product}` matches NO route (`whereNumber` constraint) | `{message: string, status: boolean}` | None | Framework passthrough (`translateHttpMessage` returns the raw route-miss text verbatim; `"Not Found"` only when the exception message is empty via `HTTP_NOT_FOUND` — code-verified Handler:112-121, literal NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | `?product_variant_id` names an unknown variant OR a variant of ANOTHER product (`ProductVariant::whereKey->first()` + `product_id` comparison → `unprocessable()` → `UnprocessableEntityHttpException` → Handler `HttpException` branch: EXCEPTION envelope, code-verified) | `{message: string, status: boolean}` (NO `success` key, NO integer `status` key, NO `errors` map) | None | Translated `wms.stock.variant_mismatch` WITH both ids: `en` → `"Variant #17 does not belong to product #9."` (ids illustrative; verified in `resources/lang/en/wms.php:215`) | None | Cross-product or ghost variant filter (code-traced; message-literal test-authored for `1`/`2`) | See 422-variant example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

> Evidence conflict (UNVERIFIED — flagged, not hidden): `WmsVariantInventoryTest::test_warehouse_isolation_for_variant_ops:382-384` asserts a SCOPED (non-`manage-warehouse`) caller probing a foreign `?warehouse_id` gets 404. The implementation ignores `?warehouse_id` for scoped callers (else-branch never reads the param — code-verified above), which yields 200 with home-scope rows instead. That 404 assertion is NOT reconcilable with the current code and was NOT re-run green in this session (DB env failure); the table above documents only the code-verified 404 path. (A second such assertion previously existed in `WmsMultiLocationTest` but is gone from the current working tree — that file was externally modified mid-session and no longer asserts scoped-filter 404.) If the 404-on-foreign-filter is the intended contract, the controller needs a scope-vs-filter check; if the ignore-filter behavior is intended, that assertion needs updating.

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

404-product example (translated, NOT the fixed string):

```json
{
  "message": "Product not found.",
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

422-variant example (EXCEPTION envelope — no `success` key, no map):

```json
{
  "message": "Variant #17 does not belong to product #9.",
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

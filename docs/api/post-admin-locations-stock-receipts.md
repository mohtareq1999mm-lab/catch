# Endpoint Name

Inventory – Receive Stock (`POST /api/v1/admin/locations/{location}/stock-receipts`)

# Description

Adds new physical stock at a placeable location with idempotent retries, returning 201 or 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/locations/{location}/stock-receipts` (explicit `{location}` placeholder with `whereNumber('location')` — the LOCATION id in the path, the PRODUCT id in the body; code-verified split) |
| Path composition | `api/v1` prefix + `admin/locations/{location}/stock-receipts` from the `Route::prefix('admin/locations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.locations.stock-receipts`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\InventoryController@receive(ReceiveStockRequest $request, int $location)` → validated payload → `resolvePlaceableLocation` (miss → 404 translated `wms.stock.location_not_found`; INACTIVE → 422 live; non-placeable type (quarantine/damaged/returns) → 422 live — both test-verified with stock provably unchanged); `authorizeWarehouseScope(resolved-warehouse, 'inventory-adjust')` — scope resolves from the LOCATION row, never request data (cross-warehouse → verbatim 403 with stock provably unchanged, test-verified); then `receiveStock`: unknown PRODUCT → 404 translated `wms.stock.product_not_found` (explicit catch — a MISS, not a refusal); variable-with-variants or DIGITAL products → 422 live (both test-verified); authority AND placement BOTH increment (test-verified 100→150 authority with per-location splits); keyed retry → 200 SAME result with `replayed: true` and a SINGLE ledger row (test-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `inventory-adjust` via the route pipe + scope check — BOTH the same permission (a viewer fails both; the placement twin proves the pipe) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.locations.stock-receipts` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (location lookup) → 422 (placeability) → 403-scope → 404 (product) → 422-engine → 201/200.

## Request inputs

`ReceiveStockRequest` runs before the body (`authorize: true` — the gates above already ran). Quantity bounds are `int32` (`max:2147483647` — code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `location` | path | integer | Required | No | `whereNumber('location')` + `int $location`; must be ACTIVE + placeable (else 422 AFTER validation — code-verified) | Active, placeable location id manageable by the caller | None | None | `2` | Receiving bin (NOT the product) |
| `product_id` | body | integer | Required | No | `required\|integer\|exists:products,id` (unknown ids 422 HERE at validation — unlike the PATH location which 404s; the test comment calls this out explicitly: body-id fails closed at validation, route-location bypasses it) | Existing product id (simple-physical for success) | None | None | `9` | The product gaining stock |
| `quantity` | body | integer | Required | No | `required\|integer\|min:1\|max:2147483647` | `1`–`2147483647` | None | None | `50` | Units to add (0/negative → 422, test-verified with stock proof) |
| `reference` | body | string | Optional | Yes | `nullable\|string\|max:255` | Any string or null | `null` | None | `"PO-1"` | Audit reference (test-verified passthrough) |
| `idempotency_key` | body | string | Optional | Yes | `nullable\|string\|max:64` (blank/whitespace-only keys trim to empty → treated as ABSENT by the shell, code-verified) | Any string or null | None (keyless calls execute once, no ledger row) | None | `"recv-abc123"` | Replay key (UNIQUE ledger backstop; lost-race winner reloaded when completed) |

## Request examples

Valid request receiving stock (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/locations/2/stock-receipts",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "product_id": 9,
    "quantity": 50,
    "reference": "PO-1"
  }
}
```

# Responses

## Success: stock received — HTTP 201 (fresh) or 200 (replay, SAME result)

Returned via `ApiResponse::apiResponse($replayed ? __('wms.stock.receipt_replay') : __('wms.stock.receipt_ok'), $replayed ? 200 : 201, true, ['operation' => ..., 'replayed' => ...])` (translated `wms.stock.*` messages — locale from app locale, NOT a `lang` header since the group has no `lang` middleware; code-verified). Verified: fresh 201 + authority 100→150 + per-location splits + `data.operation.stock_quantity: 150`; keyed retry 200 + `replayed: true` + unchanged stock + SINGLE ledger row (both `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | `201` fresh / `200` replay |
| `message` | string | Always present | Translated `wms.stock.receipt_ok` / `receipt_replay` | `en` → `"Stock received successfully."` / `"Stock receipt already processed."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Outcome payload (keys verified) | `operation` (StockOperationResource array — `product_id`, `warehouse_id`, `stock_quantity` (int), `reserved_quantity`, `available_quantity`, `in_stock`, `received_quantity`, `placement`, `reference`), `replayed` (bool) |

```json
{
  "status": 201,
  "message": "Stock received successfully.",
  "success": true,
  "data": {
    "operation": {
      "product_id": 9,
      "warehouse_id": 1,
      "stock_quantity": 150,
      "reserved_quantity": 0,
      "available_quantity": 150,
      "in_stock": true,
      "received_quantity": 50,
      "placement": {},
      "source_placement": null,
      "destination_placement": null,
      "reference": "PO-1"
    },
    "replayed": false
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Guest call (verified by `test_receive_guest_is_unauthorized` → 401) | See 401 example |
| 403 | Authenticated caller lacks `inventory-adjust` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (same pair as the placement twin) | See 403 example |
| 403 | Out-of-scope `inventory-adjust` holder at the SCOPE layer, resolved from the LOCATION row (verbatim, stock provably unchanged) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Foreign location (verified by `test_receive_enforces_warehouse_scope` → 403 + stock proof) | See 403-scope example |
| 404 | No LOCATION with path id (explicit catch → translated `wms.stock` key — NOT the fixed string; code-verified) | `{message: string, status: boolean}` | None | Translated `wms.stock.location_not_found`: `en` → `"Location not found."` | None | Unknown path id with valid body (verified → 404 alongside the body-id 422 twin) | See 404-location example |
| 404 | No PRODUCT with body id (explicit catch → translated `wms.stock` key — a MISS here, unlike the 422 body-id twin above... precisely: UNKNOWN body ids 422 at VALIDATION (`exists` rule); this 404 fires only for TOCTOU races where the row vanishes after validation — code-verified split) | `{message: string, status: boolean}` | None | Translated `wms.stock.product_not_found`: `en` → `"Product not found."` | None | Vanished product (code-traced race path) | See 404-product example |
| 404 | Non-numeric `{location}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | `ReceiveStockRequest` fails (unknown `product_id`, `quantity: 0`/`-3`, overlong `reference`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (unknown-product AND bad-quantity twins verified → 422 with stock proofs) | See 422-validation example |
| 422 | Location INACTIVE or NON-PLACEABLE type (live `wms.stock` text, stock provably unchanged) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | Live text with id (e.g. `"Location #2 is not active and cannot receive stock."` / `"Location #3 (type quarantine) is not placeable."` — shapes verified, interpolation values illustrative) | None | Dead/wrong-typed bin (BOTH verified → 422 with stock proofs) | See 422-place example |
| 422 | Variable-with-variants or DIGITAL product (representability guard) | `{message: string, status: boolean}` (NO `errors` map) | None | Live `wms.stock` text | None | Non-receivable product (BOTH verified → 422) | See 422-product example |
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

403-scope example (verbatim, stock unchanged):

```json
{
  "message": "Forbidden warehouse operation.",
  "status": false
}
```

404-location example (translated `wms.stock` key):

```json
{
  "message": "Location not found.",
  "status": false
}
```

404-product example (translated `wms.stock` key — race path):

```json
{
  "message": "Product not found.",
  "status": false
}
```

404-constraint example:

```json
{
  "message": "Not Found",
  "status": false
}
```

422-validation example:

```json
{
  "message": "The quantity field must be at least 1.",
  "status": false,
  "errors": {
    "quantity": ["The quantity field must be at least 1."]
  }
}
```

422-place example (live text with id, no map):

```json
{
  "message": "Location #2 is not active and cannot receive stock.",
  "status": false
}
```

422-product example (live text, no map):

```json
{
  "message": "Live product-guard text (exact string guard-dependent)",
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

# Endpoint Name

Inventory – Transfer Stock (`POST /api/v1/admin/stock-transfers`)

# Description

Moves stock between same-warehouse locations with provable total invariance and idempotent retries.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/stock-transfers` (NO path id — both locations ride in the body; code-verified, unlike the receipt twin) |
| Path composition | `api/v1` prefix + `admin/stock-transfers` from the SEPARATE `Route::prefix('admin')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.stock-transfers`) in `packages/marvel/src/Rest/Routes.php` — different prefix family from the receipt twin |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\InventoryController@transfer(TransferStockRequest $request)` → validated payload → BOTH locations resolved placeable FIRST (either miss → 404 translated; either inactive/unplaceable → 422 live — same guards as receipt, code-verified); then DUAL scope checks — source AND destination warehouses EACH authorized (source checked first so foreign-source probes 403 BEFORE the cross-warehouse business refusal, explicitly to block enumeration; destination checked second — code-verified order + comment); then `transferStock`: same-id → 422 live; cross-warehouse → 422 live (explicit, never silent cross-posting — source-commented integrity rule); missing source row → 422 live (transfers NEVER create stock — no zero-row writes, code-verified); placement-warehouse drift → 422 live; insufficient AVAILABLE (`quantity - allocated_hint`) → 422 live; deterministic id-ordered row locks (deadlock-proofing, code-verified); authority before/after comparison (altered total → `RuntimeException`, code-verified); keyed retry → 200 SAME result (test-verified with placement proofs) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `inventory-adjust` via the route pipe + DUAL scope checks — all three the same permission |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.stock-transfers` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (location lookups) → 422 (placeability) → 403-source-scope → 403-destination-scope → 404 (product race) → 422-engine → 201/200.

## Request inputs

`TransferStockRequest` runs before the body (`authorize: true` — the gates above already ran).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `product_id` | body | integer | Required | No | `required\|integer\|exists:products,id` (unknown ids 422 at validation — same fail-closed twin as receipt) | Existing product id (simple-physical for success) | None | None | `9` | The product moving (authority total provably UNCHANGED — test-verified 150→150) |
| `source_location_id` | body | integer | Required | No | `required\|integer\|exists:locations,id` (trashed counts as existing at validation, then 404s at lookup — same split as receipt) | Existing location id | None | None | `1` | Origin bin (must differ from destination AND share its warehouse) |
| `destination_location_id` | body | integer | Required | No | Same rule set as `source_location_id` | Existing location id | None | None | `2` | Target bin (same warehouse only) |
| `quantity` | body | integer | Required | No | `required\|integer\|min:1\|max:2147483647` | `1`–`2147483647` | None | None | `30` | Units to move (bounded by source AVAILABLE, not raw quantity — `allocated_hint` counts against it, test-verified) |
| `reference` | body | string | Optional | Yes | `nullable\|string\|max:255` | Any string or null | `null` | None | `"REBAL-1"` | Audit reference (test-verified passthrough) |
| `idempotency_key` | body | string | Optional | Yes | `nullable\|string\|max:64` | Any string or null | None (keyless executes once, no ledger row) | None | `"xfer-abc123"` | Replay key (UNIQUE ledger backstop) |

## Request examples

Valid request moving stock (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/stock-transfers",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "product_id": 9,
    "source_location_id": 1,
    "destination_location_id": 2,
    "quantity": 30,
    "reference": "REBAL-1"
  }
}
```

# Responses

## Success: stock moved — HTTP 201 (fresh) or 200 (replay, SAME result)

Returned via `ApiResponse::apiResponse($replayed ? __('wms.stock.transfer_replay') : __('wms.stock.transfer_ok'), $replayed ? 200 : 201, true, ['operation' => ..., 'replayed' => ...])` (translated messages). Verified: fresh 201 + total 150 held + 100/50→70/80 splits; keyed retry 200 + `replayed: true` + identical splits (both `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | `201` fresh / `200` replay |
| `message` | string | Always present | Translated `wms.stock.transfer_ok` / `transfer_replay` | `en` → `"Stock transferred successfully."` / `"Stock transfer already processed."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Outcome payload (keys verified) | `operation` (StockOperationResource — `transferred_quantity` instead of `received_quantity`, plus `source_placement`/`destination_placement` snapshots), `replayed` (bool) |

```json
{
  "status": 201,
  "message": "Stock transferred successfully.",
  "success": true,
  "data": {
    "operation": {
      "product_id": 9,
      "warehouse_id": 1,
      "stock_quantity": 150,
      "reserved_quantity": 0,
      "available_quantity": 150,
      "in_stock": true,
      "received_quantity": null,
      "transferred_quantity": 30,
      "placement": null,
      "source_placement": {},
      "destination_placement": {},
      "reference": "REBAL-1"
    },
    "replayed": false
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Guest call (same stack as the receipt twin's 401 test) | See 401 example |
| 403 | Authenticated caller lacks `inventory-adjust` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (same pair as receipt) | See 403 example |
| 403 | Out-of-scope holder at the SOURCE check (first — enumeration-blocking order) or DESTINATION check (second), verbatim | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Foreign source and/or destination (code-traced order; receipt twin proves the source shape) | See 403-scope example |
| 404 | Either LOCATION misses (`firstOrFail` inside resolve — translated `wms.stock` key, NOT the fixed string) | `{message: string, status: boolean}` | None | Translated `wms.stock.location_not_found`: `en` → `"Location not found."` | None | Unknown location id (code-traced; receipt twin verifies the message) | See 404-location example |
| 404 | PRODUCT vanishes post-validation (TOCTOU race → translated `wms.stock` key) | `{message: string, status: boolean}` | None | Translated `wms.stock.product_not_found`: `en` → `"Product not found."` | None | Vanished product (code-traced race path) | See 404-product example |
| 422 | `TransferStockRequest` fails (unknown ids, `quantity: 0`, overlong `reference`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Inactive/unplaceable location, non-receivable product (same guard family as receipt) | `{message: string, status: boolean}` (NO `errors` map) | None | Live `wms.stock` text | None | Dead/wrong-typed bin or product (code-traced family) | See 422-guard example |
| 422 | Same source AND destination (explicit self-move refusal) | `{message: string, status: boolean}` (NO `errors` map) | None | Live `wms.stock.transfer_same` text | None | Identical ids (verified → 422 with placements provably held) | See 422-same example |
| 422 | CROSS-warehouse pair (explicit integrity refusal — never a silent cross-posting) | `{message: string, status: boolean}` (NO `errors` map) | None | Live `wms.stock.transfer_cross` text | None | Warehouses differ (verified → 422 with placement proof) | See 422-cross example |
| 422 | No source placement row (transfers never create stock — fail-closed WITHOUT zero-row writes) | `{message: string, status: boolean}` (NO `errors` map) | None | Live `wms.stock.no_stock` text with product+location | None | Unstocked source (code-traced) | See 422-nostock example |
| 422 | INSUFFICIENT available (`quantity - allocated_hint < requested` — reservations bind first) | `{message: string, status: boolean}` (NO `errors` map) | None | Live `wms.stock.insufficient` text with location/available/quantity | None | Over-draw (verified ×2: raw 500-request AND allocated-hint-bound 30-request, both with held proofs) | See 422-short example |
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

403-scope example (verbatim WITH period):

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

422-guard example (live text, no map):

```json
{
  "message": "Live guard text (exact string guard-dependent)",
  "status": false
}
```

422-same example (live text, no map):

```json
{
  "message": "Live same-location refusal text (wms.stock.transfer_same)",
  "status": false
}
```

422-cross example (live text, no map):

```json
{
  "message": "Live cross-warehouse refusal text (wms.stock.transfer_cross)",
  "status": false
}
```

422-nostock example (live text with ids, no map):

```json
{
  "message": "Live no-stock text with product and location (wms.stock.no_stock)",
  "status": false
}
```

422-short example (live text with figures, no map):

```json
{
  "message": "Live insufficiency text with location, available and quantity (wms.stock.insufficient)",
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

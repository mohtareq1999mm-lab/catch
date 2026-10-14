# Endpoint Name

Inventory – Operation Status (`GET /api/v1/admin/inventory/operations/{key}`)

# Description

Resolves one ledger operation by its idempotency key (Case-C retry resolution: after a timeout or lost response, learn whether the keyed receipt/transfer committed, is still running, or never arrived), returning 200 with a single rich `InventoryOperationResource` object. Read-only; foreign/homeless callers get 404 anti-enumeration, never 403 (code-verified).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/inventory/operations/{key}` (explicit `{key}` placeholder with NO `whereNumber` — any single non-empty segment matches; `operations` alone matches the sibling index route) |
| Path composition | `api/v1` prefix + `admin/inventory/operations/{key}` custom `Route::get(...)->name('api.admin.inventory.operations.show')` in `packages/marvel/src/Rest/Routes.php:711` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-location` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\InventoryController@operationStatus(string $key)` (`InventoryController.php:368`, code-verified) → `trim($key)` FIRST (blank/whitespace-only → null → 404 path, code-verified); then `InventoryOperation::where('idempotency_key', key)->first()` (miss → `NotFoundHttpException(__('wms.stock.operation_not_found'))` translated 404 — NOT the fixed string, code-verified); then scope: `manage-warehouse` holders skip the check (resolve ANY warehouse — test-verified); non-global callers resolve home via `warehouseIdFor` and require home !== null AND `(int)operation.warehouse_id === home`, else the SAME translated 404 (404 anti-enumeration — foreign AND homeless both map to 404, code-verified; there is NO 403-scope path on this action); then `loadMissing([product:id,name,sku,product_type, productVariant.attributeProducts…, warehouse, sourceLocation, destinationLocation])` → 200 single-resource payload. NOTHING is written (pure read — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-location` (route pipe only; `manage-warehouse` is NOT required but bypasses the warehouse-equality check) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.inventory.operations.show` (explicit named route) |

Order matters: 401 → 403-route → 404-miss/blank (lookup precedes ALL scope logic, so unknown keys 404 even for out-of-scope/homeless callers) → 404-foreign/homeless (anti-enumeration) → 200. No validation 422 and no business-refusal 422 exist on this action (no `FormRequest`, no `unprocessable()` call in `operationStatus` — code-verified).

## Request inputs

No `FormRequest` — raw string `$key` with `trim()` (no validation 422 with an `errors` map exists on this action).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `key` | path | string | Required | No | Trimmed; empty-after-trim → 404; otherwise must equal an existing `inventory_operations.idempotency_key` in the caller's scope (else 404 translated, code-verified) | Existing idempotency key visible to the caller | None | None | `rel-Ab12Cd34Ef` | The idempotency key of the receipt/transfer to resolve |

No query parameters or body fields are read. `GET /api/v1/admin/inventory/operations/%20` (blank after trim) → 404 translated (code-traced). Deeper paths (`/operations/a/b`) match NO route.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/inventory/operations/rel-Ab12Cd34Ef",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: operation resolved — HTTP 200 (read-only, nothing written)

Returned via `ApiResponse::apiResponse(__('wms.stock.operation_fetched'), 200, true, (new InventoryOperationResource(operation))->toArray(request()))` (translated message + single object — code-verified). `status` semantics (code-verified model constants + test-verified `completed`/`processing` reads): `completed` = committed with stored result; `processing` = still running → wait and re-poll, do NOT start a second attempt elsewhere; absence (404) = never arrived or rolled back without committing (failed attempts leave no ledger row — controller-commented intent; 404 is then safe-to-retry, preferably with the same key and identical payload).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.stock.operation_fetched` | `en` → `"Stock operation fetched successfully."` (verified in `resources/lang/en/wms.php:236`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Single operation object | Keys below (verified in `InventoryOperationResource`) |
| `data.id` | integer | Always present | Ledger row id | — |
| `data.key` | string/null | Always present | `idempotency_key` (echo of `{key}`) | Test-verified echo (`assertSame(key, json('data.key'))` in `WmsReceivingReliabilityTest` + `WmsInventoryOperationsListTest`) |
| `data.operation` | string | Always present | Operation kind | `receipt`, `transfer`, `initial_placement` (test-verified `receipt`) |
| `data.status` | string | Always present | Ledger status | `processing`, `completed`, `failed` (test-verified `completed` + `processing`) |
| `data.product_id` | integer | Always present | Owning product | Test-verified echo |
| `data.product_variant_id` | integer/null | Always present | SKU identity | `null` = simple row (test-verified null case + variant-id case) |
| `data.warehouse_id` | integer/null | Always present | Owning warehouse | — |
| `data.source_location_id` | integer/null | Always present | Source bin | — |
| `data.destination_location_id` | integer/null | Always present | Destination bin | Test-verified echo of receipt bin |
| `data.quantity` | number | Always present | Ledger quantity (float) | Test-verified (`assertEquals(8, (float) quantity)`) |
| `data.reference` | string/null | Always present | Caller reference | Test-verified (`PO-S1`) |
| `data.product` | object/null | When loaded (always on this action) | `{id, name, sku, product_type}` | Rich header test-verified (T-Shirt Master / TSHIRT-MASTER-SKU / `variable` in `test_operation_status_returns_rich_product_and_variant_details`) |
| `data.variant` | object/null | When loaded + variant set | `{id, sku, price, stock_quantity, attributes: [{attribute, value}]}` | Rich SKU test-verified (TSHIRT-RED-XL / 130 / 20 / array attributes); `null` when simple row |
| `data.warehouse` | object/null | When loaded | `{id, name, code}` | — |
| `data.source_location` | object/null | When loaded | `{id, code, name}` | — |
| `data.destination_location` | object/null | When loaded | `{id, code, name}` | — |
| `data.result` | object/array/null | Per row | Stored result payload | Test-verified (`result.received_quantity = 8`) |
| `data.created_at` | string/null | Always present | ISO-8601 timestamp | — |
| `data.updated_at` | string/null | Always present | ISO-8601 timestamp | — |

```json
{
  "status": 200,
  "message": "Stock operation fetched successfully.",
  "success": true,
  "data": {
    "id": 42,
    "key": "rel-Ab12Cd34Ef",
    "operation": "receipt",
    "status": "completed",
    "product_id": 9,
    "product_variant_id": null,
    "warehouse_id": 1,
    "source_location_id": null,
    "destination_location_id": 14,
    "quantity": 8,
    "reference": "PO-S1",
    "product": { "id": 9, "name": "Widget", "sku": "W-1", "product_type": "simple" },
    "variant": null,
    "warehouse": { "id": 1, "name": "Main", "code": "WH-1" },
    "source_location": null,
    "destination_location": { "id": 14, "code": "A-01", "name": "Bin A-01" },
    "result": { "received_quantity": 8 },
    "created_at": "2026-10-14T11:00:00+00:00",
    "updated_at": "2026-10-14T11:00:00+00:00"
  }
}
```

Still-running variant (same message, `status: "processing"` — test-verified in `test_operation_status_reports_processing`):

```json
{
  "status": 200,
  "message": "Stock operation fetched successfully.",
  "success": true,
  "data": {
    "id": 43,
    "key": "rel-Gh56Ij78Kl",
    "operation": "receipt",
    "status": "processing",
    "product_id": 9,
    "product_variant_id": null,
    "warehouse_id": 1,
    "source_location_id": null,
    "destination_location_id": 15,
    "quantity": 2,
    "reference": null,
    "product": { "id": 9, "name": "Widget", "sku": "W-1", "product_type": "simple" },
    "variant": null,
    "warehouse": { "id": 1, "name": "Main", "code": "WH-1" },
    "source_location": null,
    "destination_location": { "id": 15, "code": "A-02", "name": "Bin A-02" },
    "result": null,
    "created_at": "2026-10-14T11:00:00+00:00",
    "updated_at": "2026-10-14T11:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action. Envelope split (code-verified via `Handler`): success uses the `apiResponse` envelope `{status, message, success, data}`; ALL errors below use the EXCEPTION envelope `{message, status: false}` — the translated `wms.stock.operation_not_found` string passes through `translateHttpMessage` verbatim (custom string, not a mapped literal — code-verified Handler:259-279).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, verified in `resources/lang/en/message.php:575`) | None | No valid token (code-traced; RBAC 401-sweep covers all WMS URIs) | See 401 example |
| 403 | Authenticated caller lacks `view-location` (route pipe — the ONLY 403 path on this action) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced; RBAC 403-sweep covers all WMS URIs) | See 403 example |
| 404 | No operation with `{key}` (lookup miss — fires BEFORE scope, so unknown keys 404 even for foreign/homeless callers; explicit catch → translated `wms.stock` key, NOT the fixed string, code-verified) | `{message: string, status: boolean}` | None | Translated `wms.stock.operation_not_found`: `en` → `"Stock operation not found."` (verified in `resources/lang/en/wms.php:238`; Handler passes the custom string through verbatim — code-verified) | None | Unknown key, e.g. `rel-no-such-key` (`WmsReceivingReliabilityTest::test_operation_status_unknown_key_404` asserts this 404 — test-verified) | See 404-operation example |
| 404 | Blank `{key}` after `trim()` (whitespace-only/empty → null → same miss path, code-verified) | `{message: string, status: boolean}` | None | Same translated `"Stock operation not found."` | None | Empty/blank segment (code-traced only) | See 404-operation example |
| 404 | Scoped caller probing a foreign-warehouse operation OR homeless caller (`warehouseIdFor` → null) probing ANY operation (404 anti-enumeration — SAME translated key, not Resource Not Found and not 403; code-verified branch) | `{message: string, status: boolean}` | None | Same translated `"Stock operation not found."` | None | `operation.warehouse_id` !== home or home === null for non-`manage-warehouse` callers (`WmsReceivingReliabilityTest::test_operation_status_cross_warehouse_hidden` asserts scoped-404 + global-200 on the same foreign key — test-verified) | See 404-operation example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (missing permission — the only 403 on this action):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404-operation example (translated, NOT the fixed string — covers miss, blank, foreign, and homeless):

```json
{
  "message": "Stock operation not found.",
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

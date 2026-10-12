# Endpoint Name

Fulfillment Items – Assign Placement (`POST /api/v1/admin/fulfillment-items/{id}/assign-placement`)

# Description

Pins one fulfillment item to a same-product placement with item-warehouse scoping, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/fulfillment-items/{id}/assign-placement` (explicit `{id}` placeholder with `whereNumber('id')` — NOTE the ITEM id, not the fulfillment id; code-verified) |
| Path composition | `api/v1` prefix + `admin/fulfillment-items/{id}/assign-placement` from the SEPARATE `Route::prefix('admin/fulfillment-items')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.fulfillment-items.assign-placement`) in `packages/marvel/src/Rest/Routes.php` — different prefix family from the seven fulfillment actions above |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@assignPlacement(AssignPlacementRequest $request, int $id)` → item `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST — code-verified order); then the FULFILLMENT resolved THROUGH the item (`$item->fulfillment()->firstOrFail()` — an orphaned item 404s here, code-verified second lookup); `authorizeWarehouseScope(fulfillment.warehouse_id, 'manage-fulfillment')` — scope follows the ITEM's warehouse, never request data (source-commented P9-4 rule; verbatim-403 path); then `FulfillmentService::assignPlacement` (wrong-PRODUCT placement → `RuntimeException` → 422 live; the target's own warehouse is NOT re-checked beyond the item scope — the passing test uses same-warehouse placements, code-verified) → 200 `FulfillmentItemResource` of the FRESH row |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-fulfillment` via the route pipe (no constructor/inline check exists — the pipe alone decides) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillment-items.assign-placement` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (item lookup) → 404 (orphaned fulfillment) → 403-scope → 200/422-engine.

## Request inputs

`AssignPlacementRequest` runs before the body (single `product_location_id` rule — product-match is a SERVICE check, not validation; code-verified split).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Fulfillment ITEM id manageable via its warehouse | None | None | `11` | Identifies the fulfillment item (NOT the fulfillment) |
| `product_location_id` | body | integer | Required | No | `required\|integer\|exists:product_locations,id` (row must exist; SAME-PRODUCT match enforced by the service, not here) | Existing placement id | None | None | `31` | Target placement (must stock the item's product) |

## Request examples

Valid request pinning an item (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillment-items/11/assign-placement",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "product_location_id": 31
  }
}
```

# Responses

## Success: placement pinned — HTTP 200 (item resource)

Returned via `ApiResponse::apiResponse('Placement assigned successfully.', 200, true, FulfillmentItemResource::make($assigned->fresh()))` (literal message; FRESH row — code-verified). Verified by `test_assign_placement_pins_item_and_scopes_by_item_warehouse` (200 + pinned-id proof, `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Placement assigned successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `FulfillmentItemResource` (11 keys, verified) | `id`, `fulfillment_id`, `order_item_id`, `product_id`, `product_variant_id`, `product_location_id` (pinned), `quantity`, `quantity_picked`, `status`, `picked_at`, `packed_at` |

```json
{
  "status": 200,
  "message": "Placement assigned successfully.",
  "success": true,
  "data": {
    "id": 11,
    "fulfillment_id": 1,
    "order_item_id": 5,
    "product_id": 9,
    "product_variant_id": null,
    "product_location_id": 31,
    "quantity": 1,
    "quantity_picked": 0,
    "status": "pending",
    "picked_at": null,
    "packed_at": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `manage-fulfillment` holder at the SCOPE layer, resolved through ITEM → FULFILLMENT (verbatim) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Foreign-warehouse item (code-traced shape; the twin test proves 403-scoping on sibling writes) | See 403-scope example |
| 404 | No fulfillment ITEM with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric item id with valid body (code-traced) | See 404 example |
| 404 | Item exists but its FULFILLMENT is gone (orphaned second lookup) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string — identical text, code-verified) | None | Orphaned item (code-traced) | See 404-orphan example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `AssignPlacementRequest` fails (missing/unknown `product_location_id`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Placement stocks a DIFFERENT product than the item (service product-match — NOT validation; code-verified split) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | Live engine text (exact string engine-dependent) | None | Wrong-product placement (verified → 422 with pin provably unchanged) | See 422-engine example |
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

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-orphan example (identical text):

```json
{
  "message": "Resource Not Found",
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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422-validation example:

```json
{
  "message": "The product location id field is required.",
  "status": false,
  "errors": {
    "product_location_id": ["The product location id field is required."]
  }
}
```

422-engine example (live text, no map):

```json
{
  "message": "Live engine mismatch text (exact string engine-dependent)",
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

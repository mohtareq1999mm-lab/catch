# Endpoint Name

Packages – Add Item (`POST /api/v1/admin/packages/{id}/add-item`)

# Description

Adds picked quantity of one fulfillment item to an open package (capped by picked quantity; additive merges), returning 200 with package detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packages/{id}/add-item` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packages/{id}/add-item` custom `Route::post(...)->name('api.admin.packages.add-item')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:packing-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackageController@addItem(AddPackageItemRequest $request, int $id)` → validated payload → package lookup OUTSIDE the try (`whereKey->firstOrFail` — miss → 404 fixed); `authorizeWarehouseScope(package→fulfillment warehouse, 'packing-execute', asNotFound: FALSE)`; then `PackingService::addItemToPackage(package, itemId, quantity)` — ANY `\Exception` → 422 with the message (narrow by design: no other fallible work — code-verified). Service, under lock (code-verified): package MUST be `open` (else 422 `package_status`); cancelled fulfillment → 422 `pack_cancelled`; item lookup (miss path below); foreign-fulfillment item → 422 `package_foreign_item`; `≤ 0` → 422 `package_qty_positive` (defensive post-validation); NOTHING picked yet → 422 `pack_unpicked`; over picked → 422 `overpack`; else `updateOrCreate` merge (additive — same pair accumulates, code-verified). QUIRK (code-verified): the ITEM lookup lives INSIDE the try, so an unknown `fulfillment_item_id` (no `exists` rule on the request) surfaces as 422 with the framework's no-query-results text — NOT 404 |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `packing-execute` via the route pipe + scope check |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packages.add-item` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-package → 403-scope → 422-state/item/quantity → 200.

## Request inputs

`AddPackageItemRequest` runs before the action (`authorize: true`). NOTE (code-verified): NO `exists` rule on `fulfillment_item_id` — unknown ids do NOT 422 at validation; they 422 (not 404) inside the service — see quirk above.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; package must be `open` for success | Open package id manageable by the caller | None | None | `4` | Identifies the package |
| `fulfillment_item_id` | body | integer | Required | No | `required\|integer\|min:1` (unknown ids → 422 in-service quirk, code-verified) | Picked item id of the SAME fulfillment | None | None | `3` | The fulfillment item to pack (test-authored) |
| `quantity` | body | number | Required | No | `required\|numeric\|gt:0` | `> 0`, `≤ (picked − already packed)` | None | Over picked → 422 | `2` | Units to add (test-authored exact and over-pack cases) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packages/4/add-item",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "fulfillment_item_id": 3,
    "quantity": 2
  }
}
```

# Responses

## Success: item added — HTTP 200 (DETAIL resource, merged quantity)

Returned via `ApiResponse::apiResponse(__('wms.package.item_added'), 200, true, PackageDetailResource…)` — translated at the call site (`en` → `"Package item added successfully."`, verified in `resources/lang/en/wms.php:140`). Test-authored: exact picked quantity → 200.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.package.item_added` | `en` → `"Package item added successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackageDetailResource` (merged `items`) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Package item added successfully.",
  "success": true,
  "data": {
    "id": 4,
    "status": "open",
    "items": [
      {"id": 9, "fulfillment_item_id": 3, "order_item_id": 5, "quantity": 2}
    ]
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); item/quantity refusals use the EXCEPTION envelope `{message, status: false}` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `packing-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `packing-execute` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse package (code-traced) | See 403-scope example |
| 404 | No package with `{id}` (lookup miss — outside the try, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing item id, non-positive quantity (code-traced) | See 422-validation example |
| 422 | Unknown `fulfillment_item_id` (in-service quirk: NO `exists` rule + lookup inside the try → the `ModelNotFoundException` is caught by `catch (\Exception)` and reframed as 422 — code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Framework no-query-results text, verbatim passthrough (of the form `No query results for model […FulfillmentItem] <id>` — shape code-verified, exact literal NOT asserted) | None | Ghost item id (code-traced) | See 422-unknown-item example |
| 422 | Package not `open` (sealed/voided/handed_off) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.package_status`: `en` → `"Cannot modify package in status: sealed"` (status illustrative; verified in `resources/lang/en/wms.php:124`) | None | Sealed package (test-authored 422) | See 422-state example |
| 422 | Fulfillment cancelled | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.pack_cancelled` WITH fulfillment id: `en` → `"Cannot pack items for cancelled fulfillment #11"` (ids illustrative; verified in `resources/lang/en/wms.php:125`) | None | Cancelled fulfillment (code-traced) | See 422-cancelled example |
| 422 | Item belongs to ANOTHER fulfillment | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.package_foreign_item`: `en` → `"Package item belongs to a different fulfillment"` (verified in `resources/lang/en/wms.php:126`) | None | Foreign item (test-authored 422) | See 422-foreign example |
| 422 | Nothing picked yet on the item | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.pack_unpicked`: `en` → `"Cannot pack unpicked quantity"` (verified in `resources/lang/en/wms.php:128`) | None | Unpicked item (code-traced) | See 422-unpicked example |
| 422 | Quantity exceeds picked-minus-packed | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.overpack`: `en` → `"Over-pack rejected: picked 2, already packed 0, requested 3"` (values illustrative; verified in `resources/lang/en/wms.php:129`) | None | Over-pack (test-authored 422 on `3 > 2`) | See 422-overpack example |
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

403-scope example (catalogue text, code-verified):

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

404-constraint example (ILLUSTRATIVE — NOT live-verified):

```json
{
  "message": "Not Found",
  "status": false
}
```

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "quantity": ["The quantity must be greater than 0."]
  }
}
```

422-unknown-item example (framework text reframed as 422 — shape code-verified, exact literal NOT asserted):

```json
{
  "message": "No query results for model [App\\Models\\Fulfillment\\FulfillmentItem] 999999",
  "status": false
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot modify package in status: sealed",
  "status": false
}
```

422-cancelled example (translated WITH id, no map):

```json
{
  "message": "Cannot pack items for cancelled fulfillment #11",
  "status": false
}
```

422-foreign example (translated, no map):

```json
{
  "message": "Package item belongs to a different fulfillment",
  "status": false
}
```

422-unpicked example (translated, no map):

```json
{
  "message": "Cannot pack unpicked quantity",
  "status": false
}
```

422-overpack example (translated, no map):

```json
{
  "message": "Over-pack rejected: picked 2, already packed 0, requested 3",
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

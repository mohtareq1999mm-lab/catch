# Endpoint Name

Shipments – List Shipments (`GET /api/v1/admin/shipments`)

# Description

Returns the paginated shipment list the caller is allowed to see — one fulfillment, the caller's home warehouse, or ALL warehouses for `view-all-shipments`/`manage-warehouse` holders.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/shipments` (`fulfillment_id` query is OPTIONAL — three resolution branches; code-verified in `ShipmentController@index`) |
| Path composition | `api/v1` prefix + `admin/shipments` from the `Route::prefix('admin/shipments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.shipments.index`) in `packages/marvel/src/Rest/Routes.php:714` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@index(ListShipmentsRequest)` → BRANCH SPLIT on `fulfillment_id`: (a) PRESENT → `Fulfillment::whereKey->firstOrFail` (miss → 404 fixed) → `authorizeWarehouseScope($fulfillment->warehouse_id, 'view-shipment', asNotFound: TRUE)` (cross-warehouse → 404 anti-enumeration) → `Shipment::where('fulfillment_id', ...)`; (b) ABSENT → `canViewAll = view-all-shipments \|\| manage-warehouse` (fail-soft try/catch) → if `canViewAll` AND no `warehouse_id` → GLOBAL all WMS shipments (`whereNotNull('fulfillment_id')` — order-only labels excluded); otherwise target = `warehouse_id` ?? `user.warehouse_id` → `authorizeWarehouseScope(target, 'view-shipment', asNotFound: TRUE)` (foreign probe OR no home → 404 fail-closed) → `Shipment::whereHas('fulfillment', warehouse_id = target)`. All branches: `orderByDesc('id')` + optional `status` filter + `paginate(min(limit,100))` → 200 `ShipmentResource` collection |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-shipment` OR `view-all-shipments` on the route pipe (`permission:view-shipment\|view-all-shipments` — code-verified `Routes.php:714`; `route:list` verified) + the in-controller scope check per branch (`view-shipment` — `WarehouseAccess::allows()` substitutes `view-all-shipments` for it across all warehouses, code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.shipments.index` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422-validation → 404-branch (fulfillment lookup / scope, BOTH read-masked) → 200. The 404 layer is branch-dependent; a bare home-scoped call of an in-scope viewer has NO 404 path.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `fulfillment_id` | query | integer | Optional | Yes | `nullable\|integer\|min:1` (code-verified `ListShipmentsRequest`; formerly `required` — the new flow made it branch-triggering) | Fulfillment id visible to the caller | `null` | Present → branch (a); omitted → branches (b) global/scope | `11` | Scopes the list to ONE fulfillment (WMS surface is fulfillment-linked only when given) |
| `warehouse_id` | query | integer | Optional | Yes | `nullable\|integer\|min:1` (code-verified `ListShipmentsRequest`; added with the new flow) | Warehouse id the caller may see | `null` | Ignored when `fulfillment_id` present; for global holders narrows the global view; otherwise the scoped target (404 if foreign) | `1` | Narrows by warehouse |
| `status` | query | string | Optional | Yes | `nullable\|string\|max:50` | Shipment status filter | None | None | `label_created` | Narrows rows |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | Page size | `15` | Capped at `100` | `15` | Page size |

No body fields. Validation failure returns `422` with an `errors` map.

## Request examples

(1) Fulfillment-scoped (test-verified `test_shipment_reads_scope_to_home_warehouse`, `getJson`):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/shipments?fulfillment_id=11",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

(2) Bare → home-warehouse scoped (test-verified same test: `200` + `data.0.id` = the caller's shipment; foreign caller `200` with EMPTY `data`):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/shipments",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

(3) Bare + `warehouse_id` → narrowed global view for `view-all-shipments` holders (test-verified `test_user_with_view_all_shipments_permission_can_view_all_shipments_without_role` step 3 → `200`):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/shipments?warehouse_id=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: shipment page — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.shipment.fetched_many'), 200, true, ShipmentResource::collection($page))` — translated at the call site (`en` → `"Shipments fetched successfully."`, verified in `resources/lang/en/wms.php` under `wms.shipment.fetched_many`). All branches land on the same 200 shape. Verified by `test_shipment_reads_scope_to_home_warehouse` (branch (a)+(b)-scoped: `200` + non-empty `data` + `data.0.id` + foreign-empty case) and `test_user_with_view_all_shipments_permission_can_view_all_shipments_without_role` (branch (b)-global: `200` + non-empty `data`; narrowed: `200`) — all `getJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.shipment.fetched_many` | `en` → `"Shipments fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present on success | `ShipmentResource` items (the paginator wrapper does NOT add `meta`/`links` — direct `jsonSerialize` renders items only; pinned by `json('data.0.id')` and `assertJsonCount(0,'data')`) | Each item: `id`, `uuid`, `order_id`, `fulfillment_id`, `tracking_number`, `courier`, `status`, `shipping_method`, `total_weight` (float/null), `delivered_at`, `cancelled_at`, `created_at`, `updated_at` (ISO/null) |

```json
{
  "status": 200,
  "message": "Shipments fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 1,
      "uuid": "123e4567-e89b-12d3-a456-426614174000",
      "order_id": 42,
      "fulfillment_id": 11,
      "tracking_number": null,
      "courier": "Aramex",
      "status": "label_created",
      "shipping_method": "standard",
      "total_weight": null,
      "delivered_at": null,
      "cancelled_at": null,
      "created_at": "2026-10-14T09:00:00+00:00",
      "updated_at": "2026-10-14T09:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler, code-verified) | None | No valid token (verified by `test_unauthenticated_shipment_endpoints_return_401` on this URI) | See 401 example |
| 403 | Authenticated caller lacks BOTH `view-shipment` and `view-all-shipments` (route pipe, Spatie) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `"User does not have the required permissions."` | None | Valid token without either read permission (pattern-verified on sibling WMS reads) | See 403 example |
| 404 | No fulfillment with `fulfillment_id` (branch (a) lookup miss — validation passed) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string, `message.ERROR.RESOURCE_NOT_FOUND`) | None | Unknown numeric id (code-traced through `firstOrFail`) | See 404 example |
| 404 | Cross-warehouse fulfillment for a `view-shipment`-ONLY caller (branch (a) scope anti-enumeration — reads NEVER 403 at the scope layer; `view-all-shipments` holders pass via the WarehouseAccess substitute grant and read any warehouse's fulfillment with 200) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign `fulfillment_id` probed by a home-scoped viewer (verified by `test_shipment_reads_scope_to_home_warehouse` → 404) | See 404-scope example |
| 404 | Foreign `warehouse_id` probe (branch (b) scoped target; `asNotFound: TRUE`) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | `view-shipment`-only caller asking for another warehouse (verified by `test_shipment_reads_scope_to_home_warehouse` → 404) | See 404-scope example |
| 404 | `view-shipment`-only caller with NO home warehouse requesting a bare/narrowed list (target resolves `null` — fail-closed boundary rule D9-15) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Code-traced (`authorizeWarehouseScope(null, …, true)` — `allows()` false when warehouse null); NOT directly test-verified | See 404-null example |
| 422 | Invalid query values (`fulfillment_id`/`warehouse_id` non-integer/0, `status` > 50 chars, `limit` 0 or > 100) | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Field → messages | Malformed query (code-traced off `ListShipmentsRequest` rules) | See 422 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded (code-traced) | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example:

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (miss, masked scope, foreign probe, null-home — identical):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "limit": ["The limit field must be at most 100."]
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

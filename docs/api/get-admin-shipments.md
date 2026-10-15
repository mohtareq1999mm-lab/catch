# Endpoint Name

Shipments – List Shipments (`GET /api/v1/admin/shipments`)

# Description

Returns the paginated shipments of one fulfillment inside the caller's warehouse scope.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/shipments?fulfillment_id={id}` (query required; bare `/` with no query is `422`) |
| Path composition | `api/v1` prefix + `admin/shipments` from the `Route::prefix('admin/shipments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.shipments.index`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@index(ListShipmentsRequest)` → `Fulfillment::whereKey(fulfillment_id)->firstOrFail` (miss escapes → 404 fixed); then `authorizeWarehouseScope(warehouse_id, 'view-shipment', asNotFound: TRUE)` (cross-warehouse → 404 anti-enumeration, NEVER 403 — code-verified); then `Shipment::where(fulfillment_id)->orderByDesc('id')` + optional `status` filter → 200 `ShipmentResource` pagination |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-shipment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.shipments.index` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422-validation → 404-fulfillment-lookup → 404-scope → 200. No `422` beyond the query contract below.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `fulfillment_id` | query | integer | Required | No | `required\|integer\|min:1` (`ListShipmentsRequest`) | Fulfillment id visible to the caller | None | None | `11` | Scopes the list (WMS surface is fulfillment-linked only) |
| `status` | query | string | Optional | Yes | `nullable\|string\|max:50` | Shipment status filter | None | None | `label_created` | Narrows rows |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | Page size | `15` | Capped at `100` | `15` | Page size |

No body fields. Validation failure returns `422` with an `errors` map.

## Request examples

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

# Responses

## Success: shipment page — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.shipment.fetched_many'), 200, true, ShipmentResource::collection($page))` — translated at the call site (`en` → `"Shipments fetched successfully."`, verified in `resources/lang/en/wms.php:184`). Verified by `test_shipment_reads_scope_to_home_warehouse` (200 + non-empty `data` via `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.shipment.fetched_many` | `en` → `"Shipments fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array (paginated) | Always present on success | `ShipmentResource` page | Each item: `id`, `uuid`, `order_id`, `fulfillment_id`, `tracking_number`, `courier`, `status`, `shipping_method`, `total_weight` (float/null), `delivered_at`, `cancelled_at`, `created_at`, `updated_at` (ISO/null) |

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
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170 + `resources/lang/en/message.php:575`, code-verified) | None | No valid token (verified by `test_unauthenticated_shipment_endpoints_return_401` on this URI) | See 401 example |
| 403 | Authenticated caller lacks `view-shipment` (route pipe, Spatie) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `"User does not have the required permissions."` | None | Valid token without the permission (pattern-verified on sibling WMS reads) | See 403 example |
| 404 | No fulfillment with `fulfillment_id` (lookup miss — validation passed) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string, `message.ERROR.RESOURCE_NOT_FOUND`) | None | Unknown numeric id (code-traced through `firstOrFail`) | See 404 example |
| 404 | Cross-warehouse fulfillment (scope anti-enumeration — reads NEVER 403 at the scope layer) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign `fulfillment_id` (verified by `test_shipment_reads_scope_to_home_warehouse` → 404) | See 404-scope example |
| 422 | Missing/invalid query (`fulfillment_id` required) | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Field → messages (`fulfillment_id`) | Bare `/api/v1/admin/shipments` with no query (verified → 422 in the same test) | See 422 example |
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

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-scope example (indistinguishable from a miss — by design):

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
    "fulfillment_id": ["The fulfillment id field is required."]
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

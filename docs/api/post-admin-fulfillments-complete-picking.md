# Endpoint Name

Fulfillments – Complete Picking (`POST /api/v1/admin/fulfillments/{id}/complete-picking`)

# Description

Advances one fully-picked fulfillment to picked explicitly with no target input.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/fulfillments/{id}/complete-picking` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/fulfillments/{id}/complete-picking` custom `Route::post(...)->name('api.admin.fulfillments.complete-picking')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-fulfillment` (NOTE: `picking-execute` does NOT suffice here despite the picking subject — code-verified permission switch from the create-tasks twin) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@completePicking(int $id)` — NO request validation and NO target-status input (the service owns the `picking → picked` transition — source-commented); `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(warehouse_id, 'manage-fulfillment')` (verbatim-403 path); then `OrderPickingService::completePicking` in a row-locked transaction: ANY item with `quantity_picked < quantity` → `RuntimeException("Fulfillment #.. has incomplete picking work: completion refused")` → 422 live FULL text (code-verified verbatim shape); else the canonical owner advances (repeat-safe via same-state no-op — source-commented; orders/inventory/payment/shipment untouched — source-commented) → 200 bounded detail |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-fulfillment` via the route pipe (a `picking-execute`-only caller fails it — code-verified switch; the create-tasks test actor carries picking rights that do NOT transfer) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read (`actor_id` derived server-side for the context — code-verified); `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.complete-picking` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (recorded as `actor_id` in context) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be FULLY picked (else 422) | Fully-picked fulfillment id manageable by the caller | None | None | `1` | Identifies the fulfillment |

No query parameters, body fields, or validation-driven `422` (the 422 below is the service outcome, not validation).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/1/complete-picking",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: picking completed — HTTP 200 (bounded detail, `picked` state)

Returned via `ApiResponse::apiResponse('Fulfillment picking completed successfully.', 200, true, FulfillmentDetailResource::make($detail))` (literal message). Verified by `test_complete_picking_advances_only_when_fully_picked` (post-confirm 200 + `picked` proof, `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Fulfillment picking completed successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Bounded `FulfillmentDetailResource` (`status: picked`) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Fulfillment picking completed successfully.",
  "success": true,
  "data": {
    "id": 1,
    "fulfillment_number": "FF-000001",
    "order_id": 42,
    "warehouse_id": 1,
    "status": "picked",
    "priority": null,
    "assigned_to": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so the 422 carries live text with NO `errors` map.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-fulfillment` (route pipe — `picking-execute` alone fails) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced switch) | See 403 example |
| 403 | Out-of-scope `manage-fulfillment` holder at the SCOPE layer (verbatim) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse completion (verified → 403 on the foreign twin) | See 403-scope example |
| 404 | No fulfillment with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | INCOMPLETE picking work (any item below quantity — thrown `RuntimeException`, NOT validation) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | `"Fulfillment #1 has incomplete picking work: completion refused"` (FULL live text with id — code-verified verbatim shape, ids illustrative) | None | Partially-picked fulfillment (verified → 422 + `picking` state proof) | See 422 example |
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

422 example (FULL live text, no map):

```json
{
  "status": 422,
  "message": "Fulfillment #1 has incomplete picking work: completion refused",
  "success": false
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

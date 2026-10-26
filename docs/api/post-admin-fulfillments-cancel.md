# Endpoint Name

Fulfillments – Cancel Fulfillment (`POST /api/v1/admin/fulfillments/{id}/cancel`)

# Description

Cancels one fulfillment with actor audit and replay-safe repeats, refusing guarded states.
يقوم بإلغاء أمر تجهيز (Fulfillment) محدد في نظام WMS مع توثيق المستخدم ومصدر الإلغاء، ويدعم إعادة الطلب بأمان (Idempotent/Replay-safe)، ويرفض الإلغاء في الحالات المحمية (مثل وجود شحنة نشطة أو طرود مختومة أو مهام تغليف مكتملة).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/fulfillments/{id}/cancel` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/fulfillments/{id}/cancel` custom `Route::post(...)->name('api.admin.fulfillments.cancel')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:fulfillment.cancel` (`\Marvel\Enums\Permission::FULFILLMENT_CANCEL`) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\FulfillmentController@cancel(CancelFulfillmentRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation runs FIRST, so 422 precedes 404); `authorizeWarehouseScope(warehouse_id, 'fulfillment.cancel')` (cross-warehouse 403 with state provably held); then `cancelFulfillment` with SERVER-DERIVED `cancelled_by: actorId()` + `cancel_source: 'admin_api'` (caller-sent `cancelled_by`/`actor_id`/`status` fields IGNORED — no rules accept them; forgery-proof). Outcomes: 200 `cancelled` (actor + source audited on the row); REPEAT on cancelled → 200 again (replay-safe); `InvalidArgumentException`/`RuntimeException` (live shipment, sealed package, packed task, delivered DAG refusal) → 422 live message. Release+cancel provably leave order/inventory/payment/shipments untouched |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `fulfillment.cancel` via the route pipe |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.cancel` (explicit named route) |

Execution order: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200/422-engine.

## Request inputs

`CancelFulfillmentRequest` runs before the body (single `reason` rule; blank-only strings fail via empty-string conversion).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (recorded as `cancelled_by`) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Fulfillment id manageable by the caller | None | None | `1` | Identifies the fulfillment |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` | Non-blank string ≤ 2000 chars | None | None | `"Customer request"` | Audit reason for cancellation |

## Request examples

Valid request cancelling a pending fulfillment:

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/1/cancel",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "reason": "customer request"
  }
}
```

# Responses

## Success: fulfillment cancelled — HTTP 200 (audited, idempotent on repeats)

Returned via `ApiResponse::apiResponse(__('wms.fulfillment.cancelled'), 200, true, FulfillmentDetailResource::make($detail))` (translated key; bounded detail). Verified by `test_cancel_pending_fulfillment_audits_actor_and_source` (200 + `status: cancelled` + `cancelled_by: operator` + `cancel_source: admin_api`, `postJson`) and `test_cancel_replay_on_cancelled_returns_200` (second 200, still `cancelled`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including repeats) |
| `message` | string | Always present | Translated `wms.fulfillment.cancelled` | `en` → `"Fulfillment cancelled successfully."` / `ar` → `"تم إلغاء عملية التنفيذ بنجاح."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Bounded `FulfillmentDetailResource` (`status: cancelled`, audit fields set) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Fulfillment cancelled successfully.",
  "success": true,
  "data": {
    "id": 1,
    "fulfillment_number": "FF-000001",
    "order_id": 42,
    "warehouse_id": 1,
    "status": "cancelled",
    "priority": null,
    "assigned_to": null,
    "cancelled_by": 7,
    "cancel_source": "admin_api",
    "notes": null,
    "cancelled_at": "2026-10-25T13:00:00+03:00",
    "created_at": "2026-10-25T10:00:00+03:00",
    "updated_at": "2026-10-25T13:00:00+03:00",
    "order": {},
    "warehouse": {},
    "items": [],
    "packing_tasks": [],
    "shipments": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Unauthenticated request | See 401 example |
| 403 | Authenticated caller lacks `fulfillment.cancel` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Caller without required permission | See 403 example |
| 403 | Out-of-scope `fulfillment.cancel` holder at the SCOPE layer (verbatim, state provably held) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` | None | Cross-warehouse cancel request | See 403-scope example |
| 404 | No fulfillment with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong HTTP method | See 405 example |
| 422 | `CancelFulfillmentRequest` fails (missing/blank/overlong `reason`) | `{message: string, status: boolean, errors: object}` | None | Laravel validation default | Field → messages | Blank or missing `reason` | See 422-validation example |
| 422 | Guarded-state refusal (live shipment, sealed package, packed task — live engine text, states provably held) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | Live engine text (exact strings engine-dependent) | None | Uncancellable state (active shipment, sealed package, packed tasks) | See 422-engine example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

### 401 Example (Unauthenticated)

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

### 403 Example (Missing Permission)

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

### 403-Scope Example (Cross-Warehouse)

```json
{
  "message": "Forbidden warehouse operation.",
  "status": false
}
```

### 404 Example (Resource Not Found)

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

### 404-Constraint Example (Non-numeric ID)

```json
{
  "message": "Not Found",
  "status": false
}
```

### 405 Example (Method Not Allowed)

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

### 422-Validation Example (Validation Failure)

```json
{
  "message": "The reason field is required.",
  "status": false,
  "errors": {
    "reason": [
      "The reason field is required."
    ]
  }
}
```

### 422-Engine Example (Guarded State Refusal)

```json
{
  "message": "Cannot cancel fulfillment #1 with live shipment.",
  "status": false
}
```

### 429 Example (Too Many Requests)

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

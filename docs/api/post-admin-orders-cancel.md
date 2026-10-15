# Endpoint Name

Admin Orders – Cancel During Fulfillment (`POST /api/v1/admin/orders/{orderId}/cancel`)

# Description

Cancels one order through the Order Flow authority with fail-closed warehouse scoping.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/orders/{orderId}/cancel` |
| Path composition | `api/v1` prefix + `admin/orders/{orderId}/cancel` from the `Route::prefix('admin/orders')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group in `packages/marvel/src/Rest/Routes.php` with route-level `permission:order.cancel-during-fulfillment` (DOTTED permission name — `Permission::ORDER_CANCEL_DURING_FULFILLMENT`, code-verified; Spatie accepts dots verbatim). Source comment designates `OrderService::changeOrderStatus` the SOLE writer (Order Flow stays lifecycle authority) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\OrderCancellationController@cancel(BatchCommandRequest $request, int $orderId)` (extends `WmsAdminController` — NO constructor middleware; group supplies auth; actor is server-derived `auth()->id()`, never caller-supplied): (1) `Order::whereKey->firstOrFail` (miss escapes → 404 via `Handler` `ModelNotFound` → `__('message.ERROR.RESOURCE_NOT_FOUND')` — code-verified, and the strict test pins it); (2) `BatchCommandRequest` validation runs BEFORE the method body (missing `reason` → 422 with order provably untouched — test-verified); (3) `authorizeOrderScope`: `manage-warehouse` holders pass GLOBALLY, else the actor's home warehouse must EXACTLY equal the order's non-cancelled fulfillment warehouse set — null home, EMPTY set (no fulfillment context at all), or cross-warehouse all throw `AuthorizationException('Forbidden warehouse operation.')` → mapped by `Handler` to 403 `"This action is unauthorized."` (throw text swallowed; tests assert status only — all three variants test-verified, state provably untouched); (4) `OrderService::changeOrderStatus(null, 'cancelled', id, true, reason)` — `RuntimeException` (flow-gate/guard refusals: sealed custody, packed work, live shipment) → 422 live message with the order atomically untouched; `false` → 422 via `__('wms.order.cancel_current_state')` (`wms.php:281`); success cascades to OPEN fulfillments in the same transaction (shipped/terminal fulfillments NEVER force-cancelled — a shipped row keeps `shipped` while the order flips to `cancelled`, test-verified; live-shipment orphans surface via log for manual follow-up, code-verified); (5) fresh order + ordered `fulfillments[{id, status}]` map → 200 `ApiResponse` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; the test uses `actingAs($actor, 'sanctum')` and the strict 401 pins guests) |
| Permission | Required: `order.cancel-during-fulfillment` via route middleware (same-warehouse WITHOUT it → 403 with order provably untouched — test-verified) PLUS the warehouse-scope layer above (the permission alone is NOT sufficient for scoped actors) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.orders.cancel` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup; validation precedes it, so 422 wins ties) → 403-scope → 422-engine → 200. No `lang` in group middleware (same as its shipment siblings).

## Request inputs

`BatchCommandRequest` runs before the body: `reason` is REQUIRED (`required|string|min:1|max:2000` — code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (actor identity is server-derived from it) |
| `orderId` | path | integer | Required | No | `whereNumber('orderId')` + `int $orderId` | Existing order id | None | None | `42` | Identifies the order |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` | Non-empty string ≤ 2000 chars | None | None | `"customer changed mind"` | Audit reason forwarded to `changeOrderStatus` |

## Request examples

Valid request cancelling during picking (test-verified call shape):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/orders/42/cancel",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "reason": "customer changed mind"
  }
}
```

# Responses

## Success: order cancelled — HTTP 200 (`ApiResponse` shape)

Returned via `apiResponse(__('wms.order.cancelled'), 200, true, ['order_id' => ..., 'status' => ..., 'fulfillments' => ...])` (`en` → `"Order cancelled successfully."`, verified in `resources/lang/en/wms.php:282` — wire-identical to the former literal claim; NOTE this action uses the `ApiResponse` envelope WITH the `status` key, unlike its shipment siblings' plain shapes). Verified by `test_cancel_during_picking_cascades_through_fulfillment_authority` (`assertStatus(200)`, exact message assertion, `order_id`/`status: cancelled`/`fulfillments: [{id, status: cancelled}]` assertions, cascade assertions `cancel_source: order_cancel` + `cancelled_at` set, `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.order.cancelled` | `en` → `"Order cancelled successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Result payload (keys verified) | `order_id`, `status` (`cancelled`), `fulfillments` (ordered-by-id `[{id, status}]` — INCLUDES shipped survivors, so a cancelled order can list a `shipped` fulfillment; test-verified) |

```json
{
  "status": 200,
  "message": "Order cancelled successfully.",
  "success": true,
  "data": {
    "order_id": 42,
    "status": "cancelled",
    "fulfillments": [
      { "id": 7, "status": "cancelled" }
    ]
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Failure responses here are `HttpException`-thrown (via `unprocessable`) or `Handler`-rendered — all carry NO `success` key (only the 200 uses the `ApiResponse` envelope; code-verified asymmetry). `HttpException` failures DO carry Handler's boolean `status: false` alongside `message`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (strict `assertStatus(401)` in `test_cancel_unauthenticated_returns_401`, `postJson`) | See 401 example |
| 403 | Authenticated caller lacks `order.cancel-during-fulfillment` (route layer) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Same-warehouse actor with only `view-fulfillment` (verified by `test_cancel_requires_granular_permission` → 403, order + fulfillment provably `pending`) | See 403 example |
| 403 | Scoped actor fails the warehouse-scope layer (THREE variants, IDENTICAL shape — code-verified): null home warehouse; EMPTY non-cancelled fulfillment set (no WMS nexus at all); cross-warehouse set ≠ `[home]` | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION` — `Handler` swallows the `Forbidden warehouse operation.` throw text; tests assert status only — code-verified mapping) | None | `test_cancel_cross_warehouse_denied_with_state_untouched` → 403 (states provably `pending`); `test_cancel_without_fulfillment_context_denied_for_scoped_actor` → 403 (states provably `pending`) | See 403-scope example |
| 404 | No order with `{orderId}` (`firstOrFail` escapes before scope/validation-of-state) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` via `__('message.ERROR.RESOURCE_NOT_FOUND')` (`message.php:579`, NO period) | None | Unknown numeric id (strict `assertStatus(404)` in `test_cancel_missing_order_returns_404` for `999999`) | See 404 example |
| 404 | Non-numeric `{orderId}` matches NO route (`whereNumber`) | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | `BatchCommandRequest` fails (missing/empty/overlong `reason`) | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA` — field texts live in `errors`) | Field → messages | Empty body (verified by `test_cancel_requires_reason` → 422, order provably `pending`) | See 422-validation example |
| 422 | Flow-gate/guard refusal (`RuntimeException` from `changeOrderStatus` or the cascade — sealed custody, packed work, live shipment; order atomically untouched) | `{message: string, status: boolean}` (NO `success` key — thrown `HttpException` carries Handler `status: false`, code-verified) | None | LIVE engine text (`$e->getMessage()` verbatim — exact strings are engine-dependent, **Unverified in source** at text level) | None | Refused transition (code-traced; E2E tests exercise the happy paths through picking/packing, refusals stay engine-side) | See 422-engine example |
| 422 | Service returned `false` (non-exception refusal) | `{message: string, status: boolean}` (NO `success` key) | None | `"Order cannot be cancelled in its current state."` (via `__('wms.order.cancel_current_state')`, `wms.php:281` — wire-identical) | None | False-return refusal (code-traced) | See 422-state example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (missing granular permission):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403-scope example (all three scope variants — same shape; throw text swallowed by Handler):

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
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "reason": ["The reason field is required."]
  }
}
```

422-engine example (live engine text, illustrative):

```json
{
  "message": "Live engine refusal text (exact string engine-dependent)",
  "status": false
}
```

422-state example (translated `wms.order.cancel_current_state`, wire-identical):

```json
{
  "message": "Order cannot be cancelled in its current state.",
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

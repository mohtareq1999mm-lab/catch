# Endpoint Name

Admin Tracking – List Orders (`GET /api/v1/admin/tracking/orders`)

# Description

Returns paginated orders with advanced filtering for operations monitoring. No validation — unknown filter values filter literally (possibly to zero rows), unknown `sort_by` falls back to `created_at`. `payment_status` accepts single, comma-separated, or array values with paid/pending/failed/refunded aliases (working-tree addition); relative date filters (`older_than*` / `newer_than` / `within_days`) scope `created_at` (working-tree addition).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/tracking/orders` (+ optional query string) |
| Path composition | `api` prefix (from `RouteServiceProvider` mapping of `routes/api.php`) + `v1/admin/tracking` prefix + `orders` from `routes/api.php:232` inside the `['api', 'auth:sanctum', 'throttle:admin']` group (note: NO `lang` middleware here — messages are fixed English) |
| Controller action | `App\Http\Controllers\Api\Admin\AdminOrderTrackingController@listOrders` (application layer — not Marvel; uses the Marvel `ApiResponse` trait envelope `{status, message, success, data}`) → in-method `authorizeAdmin` (see Permission), then `Order::with(user, latest statusHistory)` + `filled()` filters (`status`, expanded `payment_status` via `whereIn`, `payment_method`/`payment_type`, `payment_gateway`, `fulfillment_status`, `search` LIKE, absolute + relative date filters) + whitelisted sort + `paginate(per_page)` → 200 |
| Authentication | Required twice: `auth:sanctum` group middleware, plus in-method `authorizeAdmin` guard that aborts 401 when no user is present (unreachable after the middleware — defense-in-depth) |
| Permission | Required: `view-orders` via in-method `authorizeAdmin` (NOT route middleware): passes via Spatie `hasPermissionTo('view-orders')` or gate `can('view-orders')`; legacy `type/role === 'admin'` WITHOUT the permission is still DENIED when the permissions system is available (`abort(403)`), allowed ONLY when the permission check itself cannot run (code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `api.admin.tracking.orders` (explicit named route; `route:list` verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `status` | query | string | Optional | No | No validation; applied via `where('status', value)` only when `filled()` | Any order status | None | None | `pending` | Exact status filter |
| `payment_status` | query | string/array | Optional | No | No validation; single, comma-separated, or array input split on `,` + trimmed, then alias-expanded and applied via `whereIn('payment_status', unique)` when `filled()` (working-tree addition — code-verified). Aliases: `pending` → +`pending`/`PAYMENT_STATUS_PENDING`; `paid`/`success` → +`paid`/`success`/`PAYMENT_STATUS_SUCCESS`; `failed` → +`failed`/`PAYMENT_STATUS_FAILED`; `refunded` → +`refunded`/`PAYMENT_STATUS_REFUNDED`; anything else passes through literally | Any payment status(es) | None | None | `paid` | Payment-status filter (multi-value) |
| `payment_method` / `payment_type` | query | string/array | Optional | No | No validation; `payment_method ?? payment_type`, split on `,` when string, applied via `whereIn('payment_method', …)` when non-empty (working-tree addition — code-verified) | Any method | None | `payment_type` is the fallback alias | `online` | Payment-method filter |
| `payment_gateway` | query | string | Optional | No | No validation; `where('payment_gateway', value)` when `filled()` (working-tree addition — code-verified) | Any gateway | None | None | `myfatoorah` | Gateway filter |
| `fulfillment_status` | query | string | Optional | No | No validation; `where('fulfillment_status', value)` when filled | Any fulfillment status | None | None | `processing` | Exact fulfillment-status filter |
| `search` | query | string | Optional | No | No validation; `LIKE %value%` over `order_number` OR `user_email` OR `user_phone` OR `name` when filled | Free text | None | None | `ORD-00000042` | Customer/order search |
| `date_from` | query | string | Optional | No | No validation; `where('created_at', '>=', value)` when filled | Datetime string | None | None | `2026-01-01` | Created-at lower bound |
| `date_to` | query | string | Optional | No | No validation; `where('created_at', '<=', value)` when filled | Datetime string | None | None | `2026-12-31` | Created-at upper bound |
| `older_than` | query | string/integer | Optional | No | No validation; parsed by `parseRelativeDuration` → `where('created_at', '<=', cutoff)`; unparsable → NO filter (working-tree addition — code-verified). Formats: plain int (days), `2d`/`3_days`/`2 days`/`1w`/`1_week`/`1m`/`1_month`/`1y` (units `h/d/w/m/y` + long forms) | Duration | None | Wins over `older_than_days/hours/months` (`elseif` chain) | `30d` | Relative older-than filter |
| `older_than_days` / `older_than_hours` / `older_than_months` | query | integer | Optional | No | No validation; `created_at <= now()-X days/hours/months` (first filled wins, only when `older_than` absent) | Integers | None | None | `30` | Relative older-than fallback |
| `newer_than` | query | string/integer | Optional | No | No validation; same duration parser → `where('created_at', '>=', cutoff)`; unparsable → NO filter (working-tree addition) | Duration | None | Wins over `within_days` (`elseif`) | `7d` | Relative newer-than filter |
| `within_days` | query | integer | Optional | No | No validation; `created_at >= now()-X days` (only when `newer_than` absent) | Integer | None | None | `7` | Within-last-days fallback |
| `sort_by` | query | string | Optional | No | Whitelist `['created_at','updated_at','total_price','status','id']`; anything else silently falls back to `created_at` (code-verified) | Whitelisted column | `created_at` | None | `total_price` | Sort column |
| `sort_order` | query | string | Optional | No | `'asc'` (case-insensitive) → asc, anything else → desc (code-verified) | `asc`/`desc` | `desc` | None | `asc` | Sort direction |
| `per_page` | query | integer | Optional | No | `min(max((int)value, 1), 100)` — clamped 1–100 (code-verified) | 1–100 | `50` | None | `50` | Page size |

There are no body fields, path parameters, or validation — so there is NO `422` contract and NO `404` contract (unknown filters yield an empty page, never a miss). Caveat (code-traced, no test): array-typed query values (e.g. `?period[]=x`) bypass the string assumption and can bubble to the generic `500` branch instead of filtering.

## Request examples

Filtered list (test-compatible call):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/tracking/orders?status=pending",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: order page — HTTP 200

Returned via `ApiResponse::apiResponse(__('message.MESSAGE.ORDERS_RETRIEVED_SUCCESSFULLY'), 200, true, $paginator)` (`en` → `"Orders retrieved successfully."`, verified in `resources/lang/en/message.php:589`). Verified by `admin_can_list_orders_with_filters` (`?status=pending` → 200, `success: true`, rows at `data.data` all `pending`; uses `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ORDERS_RETRIEVED_SUCCESSFULLY` | `en` → `"Orders retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object (Laravel paginator) | Always present on success | Paginated orders, `sort_by`/`sort_order` applied | `data` (rows: full order models with `user` + latest `statusHistory`), `links`, `meta` (`current_page`, `per_page`, `total`, …) |
| `data.data[]` | array | Possibly empty | Order rows | Each row carries the order columns plus `user` and one latest `statusHistory` record |

```json
{
  "status": 200,
  "message": "Orders retrieved successfully.",
  "success": true,
  "data": {
    "data": [
      { "id": 1, "order_number": "ORD-00000001", "status": "pending" }
    ],
    "links": {},
    "meta": { "current_page": 1, "per_page": 50, "total": 1 }
  }
}
```

(Rows abbreviated — full order attributes + `user` + latest `statusHistory`; figures illustrative, envelope/keys verified.)

# Errors

No error-code field; every error below has Error code `None`. No validation and no lookup, so NO `422` and NO `404`. The 200/401/403 contracts are test-verified; 405/429/500 are code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token (`auth:sanctum` group rejects before the method; in-method `abort(401, 'Unauthenticated.')` unreachable) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated."` (WITH period — `AuthenticationException` branch; in-method abort string identical) | None | No valid token (verified by `test_admin_tracking_requires_authentication` on this URI) | See 401 example |
| 403 | Caller fails `authorizeAdmin` (no `view-orders` + no usable legacy fallback) via `abort(403, 'Forbidden. Missing required permission: view-orders.')` → `Handler::translateHttpMessage` catalogue `message.ERROR.FORBIDDEN_PERMISSION` (`en` identical to the abort string; NOT the Spatie message) | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: view-orders."` | None | Customer without permission (verified by `test_customer_without_permission_denied_on_tracking` on this URI); `view-analytics`-only admin (verified 403) | See 403 example |
| 405 | Any method other than `GET` (only `Route::get` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | E.g. `POST` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `"Too many requests. Please try again later."` | None | Rate limit exceeded (code-traced) | See 429 example |
| 500 | Unexpected failure (no try/catch in method) | `{message: string, status: boolean}` | None | Exception message when `app.debug`, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

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
  "message": "Forbidden. Missing required permission: view-orders.",
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

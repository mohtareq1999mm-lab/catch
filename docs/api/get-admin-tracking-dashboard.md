# Endpoint Name

Admin Tracking – Dashboard (`GET /api/v1/admin/tracking/dashboard`)

# Description

Returns period-scoped order counts, revenue, pending actions, and recent orders for operations monitoring.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/tracking/dashboard` |
| Path composition | `api` prefix (from `RouteServiceProvider` mapping of `routes/api.php`) + `v1/admin/tracking` prefix + `dashboard` from `routes/api.php:220` inside the `['api', 'auth:sanctum', 'throttle:admin']` group (note: NO `lang` middleware here, unlike the Marvel groups — the `lang` header has no effect and all messages are literal English) |
| Controller action | `App\Http\Controllers\Api\Admin\AdminOrderTrackingController@dashboard` (application layer — not Marvel; uses the Marvel `ApiResponse` trait envelope `{status, message, success, data?}`) |
| Authentication | Required twice: `auth:sanctum` constructor middleware, plus an in-method `authorizeAdmin` guard that aborts 401 when no user is present (unreachable after the middleware — defense-in-depth) |
| Permission | Required: `view-orders` via in-method `authorizeAdmin` (NOT route middleware): passes when the user `hasPermissionTo('view-orders')` (Spatie) or `can('view-orders')` (gate); otherwise falls back to the legacy admin-type rule — a `type === 'admin'` (or `role === 'admin'`) user WITHOUT the permission is still DENIED when the permissions system is available (`abort(403)`), and allowed ONLY when the permission check itself cannot run (e.g. missing table, test seeding without permission cache). The method itself performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `api.admin.tracking.dashboard` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `period` | query | string | Optional | No | No validation; matched against `today`/`week`/`month`/`year`, anything else (including absent) falls into the `default` arm = today | `today`, `week`, `month`, `year` (any other value silently behaves as `today` — code-verified) | `today` | None | `week` | Calendar window scoping the range-based sections |

There are no body fields, path parameters, multipart fields, or file uploads. There is no validation, so there is no `422` contract. Windows are calendar boundaries (`startOfDay/Week/Month/Year` → `endOf...`), not rolling days.

## Request examples

Valid request fetching this week's dashboard:

```json
{
  "method": "GET",
  "url": "/api/v1/admin/tracking/dashboard?period=week",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: tracking dashboard — HTTP 200

Returned via `ApiResponse::apiResponse('Dashboard retrieved successfully.', 200, true, [...])` — message is a literal English string (no translation lookup applies). Verified by `admin_can_view_dashboard` (`OrderTrackingTest`: permission-less `type = 'admin'` user — proving the legacy fallback path — `?period=today` → 200, `success: true`, structure `data: [overview, status_breakdown, recent_orders, pending_actions, revenue]`; uses `getJson`, matching this route — no test fix needed). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Dashboard retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Five computed sections (all range-scoped to `period` except where noted) | See `data` fields below |

`data` fields (code-verified):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.overview` | object | Always present | `{total_orders (created in range, any status), completed_orders (status `completed` in range), pending_orders (status `pending` — NO date filter, all-time; code-verified asymmetry), cancelled_orders (status `cancelled` in range), total_revenue (raw `total_price` sums over `payment_status = 'payment-success'` in range — mixes currencies per the code's own contract comment), revenue_by_currency (same population grouped by `currency_code`, `NULL` → `'UNKNOWN'` — authoritative split)}` | Counts ints, sums rounded 2dp floats |
| `data.status_breakdown` | object (map status → int) | Always present (possibly empty) | Order counts by status within range | Only statuses PRESENT in range appear — absent statuses are missing keys, not zeros (code-verified difference from `dashboard/order-stats`) |
| `data.recent_orders` | array (max 10) | Always present (possibly empty) | 10 latest orders overall (NOT range-scoped — code-verified), `created_at` descending, each mapped to `{id, order_number, customer (owner name, falling back to the order's `name`), status, total (raw `total_price`, 2dp), created_at (HUMAN string via `diffForHumans()`, e.g. `"2 hours ago"` — not a timestamp; code-verified)}` | Fixed limit 10, no parameter |
| `data.pending_actions` | object | Always present | `{needs_processing (pending + paid), needs_shipment (completed + fulfillment `processing`), payment_verification (payment `payment-pending` + created within last 24h)}` — all-time counts, not range-scoped (code-verified) | Ints |
| `data.revenue` | object | Always present | Paid (`payment-success`) orders in range: `{total (raw sums — mixes currencies per the code comment; `by_currency` authoritative), average (`0` when none — code-verified guard), count, by_currency (map, `NULL` → `'UNKNOWN'`), currencies (key list), mixed_currencies (more than one bucket)}` | `total`/`average` rounded 2dp |

Complete success example (figures illustrative, keys/message/structure verified):

```json
{
  "status": 200,
  "message": "Dashboard retrieved successfully.",
  "success": true,
  "data": {
    "overview": {
      "total_orders": 14,
      "completed_orders": 9,
      "pending_orders": 3,
      "cancelled_orders": 2,
      "total_revenue": 1840.5,
      "revenue_by_currency": { "USD": 1840.5 }
    },
    "status_breakdown": {
      "pending": 3,
      "completed": 9,
      "cancelled": 2
    },
    "recent_orders": [
      {
        "id": 42,
        "order_number": "ORD-00000042",
        "customer": "Jane Doe",
        "status": "completed",
        "total": 200.0,
        "created_at": "2 hours ago"
      }
    ],
    "pending_actions": {
      "needs_processing": 1,
      "needs_shipment": 2,
      "payment_verification": 0
    },
    "revenue": {
      "total": 1840.5,
      "average": 204.5,
      "count": 9,
      "by_currency": { "USD": 1840.5 },
      "currencies": ["USD"],
      "mixed_currencies": false
    }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200 contract is test-verified (`admin_can_view_dashboard`); all other rows are code-verified or code-traced as marked. There is no validation, so there is no `422` contract. Note: this controller has NO try/catch — unexpected failures bubble to `Handler`'s generic 500 branch (unlike the dashboard `DashboardController`, there is no 409-collapse here).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` constructor middleware rejects before the method runs; the in-method `abort(401, 'Unauthenticated.')` is unreachable behind it) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no endpoint-specific 401 test exists — the tracking suite's 401 test targets the different `general/orders/{id}/track` route; code-traced through the shared path) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Caller fails `authorizeAdmin`: no `view-orders` permission (via Spatie or gate) AND no usable legacy admin-type fallback — rendered from `abort(403, 'Forbidden. Missing required permission: view-orders.')` via `Handler`'s `HttpException` branch, which uses the abort message verbatim (code-verified — NOT the translated Spatie message, NOT a validation shape) | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: view-orders."` (literal abort string, English-only — no `lang` middleware on this group) | None | Authenticated non-admin without `view-orders` (code-traced — no endpoint-specific 403 test exists) | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/admin/tracking/dashboard` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception escaping the method), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query/serialization failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-orders` and no legacy fallback):

```json
{
  "message": "Forbidden. Missing required permission: view-orders.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/admin/tracking/dashboard`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

429 example (rate limit exceeded):

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

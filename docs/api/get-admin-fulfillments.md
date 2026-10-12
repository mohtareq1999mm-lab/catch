# Endpoint Name

Fulfillments – List Fulfillments (`GET /api/v1/admin/fulfillments`)

# Description

Returns a validated, scope-filtered fulfillment page with item and shipment counts.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/fulfillments` |
| Path composition | `api/v1` prefix + `admin/fulfillments/` from the `Route::prefix('admin/fulfillments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.fulfillments.index`) in `packages/marvel/src/Rest/Routes.php`. No `lang` in group middleware (same as its WMS siblings) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\FulfillmentController@index(ListFulfillmentsRequest $request)` (NO constructor middleware — group supplies auth; NO in-controller permission check, only scope branches): `id DESC` ordering; `manage-warehouse` holders see ALL rows (optional `warehouse_id` filter); everyone else: NULL home → verbatim 403 (fail-closed); foreign filter → scope-checked with `asNotFound: TRUE` → 404 (NOT an empty page — same anti-enumeration as locations); no filter → home-only. `order_id`/`status`/`assigned_to` equality filters when non-null; `limit` validated `1`–`100`; `items` + `shipments` counted per row (read-only N+1 guard); plain paginator collection (exact pagination keys **Unverified in source** at key level) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `view-fulfillment` via the route pipe (a permissionless homed user fails the pipe — test-verified 403; the SCOPE branch is permission-independent beyond it) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests) |
| Route name | `api.admin.fulfillments.index` (explicit named route) |

## Request inputs

`ListFulfillmentsRequest` runs before the body (`authorize: true` — the gates above already ran).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `warehouse_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` (unknown ids 422 here — unlike the unvalidated location filter; code-verified strictness) | Existing warehouse id | None (global: no filter; scoped: home-only) | None | `1` | Warehouse filter (scoped callers: home-only or 404) |
| `order_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:orders,id` | Existing order id | None (no filter) | None | `42` | Order filter |
| `status` | query | string | Optional | Yes | `nullable\|in:pending,picking,picked,packing,ready_to_ship,shipped,delivered,cancelled` (EIGHT states — code-verified closed set) | The eight states | None (no filter) | None | `pending` | Status filter |
| `assigned_to` | query | integer | Optional | Yes | `nullable\|integer\|exists:users,id` | Existing user id | None (no filter) | None | `7` | Assignee filter |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | `1`–`100` | `15` | None | `15` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/fulfillments?limit=15",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: scoped page returned — HTTP 200

Returned via `ApiResponse::apiResponse('Fulfillments fetched successfully.', 200, true, $page)` (literal message; paginator collection straight through). Verified by `test_list_scopes_to_home_warehouse` (200 + `success: true`, foreign `WH-FB` content provably ABSENT, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Fulfillments fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array/object | Always present (possibly empty) | Paginated `FulfillmentResource` rows | Row: `id`, `fulfillment_number`, `order_id`, `warehouse_id`, `status`, `priority`, `assigned_to`, `items_count` (int), `shipments_count` (int), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Fulfillments fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 1,
      "fulfillment_number": "FF-000001",
      "order_id": 42,
      "warehouse_id": 1,
      "status": "pending",
      "priority": null,
      "assigned_to": null,
      "items_count": 1,
      "shipments_count": 0,
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (verified by `test_unauthenticated_requests_return_401` on index + show + release + cancel + assign) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Homed user with no permissions (verified by `test_user_without_permission_is_forbidden_everywhere` → 403 on index) | See 403 example |
| 403 | NULL-home holder (fail-closed listing) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Null-home `view-fulfillment` holder (verified by `test_index_with_null_home_fails_closed` → 403) | See 403-scope example |
| 404 | Scoped caller filters a FOREIGN warehouse (anti-enumeration on a list filter) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign `warehouse_id` filter without global scope (code-traced; same `asNotFound` path as show) | See 404 example |
| 422 | `ListFulfillmentsRequest` fails (unknown ids, off-list `status`, `limit` outside 1–100) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid query (code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

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

403-scope example (null home — verbatim WITH period):

```json
{
  "message": "Forbidden warehouse operation.",
  "status": false
}
```

404 example (foreign filter — NOT an empty page):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The selected status is invalid.",
  "status": false,
  "errors": {
    "status": ["The selected status is invalid."]
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

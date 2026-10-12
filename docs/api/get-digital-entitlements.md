# Endpoint Name

Digital Entitlements – List Entitlements (`GET /api/v1/digital-entitlements`)

# Description

Returns a paginated list of digital entitlements with filtering and ownership-independent admin visibility.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/digital-entitlements` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `digital-entitlements` custom `Route::get(...)->name('admin.digital-entitlements.index')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:manage-digital-access` |
| Controller action | `Marvel\Http\Controllers\DigitalEntitlementController@index(Request $request)` → `DigitalEntitlementService::paginate($request->only(['uuid', 'status', 'order_id', 'user_id', 'search']), (int) $request->query('per_page', 25))` with `orderItem.product` + `user` eager-loaded, `orderByDesc('id')`; returns `apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ['data' => Resource::collection(items), 'meta' => ...])` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice with DIFFERENT permissions (code-verified): route middleware `manage-digital-access` AND constructor `view-orders` on `only: [index, show]` — the caller needs both |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-entitlements.index` (explicit named route) |

Middleware runs before the action: 401/403 precede 200.

## Request inputs

No validation runs — NOTHING here 422s (unknown filter values yield empty pages; `per_page` is unvalidated).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | query | string | Optional | No | Exact-match filter (`when($filters['uuid'])` — falsy skips) | Any uuid | None (no filter) | None | `123e4567-e89b-12d3-a456-426614174000` | Filters to one entitlement |
| `status` | query | string | Optional | No | Exact-match filter (falsy skips) | `pending`, `delivered`, `revoked` | None (no filter) | None | `delivered` | Status filter |
| `order_id` | query | integer-ish | Optional | No | Cast to `(int)` inside the filter | Any order id | None (no filter) | None | `77` | Order filter |
| `user_id` | query | integer-ish | Optional | No | Cast to `(int)` inside the filter | Any user id | None (no filter) | None | `5` | Owner filter |
| `search` | query | string | Optional | No | Applied when non-null and non-empty; `uuid LIKE %search%` | Any string | None (no filter) | None | `123e4567` | Partial-uuid search |
| `per_page` | query | integer-ish | Optional | No | Cast to `(int)`; unvalidated | Any positive integer | `25` | None | `25` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/digital-entitlements?status=delivered&per_page=25",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ['data' => DigitalEntitlementResource::collection(items), 'meta' => ...])`. Items carry `user` + `product` objects (service eager-loads both relations).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Page envelope (custom `data` + `meta` shape — NOT the product `links` shape) | Contains `data` + `meta` below |
| `data.data` | array | Always present (can be empty) | Page items | Empty array when nothing matches |
| `data.data[].uuid` | string | Always present | Entitlement uuid | e.g. `"123e4567-e89b-12d3-a456-426614174000"` |
| `data.data[].status` | string | Always present | Entitlement state | `pending`, `delivered`, `revoked` |
| `data.data[].download_limit` | integer | Always present | Download cap (`0` = unlimited) | e.g. `5` or `0` |
| `data.data[].unlimited` | boolean | Always present | Whether the cap is unbounded (`download_limit === 0`) | `true` / `false` |
| `data.data[].download_count` | integer | Always present | Consumed downloads | e.g. `2` |
| `data.data[].delivered_at` | string \| null | Always present (nullable) | Delivery timestamp | ISO-8601 or `null` |
| `data.data[].revoked_at` | string \| null | Always present (nullable) | Revocation timestamp | ISO-8601 or `null` |
| `data.data[].expires_at` | string \| null | Always present (nullable) | Expiry timestamp | ISO-8601 or `null` |
| `data.data[].order_id` | integer | Always present | Owning order id | e.g. `77` |
| `data.data[].order_product_id` | integer | Always present | Owning order-product row id | e.g. `101` |
| `data.data[].user` | object | Always present on index (relation eager-loaded) | Owner snapshot | `{id, name, email}` (null fields when owner missing) |
| `data.data[].product` | object | Always present on index (relation eager-loaded via `orderItem`) | Purchased product snapshot | `{id, name}` |
| `data.data[].created_at` | string \| null | Always present (nullable) | Creation timestamp | ISO-8601 or `null` |
| `data.meta` | object | Always present | Paginator counters | All keys below |
| `data.meta.current_page` | integer | Always present | Current page number | e.g. `1` |
| `data.meta.last_page` | integer | Always present | Total pages | e.g. `4` |
| `data.meta.per_page` | integer | Always present | Page size | e.g. `25` |
| `data.meta.total` | integer | Always present | Total matching rows | e.g. `100` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "uuid": "123e4567-e89b-12d3-a456-426614174000",
        "status": "delivered",
        "download_limit": 5,
        "unlimited": false,
        "download_count": 2,
        "delivered_at": "2026-01-01T00:00:00+00:00",
        "revoked_at": null,
        "expires_at": null,
        "order_id": 77,
        "order_product_id": 101,
        "user": { "id": 5, "name": "Sara", "email": "sara@example.com" },
        "product": { "id": 9, "name": "E-book" },
        "created_at": "2026-01-01T00:00:00+00:00"
      }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 4,
      "per_page": 25,
      "total": 100
    }
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-digital-access` (route gate) or `view-orders` (constructor gate) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token missing either permission (code-traced double gate) | See 403 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

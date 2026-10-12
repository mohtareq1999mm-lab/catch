# Endpoint Name

Digital Entitlements – Show Entitlement (`GET /api/v1/digital-entitlements/{uuid}`)

# Description

Returns one digital entitlement by uuid with its owner and product snapshots loaded.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/digital-entitlements/{uuid}` (explicit `{uuid}` placeholder with `whereUuid('uuid')` — non-uuid segments match NO route) |
| Path composition | `api/v1` prefix + `digital-entitlements/{uuid}` custom `Route::get(...)->name('admin.digital-entitlements.show')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:manage-digital-access` |
| Controller action | `Marvel\Http\Controllers\DigitalEntitlementController@show(string $uuid)` → `DigitalEntitlement::where('uuid')->with(['orderItem.product', 'user'])->firstOrFail()` (miss escapes as `ModelNotFoundException` → `Handler` 404 fixed string); returns `apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new DigitalEntitlementResource(...))` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice with DIFFERENT permissions (code-verified): route middleware `manage-digital-access` AND constructor `view-orders` on `only: [index, show]` — the caller needs both |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-entitlements.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | path | string (uuid) | Required | No | `whereUuid('uuid')` + `where('uuid', $uuid)` equality | Existing entitlement uuid | None | None | `123e4567-e89b-12d3-a456-426614174000` | Identifies the entitlement |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/digital-entitlements/123e4567-e89b-12d3-a456-426614174000",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: entitlement returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new DigitalEntitlementResource(...))` with `orderItem.product` + `user` loaded, so `user` and `product` objects are present.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `DigitalEntitlementResource` (relations loaded, so gated objects present) | Every key below |
| `data.uuid` | string | Always present | Entitlement uuid | e.g. `"123e4567-e89b-12d3-a456-426614174000"` |
| `data.status` | string | Always present | Entitlement state | `pending`, `delivered`, `revoked` |
| `data.download_limit` | integer | Always present | Download cap (`0` = unlimited) | e.g. `5` or `0` |
| `data.unlimited` | boolean | Always present | Whether the cap is unbounded | `true` / `false` |
| `data.download_count` | integer | Always present | Consumed downloads | e.g. `2` |
| `data.delivered_at` | string \| null | Always present (nullable) | Delivery timestamp | ISO-8601 or `null` |
| `data.revoked_at` | string \| null | Always present (nullable) | Revocation timestamp | ISO-8601 or `null` |
| `data.expires_at` | string \| null | Always present (nullable) | Expiry timestamp | ISO-8601 or `null` |
| `data.order_id` | integer | Always present | Owning order id | e.g. `77` |
| `data.order_product_id` | integer | Always present | Owning order-product row id | e.g. `101` |
| `data.user` | object | Always present on show (relation eager-loaded) | Owner snapshot | `{id, name, email}` |
| `data.product` | object | Always present on show (relation eager-loaded via `orderItem`) | Purchased product snapshot | `{id, name}` |
| `data.created_at` | string \| null | Always present (nullable) | Creation timestamp | ISO-8601 or `null` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
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
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-digital-access` or `view-orders` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token missing either permission (code-traced double gate) | See 403 example |
| 404 | No entitlement with `{uuid}` (`firstOrFail` escapes → `Handler` fixed string) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown uuid | See 404 example |
| 404 | Non-uuid `{uuid}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-uuid segment | See 404-constraint example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

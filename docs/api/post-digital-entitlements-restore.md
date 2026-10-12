# Endpoint Name

Digital Entitlements – Restore Entitlement (`POST /api/v1/digital-entitlements/{uuid}/restore`)

# Description

Restores a revoked entitlement to delivered idempotently and returns the refreshed resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/digital-entitlements/{uuid}/restore` (explicit `{uuid}` placeholder with `whereUuid('uuid')` — non-uuid segments match NO route) |
| Path composition | `api/v1` prefix + `digital-entitlements/{uuid}/restore` custom `Route::post(...)->name('admin.digital-entitlements.restore')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:manage-digital-access` |
| Controller action | `Marvel\Http\Controllers\DigitalEntitlementController@restore(Request $request, string $uuid)` → `where('uuid')->firstOrFail()` (miss → 404 fixed string); `DigitalEntitlementService::restore` — terminal-state transition ONLY (`revoked` → `delivered` with `revoked_at` nulled; any non-revoked state is an idempotent no-op, still 200); returns `apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, new DigitalEntitlementResource($entitlement->refresh()))` with NO relations eager-loaded (see response note) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `manage-digital-access` via route middleware AND constructor `only: [setLimit, revoke, restore]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-entitlements.restore` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (recorded as the log actor) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | path | string (uuid) | Required | No | `whereUuid('uuid')` + `where('uuid', $uuid)` equality | Existing entitlement uuid | None | None | `123e4567-e89b-12d3-a456-426614174000` | Identifies the entitlement |

No body fields and no `422` contract.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/digital-entitlements/123e4567-e89b-12d3-a456-426614174000/restore",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: entitlement restored — HTTP 200 (ALWAYS 200, including non-revoked no-ops)

Returned via `ApiResponse::apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, new DigitalEntitlementResource($entitlement->refresh()))`. The refresh loads NO relations, so `user` and `product` keys are OMITTED here (code-verified asymmetry vs show/index). A revoked entitlement returns with `status: "delivered"` and `revoked_at: null`; a non-revoked entitlement returns unchanged with the same 200 shape.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_DATA_SUCCESSFULLY` | `en` → `"Data updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `DigitalEntitlementResource` WITHOUT relations | Every key below; `user`/`product` absent |
| `data.uuid` | string | Always present | Entitlement uuid | e.g. `"123e4567-e89b-12d3-a456-426614174000"` |
| `data.status` | string | Always present | Entitlement state after the call | `"delivered"` when restored; unchanged (`pending`/`delivered`) on no-op |
| `data.download_limit` | integer | Always present | Download cap | e.g. `5` or `0` |
| `data.unlimited` | boolean | Always present | Whether the cap is unbounded | `true` / `false` |
| `data.download_count` | integer | Always present | Consumed downloads | e.g. `2` |
| `data.delivered_at` | string \| null | Always present (nullable) | Delivery timestamp | ISO-8601 or `null` |
| `data.revoked_at` | string \| null | Always present (nullable) | Revocation timestamp (`null` after a restore) | `null` on restored rows |
| `data.expires_at` | string \| null | Always present (nullable) | Expiry timestamp | ISO-8601 or `null` |
| `data.order_id` | integer | Always present | Owning order id | e.g. `77` |
| `data.order_product_id` | integer | Always present | Owning order-product row id | e.g. `101` |
| `data.created_at` | string \| null | Always present (nullable) | Creation timestamp | ISO-8601 or `null` |

```json
{
  "status": 200,
  "message": "Data updated successfully",
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
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-digital-access` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced double gate) | See 403 example |
| 404 | No entitlement with `{uuid}` | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown uuid | See 404 example |
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

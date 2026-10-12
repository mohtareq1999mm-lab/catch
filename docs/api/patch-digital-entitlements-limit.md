# Endpoint Name

Digital Entitlements – Set Download Limit (`PATCH /api/v1/digital-entitlements/{uuid}/limit`)

# Description

Overrides one entitlement's download cap with an explicit value or unlimited and returns the before/after pair.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` |
| Full path | `/api/v1/digital-entitlements/{uuid}/limit` (explicit `{uuid}` placeholder with `whereUuid('uuid')` — non-uuid segments match NO route) |
| Path composition | `api/v1` prefix + `digital-entitlements/{uuid}/limit` custom `Route::patch(...)->name('admin.digital-entitlements.limit')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:manage-digital-access` |
| Controller action | `Marvel\Http\Controllers\DigitalEntitlementController@setLimit(DigitalEntitlementLimitRequest $request, string $uuid)` → `where('uuid')->firstOrFail()` (miss → 404 fixed string); `setDownloadLimit($entitlement, array_key_exists('limit', validated) ? (int) validated('limit') : null, $actor)` — ABSENT key and explicit `null` both map to UNLIMITED (`0`); returns `apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ['uuid', 'previous_limit', 'download_limit', 'unlimited'])` and activity-logs the change |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `manage-digital-access` via route middleware AND constructor `only: [setLimit, revoke, restore]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-entitlements.limit` (explicit named route) |

Middleware runs before validation: 401/403 precede 404/422/200.

## Request inputs

`DigitalEntitlementLimitRequest` runs before the action (`authorize` returns `true`; permission enforced by middleware).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (recorded as the log actor) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | path | string (uuid) | Required | No | `whereUuid('uuid')` + `where('uuid', $uuid)` equality | Existing entitlement uuid | None | None | `123e4567-e89b-12d3-a456-426614174000` | Identifies the entitlement |
| `limit` | body | integer | Optional | Yes | `nullable\|integer\|min:0\|max:4294967295` | `0`–`4294967295`, explicit `null`, or omitted | Omitted/`null` → UNLIMITED (`0`) | None | `10` | New download cap (`0` = unlimited) |

## Request examples

Capped request:

```json
{
  "method": "PATCH",
  "url": "/api/v1/digital-entitlements/123e4567-e89b-12d3-a456-426614174000/limit",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "limit": 10
  }
}
```

Unlimited request (omitted key — explicit `null` behaves identically):

```json
{
  "method": "PATCH",
  "url": "/api/v1/digital-entitlements/123e4567-e89b-12d3-a456-426614174000/limit",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {}
}
```

# Responses

## Success: limit overridden — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ['uuid' => ..., 'previous_limit' => ..., 'download_limit' => ..., 'unlimited' => ...])`. `previous_limit` is the pre-write value; `download_limit` is the stored value (`0` when unlimited).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_DATA_SUCCESSFULLY` | `en` → `"Data updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Limit receipt (keys verified) | `uuid`, `previous_limit`, `download_limit`, `unlimited` |
| `data.uuid` | string | Always present | Entitlement uuid | e.g. `"123e4567-e89b-12d3-a456-426614174000"` |
| `data.previous_limit` | integer | Always present | Cap before this call | e.g. `5` |
| `data.download_limit` | integer | Always present | Stored cap (`0` = unlimited) | e.g. `10` or `0` |
| `data.unlimited` | boolean | Always present | Whether the stored cap is unbounded | `true` iff `download_limit` is `0` |

```json
{
  "status": 200,
  "message": "Data updated successfully",
  "success": true,
  "data": {
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "previous_limit": 5,
    "download_limit": 10,
    "unlimited": false
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-digital-access` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced double gate) | See 403 example |
| 404 | No entitlement with `{uuid}` | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown uuid | See 404 example |
| 404 | Non-uuid `{uuid}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-uuid segment | See 404-constraint example |
| 422 | `DigitalEntitlementLimitRequest` fails (non-integer, negative, or above `4294967295`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid `limit` (code-traced) | See 422 example |
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

422 example:

```json
{
  "message": "The limit field must be an integer.",
  "status": false,
  "errors": {
    "limit": ["The limit field must be an integer."]
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

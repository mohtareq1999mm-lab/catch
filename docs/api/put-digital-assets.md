# Endpoint Name

Digital Assets – Update Asset (`PUT /api/v1/digital-assets/{uuid}`)

# Description

Updates one digital asset's metadata only with file bytes permanently immutable here.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified, unlike product CRUD which registers both) |
| Full path | `/api/v1/digital-assets/{uuid}` (explicit `{uuid}` placeholder with `whereUuid('uuid')`) |
| Path composition | `api/v1` prefix + `digital-assets/{uuid}` custom `Route::put(...)->name('admin.digital-assets.update')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:update-product` |
| Controller action | `Marvel\Http\Controllers\DigitalAssetController@update(DigitalAssetUpdateRequest $request, string $uuid)` → uncaught `firstOrFail` (miss → 404 fixed) → `DigitalAssetService::update` through an EXPLICIT five-key whitelist (`original_name/display_name/sort_order/status/metadata` via `array_intersect_key` — anything else in the validated payload is DROPPED, not just unvalidated; code-verified second filter) → refreshed resource. File bytes stay immutable through this endpoint (replacement is the separate `/replace` operation — source-commented) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-product` via route middleware AND constructor `only: [update, destroy, replace]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-assets.update` (explicit named route) |

Middleware runs before validation: 401/403 precede 404/422. Validation precedes the lookup, so 422 precedes 404 when both apply.

## Request inputs

`status` is admin-settable ONLY between `active`/`inactive` — `revoked`/`expired` are system-reserved states (source-commented; sending them 422s).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | path | UUID string | Required | No | `whereUuid` + `string $uuid` | Existing asset UUID | None | None | `"123e4567-e89b-12d3-a456-426614174000"` | Identifies the asset |
| `original_name` | body | string | Optional | No | `sometimes\|required\|string\|max:255` (when present, non-empty) | Non-empty ≤ 255 chars | Unchanged | None | `"Manual v2"` | Original filename label |
| `display_name` | body | string | Optional | Yes | `sometimes\|nullable\|string\|max:255` | ≤ 255 chars or null | Unchanged | None | `"User Guide"` | Display label |
| `sort_order` | body | integer | Optional | No | `sometimes\|integer\|min:0` | Integer ≥ 0 | Unchanged | None | `1` | Display order |
| `status` | body | string | Optional | No | `sometimes\|string\|in:active,inactive` (system states rejected — code-verified) | `active`, `inactive` | Unchanged | None | `"active"` | Lifecycle state (admin subset) |
| `metadata` | body | object | Optional | Yes | `sometimes\|nullable\|array\|max:20` (≤ 20 keys) | Object ≤ 20 keys or null | Unchanged | None | `{"pages": "42"}` | Admin metadata map |
| `metadata.*` | body | string | Required per key | No | `string\|max:1000` | Strings ≤ 1000 chars | None | None | `"42"` | One metadata value |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/digital-assets/123e4567-e89b-12d3-a456-426614174000",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "display_name": "User Guide",
    "sort_order": 1
  }
}
```

# Responses

## Success: asset updated — HTTP 200 (generic message)

Returned via `ApiResponse::apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, new DigitalAssetResource(refreshed))` (generic key, NOT an asset-specific message — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_DATA_SUCCESSFULLY` | `en` → `"Data updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `DigitalAssetResource` | Same key set as show |

```json
{
  "status": 200,
  "message": "Data updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "type": "FILE",
    "original_name": "manual.pdf",
    "display_name": "User Guide",
    "mime": "application/pdf",
    "size": 102400,
    "sort_order": 1,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `update-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No asset with `{uuid}` (uncaught lookup) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown UUID with valid body (code-traced) | See 404 example |
| 404 | Malformed `{uuid}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-UUID segment | See 404-constraint example |
| 405 | `PATCH` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `DigitalAssetUpdateRequest` fails (overlong strings, bad `status`, > 20 metadata keys) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example:

```json
{
  "message": "The status field must be one of active, inactive.",
  "status": false,
  "errors": {
    "status": ["The status field must be one of active, inactive."]
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

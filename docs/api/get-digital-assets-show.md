# Endpoint Name

Digital Assets – Show Asset (`GET /api/v1/digital-assets/{uuid}`)

# Description

Returns one digital asset by UUID with secrets permanently hidden from serialization.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/digital-assets/{uuid}` (explicit `{uuid}` placeholder with `whereUuid('uuid')` — malformed UUIDs match NO route → 404 route-miss shape; code-verified) |
| Path composition | `api/v1` prefix + `digital-assets/{uuid}` custom `Route::get(...)->name('admin.digital-assets.show')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:view-products` |
| Controller action | `Marvel\Http\Controllers\DigitalAssetController@show(string $uuid)` → `DigitalAsset::where('uuid', $uuid)->firstOrFail()` with NO try/catch — miss escapes as `ModelNotFoundException` → `Handler` 404 FIXED string (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-products` via route middleware AND constructor `only: [index, show]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-assets.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | path | UUID string | Required | No | `whereUuid('uuid')` route constraint + `string $uuid` signature | Existing asset UUID | None | None | `"123e4567-e89b-12d3-a456-426614174000"` | Identifies the asset |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/digital-assets/123e4567-e89b-12d3-a456-426614174000",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: asset returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new DigitalAssetResource($asset))` (same contract as the collection entries — source-commented parity).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `DigitalAssetResource` (secret-free by construction) | `id`, `uuid`, `type`, `original_name`, `display_name`, `mime`, `size` (int), `sort_order` (int), `external_url?` (URL-type only), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "type": "FILE",
    "original_name": "manual.pdf",
    "display_name": null,
    "mime": "application/pdf",
    "size": 102400,
    "sort_order": 0,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-products` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No asset with `{uuid}` (uncaught `firstOrFail` → fixed string) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown UUID (code-traced) | See 404 example |
| 404 | Malformed `{uuid}` matches NO route (`whereUuid` constraint) | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape — different text from the miss row; code-verified) | None | Non-UUID segment | See 404-constraint example |
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

404 example (unknown UUID):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-constraint example (malformed UUID):

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

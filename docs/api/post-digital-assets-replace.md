# Endpoint Name

Digital Assets – Replace File (`POST /api/v1/digital-assets/{uuid}/replace`)

# Description

Swaps one FILE asset's bytes through a checksummed pipeline while preserving identity.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/digital-assets/{uuid}/replace` (explicit `{uuid}` placeholder with `whereUuid('uuid')`) |
| Path composition | `api/v1` prefix + `digital-assets/{uuid}/replace` custom `Route::post(...)->name('admin.digital-assets.replace')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:update-product` |
| Controller action | `Marvel\Http\Controllers\DigitalAssetController@replace(ReplaceDigitalAssetRequest $request, string $uuid)` → uncaught `firstOrFail` (miss → 404 fixed; NOTE validation runs FIRST — see order below) → `DigitalAssetService::replace`: FILE-type guard (non-FILE → 422 `NOT_REPLACEABLE`, code-verified at BOTH request and service layers), registry/finfo byte validation, checksum-before-mutation, new-file-under-own-UUID-name write, transactional row swap (path/mime/extension/size/checksum), compensate-delete on persistence failure, old-file removal only after commit (lifecycle verified in service comments); `uuid`/`original_name`/`display_name` survive (only byte-derived fields refresh — except the optional `display_name` request refresh, code-verified nuance) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-product` via route middleware AND constructor `only: [update, destroy, replace]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `multipart/form-data` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-assets.replace` (explicit named route) |

Order matters: 401/403 → 422 (validation incl. the DB-backed `after` hook) → 404 (lookup) → 200/422-service/500. NOTE the `after` hook queries the asset by `{uuid}` itself — an unknown UUID passes the hook silently (null → no error) and 404s in the controller (code-verified).

## Request inputs

`ReplaceDigitalAssetRequest` runs before the body (registry extensions/cap are config-driven — exact values **Unverified in source**).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | path | UUID string | Required | No | `whereUuid` + `string $uuid`; must be a FILE-type asset (else 422 at BOTH layers) | UUID of a FILE asset | None | None | `"123e4567-e89b-12d3-a456-426614174000"` | Identifies the asset |
| `file` | body (file) | file | Required | No | `required\|file\|mimes:<registry>\|max:<registry>` + `after`-hook FILE-type check (`ERROR.DIGITAL_ASSET_NOT_REPLACEABLE` → 422 with `errors.file`) | Registry-allowed bytes | None | None | `manual-v2.pdf` | Replacement bytes |
| `display_name` | body | string | Optional | Yes | `sometimes\|nullable\|string\|max:255` (refreshed alongside replacement when present — code-verified) | ≤ 255 chars or null | Untouched | None | `"User Guide v2"` | Display label refresh |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/digital-assets/123e4567-e89b-12d3-a456-426614174000/replace",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "multipart/form-data",
    "lang": "en"
  },
  "body": {
    "file": "<file manual-v2.pdf>"
  }
}
```

# Responses

## Success: bytes replaced — HTTP 200 (generic message)

Returned via `ApiResponse::apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, new DigitalAssetResource($replaced))` (same generic key as metadata update — the two operations are indistinguishable by message; code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_DATA_SUCCESSFULLY` | `en` → `"Data updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `DigitalAssetResource` with REFRESHED byte-derived fields (`mime/size/updated_at`; same `uuid`) | Same key set as show |

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
    "display_name": null,
    "mime": "application/pdf",
    "size": 204800,
    "sort_order": 0,
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
| 404 | No asset with `{uuid}` (validation passes first for unknown UUIDs — the `after` hook adds no error on null) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown UUID with valid file (code-traced order) | See 404 example |
| 404 | Malformed `{uuid}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-UUID segment | See 404-constraint example |
| 405 | `PUT`/`PATCH` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Request validation fails (missing/oversize file, non-FILE asset via the `after` hook) | `{message: string, status: boolean, errors: object}` | None | Laravel default + `ERROR.DIGITAL_ASSET_NOT_REPLACEABLE` (`en` → `"Only file assets can be replaced."`) for the type check | Field → messages | Invalid body or URL/LICENSE/ACCESS asset (code-traced both) | See 422 example |
| 422 | Service byte-truth refusal (MIME mismatch, disabled category, oversize content — translated at throw, verbatim on the wire) | `{message: string, status: boolean}` | None | Translated per-cause text | None | Post-validation service refusal (code-traced guard family) | See 422-service example |
| 500 | Persistence failure after checksum (compensate-delete path → `DIGITAL_ASSET_UPLOAD_FAILED`) | `{message: string, status: boolean}` | None | Translated `ERROR.DIGITAL_ASSET_UPLOAD_FAILED` | None | Storage/DB failure mid-replace (code-traced) | See 500 example |
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

422 example (non-FILE asset via `after` hook):

```json
{
  "message": "Only file assets can be replaced.",
  "status": false,
  "errors": {
    "file": ["Only file assets can be replaced."]
  }
}
```

422-service example (translated at throw):

```json
{
  "message": "The file content does not match its extension.",
  "status": false
}
```

500 example:

```json
{
  "message": "Digital asset could not be stored. Please try again.",
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

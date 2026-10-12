# Endpoint Name

Digital Assets – Delete Asset (`DELETE /api/v1/digital-assets/{uuid}`)

# Description

Deletes one digital asset by UUID behind the delete-product permission and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/digital-assets/{uuid}` (explicit `{uuid}` placeholder with `whereUuid('uuid')`) |
| Path composition | `api/v1` prefix + `digital-assets/{uuid}` custom `Route::delete(...)->name('admin.digital-assets.destroy')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:delete-product` |
| Controller action | `Marvel\Http\Controllers\DigitalAssetController@destroy(string $uuid)` → uncaught `firstOrFail` (miss → 404 fixed) → `DigitalAssetService::delete` (row deleted inside a DB transaction, physical file removed after commit; commit-failure leaves row+file intact, file-removal failure is warning-logged — code-verified); permission is aligned: route AND constructor both demand `delete-product` (the constructor previously gated `destroy` under `update-product` — repaired, see testing guide) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-product` via route middleware AND controller constructor `only: [destroy]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-assets.destroy` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | path | UUID string | Required | No | `whereUuid` + `string $uuid` | Existing asset UUID | None | None | `"123e4567-e89b-12d3-a456-426614174000"` | Identifies the asset |

No query parameters, body fields, or `422` contract. Entitlement fallout of asset deletion (revocation/expiry of granted access) is service-level (**Unverified in source** — no cascade in the controller). No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/digital-assets/123e4567-e89b-12d3-a456-426614174000",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: asset deleted — HTTP 200 (generic message, no data)

Returned via `ApiResponse::apiResponse(DELETE_DATA_SUCCESSFULLY, 200, true)` with NO data key (generic key, NOT an asset-specific message — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETE_DATA_SUCCESSFULLY` | `en` → `"Data deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Data deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `delete-product` (route layer, runs first, then constructor layer — same permission) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `delete-product` | See 403 example |
| 404 | No asset with `{uuid}` (uncaught lookup) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown UUID (code-traced) | See 404 example |
| 404 | Malformed `{uuid}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-UUID segment | See 404-constraint example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (either layer — identical text):

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

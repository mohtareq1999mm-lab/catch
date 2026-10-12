# Endpoint Name

Products – Export Download (`GET /api/v1/products/export/{id}/download`)

# Description

Downloads the completed export workbook or a 409 while it is not ready.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/products/export/{id}/download` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `products/export/{id}/download` custom `Route::get(...)->name('admin.products.export.download')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:export-product` (plus the constructor's identical gate — double gate, same permission) |
| Controller action | `Marvel\Http\Controllers\ProductExportController@download(int $id)`: ownership scoping (non-SUPER_ADMIN limited to own rows) + `authorize('download', ...)` policy (a THIRD distinct ability after `view`/`cancel` — denial → 403 no-period); `findOrFail` miss → 404 fixed escape; readiness requires ALL THREE (`status === 'completed'` STRICT — `completed_with_errors` does NOT qualify, code-verified strictness; non-empty `file_path`; file present on the `imports` disk) else 409 translated `EXPORT_NOT_READY`; else streams the file with the STORED `file_name` (fallback: basename of the path) and Excel MIME |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Required: `export-product` (route + constructor) AND row ownership (or SUPER_ADMIN) AND `download` policy — three layers with a dedicated ability |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (JSON only flows on error paths; success is binary) |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.export.download` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the ownership key) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale (error paths) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Own completed export id | None | None | `9` | Identifies the export |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/products/export/9/download",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: workbook file — HTTP 200 (BINARY, not JSON)

Binary download (stored `file_name` or path basename, Excel MIME). There is deliberately NO JSON example — success bytes are the workbook.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| body | binary | Always present on success | The export workbook | xlsx bytes |
| `Content-Type` | header | Always present on success | MIME override | Excel MIME |
| `Content-Disposition` | header | Always present on success | Attachment filename | Stored `file_name` or path basename |

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `export-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Row exists but `download` policy denies (dedicated ability — `view`/`cancel` grants do NOT imply it) | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (NO trailing period) | None | Policy denial (code-traced) | See 403-policy example |
| 404 | No accessible export with `{id}` (own-row miss or foreign row) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown/foreign id | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 409 | Export not downloadable (status ≠ `completed` — INCLUDING `completed_with_errors`, code-verified strictness; empty `file_path`; or file missing from the `imports` disk) | `{status: integer, message: string, success: boolean}` | None | Translated `MESSAGE.EXPORT_NOT_READY`: `en` → `"Export file is not ready yet"` | None | Unready export (code-traced all three sub-cases) | See 409 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

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

403-policy example (policy denial — note NO period):

```json
{
  "message": "This action is unauthorized",
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

409 example:

```json
{
  "status": 409,
  "message": "Export file is not ready yet",
  "success": false
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

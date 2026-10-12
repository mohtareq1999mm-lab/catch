# Endpoint Name

Products – Import Error Download (`GET /api/v1/products/import/{id}/download-errors`)

# Description

Downloads failed import rows as a spreadsheet or a translated 404 when error-free.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/products/import/{id}/download-errors` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `products/import/{id}/download-errors` custom `Route::get(...)->name('admin.products.import.download-errors')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:import-product` (plus the constructor's identical gate — double gate, same permission) |
| Controller action | `Marvel\Http\Controllers\ProductImportController@downloadErrors(int $id)`: ownership scoping (non-SUPER_ADMIN limited to own rows) + `authorize('view', $import)` policy; `findOrFail` miss → 404 fixed escape; EMPTY errors → `apiResponse(IMPORT_NO_ERRORS, 404, false)` (translated 404 — a successful query with nothing to show; code-verified); otherwise builds an in-memory xlsx (`Sheet/Row/SKU/Error Message` columns from `{sheet, row, sku, error_message}` entries), stores it on the `local` disk, streams it as a download named `failed_import_rows_{id}.xlsx` (Excel MIME), and deletes the temp file after send |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Required: `import-product` (route + constructor) AND row ownership (or SUPER_ADMIN) AND `view` policy — three layers |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (JSON only flows on error paths; success is binary) |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.import.download-errors` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the ownership key) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale (error paths) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Own import id with errors | None | None | `12` | Identifies the import |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/products/import/12/download-errors",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: error workbook — HTTP 200 (BINARY, not JSON)

Binary download (`failed_import_rows_{id}.xlsx`, Excel MIME, temp file deleted after send). Columns are FIXED English headings (`Sheet, Row, SKU, Error Message`) regardless of `lang` (code-verified — headings are hardcoded, not translated). There is deliberately NO JSON example — success bytes are the spreadsheet.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| body | binary | Always present on success | Failed-row workbook | xlsx bytes |
| `Content-Type` | header | Always present on success | MIME override | Excel MIME |
| `Content-Disposition` | header | Always present on success | Attachment filename | `failed_import_rows_{id}.xlsx` |

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `import-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Row exists but `view` policy denies | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (NO trailing period) | None | Policy denial (code-traced) | See 403-policy example |
| 404 | No accessible import with `{id}` (own-row miss or foreign row) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown/foreign id | See 404 example |
| 404 | Import has NO errors (empty `errors` — a healthy import, not a miss) | `{status: integer, message: string, success: boolean}` | None | Translated `MESSAGE.IMPORT_NO_ERRORS`: `en` → `"No errors found"` | None | Error-free import (code-traced) | See 404-empty example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
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

404-empty example (healthy import):

```json
{
  "status": 404,
  "message": "No errors found",
  "success": false
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

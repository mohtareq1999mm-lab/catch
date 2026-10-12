# Endpoint Name

Products – Start Import (`POST /api/v1/products/import`)

# Description

Queues a product spreadsheet import with idempotent replay and returns 202 with the tracker.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/products/import` |
| Path composition | `api/v1` prefix + `products/import` custom `Route::post(...)->name('admin.products.import')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:import-product` (redundant with the constructor's identical gate — code-verified double gate, same permission) |
| Controller action | `Marvel\Http\Controllers\ProductImportController@import(ProductImportRequest $request)`: (1) `Idempotency-Key`/`X-Idempotency-Key` replay under a 10s cache lock (5s block; lock failures degrade to no-lock, code-verified) — known key + live row → 202 SAME import, no new row/job; (2) SHA-256 same-file + pending/processing row by the same user within 10 min (+ hash-cache match) → 202 SAME import (duplicate-upload guard); (3) otherwise stores the file, creates the `Import` row (`pending`, `total_rows: 0`), writes the progress signal file, broadcasts the queued Pusher event, dispatches `ImportProductsJob`, caches idempotency (24h) + hash (10m) → 202 |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Required: `import-product` via route middleware AND constructor (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`); `Idempotency-Key` (or `X-Idempotency-Key`) for replay protection (24h row mapping per user) |
| Supported request formats | `multipart/form-data` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.import` (explicit named route) |

Middleware runs before validation: 401/403 precede 422/202.

## Request inputs

`ProductImportRequest` runs before the body, with TRANSLATED rule messages (custom `messages()` — code-verified, unlike most requests' Laravel defaults).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (recorded as `created_by`) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `Idempotency-Key` | header | string | Optional | No | Free-form; `X-Idempotency-Key` accepted as fallback; per-USER namespace (same key by another user is independent — code-verified) | Any string | None (no replay) | None | `"job-9f3a"` | Replay key (24h) |
| `file` | body (file) | file | Required | No | `required\|file\|mimes:xlsx,xls,ods\|max:20480` (≤ 20 MB; translated messages: `An Excel file is required` / `The file must be a valid Excel file (xlsx, xls, or ods)` / `The file size must not exceed 20 MB`) | xlsx/xls/ods ≤ 20 MB | None | None | `products.xlsx` | The workbook to import |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/products/import",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "multipart/form-data",
    "Idempotency-Key": "job-9f3a",
    "lang": "en"
  },
  "body": {
    "file": "<file products.xlsx>"
  }
}
```

# Responses

## Success: import queued — HTTP 202 (ALWAYS 202, including replays)

Returned via `ApiResponse::apiResponse(IMPORT_STARTED_SUCCESSFULLY, 202, true, ['import_id' => ..., 'status' => ...])` for fresh uploads, idempotency replays, AND hash-duplicate uploads alike (all three paths share the message + shape — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `202` on accept (NOT 200/201) |
| `message` | string | Always present | Translated `MESSAGE.IMPORT_STARTED_SUCCESSFULLY` | `en` → `"Import started successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on accept |
| `data` | object | Always present on accept | Tracker payload (keys verified on all three paths) | `import_id` (Import row id — track via the status endpoint), `status` (row status at accept time, typically `"pending"`) |

```json
{
  "status": 202,
  "message": "Import started successfully",
  "success": true,
  "data": {
    "import_id": 12,
    "status": "pending"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `import-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `import-product` (code-traced) | See 403 example |
| 422 | `ProductImportRequest` fails (missing/non-spreadsheet/oversize file) | `{message: string, status: boolean, errors: object}` | None | TRANSLATED custom messages (not Laravel defaults — code-verified `messages()` override) | Field → messages | Invalid file (code-traced) | See 422 example |
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

422 example (translated messages):

```json
{
  "message": "An Excel file is required",
  "status": false,
  "errors": {
    "file": ["An Excel file is required"]
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

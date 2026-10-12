# Endpoint Name

Products – Import Sample (`GET /api/v1/products/import/sample`)

# Description

Downloads the product import spreadsheet template or a translated 404 when missing.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/products/import/sample` |
| Path composition | `api/v1` prefix + `products/import/sample` custom `Route::get(...)->name('admin.products.import.sample')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with NO route-level permission middleware — but the controller constructor enforces `auth:sanctum` + `permission:import-product` on EVERY action (code-verified backstop, so the effective gate equals its siblings). Registered BEFORE `apiResource('products', ...)` precisely so it is not captured as `products/{product}` (source comment verified) |
| Controller action | `Marvel\Http\Controllers\ProductImportController@downloadSample` → `config('marvel.import.samples.product')` path check; missing file → `apiResponse(IMPORT.SAMPLE_NOT_FOUND, 404, false)` (translated dotted key — code-verified); present → binary `response()->download` (`product-import-sample.xlsx`, Excel MIME) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Required: `import-product` via controller constructor (all actions — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (JSON only flows on the 404 path; success is binary) |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.import.sample` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale (404 path) |

No path/query/body parameters. No `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/products/import/sample",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: template file — HTTP 200 (BINARY, not JSON)

Binary download (`product-import-sample.xlsx`, `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`). There is deliberately NO JSON example — success bytes are the spreadsheet.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| body | binary | Always present on success | The sample workbook | xlsx bytes |
| `Content-Type` | header | Always present on success | MIME override | Excel MIME |
| `Content-Disposition` | header | Always present on success | Attachment filename | `product-import-sample.xlsx` |

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `import-product` (constructor gate) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `import-product` (code-traced) | See 403 example |
| 404 | Sample file not configured on disk (`config('marvel.import.samples.product')` missing) | `{status: integer, message: string, success: boolean}` | None | Translated `IMPORT.SAMPLE_NOT_FOUND`: `en` → `"Sample file not found"` | None | Unconfigured sample path (code-traced) | See 404 example |
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
  "status": 404,
  "message": "Sample file not found",
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

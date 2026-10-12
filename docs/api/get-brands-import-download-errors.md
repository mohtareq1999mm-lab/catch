# Endpoint Name

Brands – Import Error File (`GET /api/v1/brands/import/{id}/download-errors`)

# Description

Streams the failed-rows spreadsheet for an import, or 404 when it has none.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/brands/import/{id}/download-errors` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/import/{id}/download-errors` (with `whereNumber('id')`) from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.brands.import.download-errors` |
| Controller action | `Marvel\Http\Controllers\BrandImportController@downloadErrors` (BRAND_IMPORT-scoped lookup + creator-scope for non-super-admins + `authorize('download')` policy; builds the xlsx on the fly via an anonymous `FromCollection` export, stores to `local` disk, streams with `deleteFileAfterSend`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `import-brand` via constructor middleware (whole controller) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.brands.import.download-errors` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes non-super-admin lookups to own rows) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (errors only — the file itself is locale-free) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (route-level `whereNumber` constraint) | Required | No | Must be numeric to match the route at all; then resolved by the BRAND_IMPORT-scoped query (plus `created_by` = caller unless super-admin), throwing `ModelNotFoundException` for unknown OR foreign ids → `Handler` 404 | Id of a brand-import row visible to the caller | None | None | `17` | Identifies the import operation |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/brands/import/17/download-errors",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: error spreadsheet — HTTP 200

A `BinaryFileResponse` download (NOT a JSON envelope — code-verified): generated file `failed_brand_import_rows_{id}_{uniqid}.xlsx`, `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, deleted from the server after sending (`deleteFileAfterSend`). No endpoint-specific success test exists (all expectations below are code-verified or test-verified on siblings as marked). There is exactly one success contract.

| Aspect | Value |
| --- | --- |
| HTTP status | `200` (binary download) |
| Filename | `failed_brand_import_rows_{id}_{uniqid}.xlsx` (unique per call — code-verified) |
| Content-Type | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| Columns (headings row, code-verified) | `Sheet`, `Row`, `Name (EN)`, `Name (AR)`, `Error Message` — mapped from each error entry's `sheet`/`row`/`name_en`/`name_ar`/`error_message` keys (missing keys → empty cells) |
| Server cleanup | Temp file deleted after send (code-verified) |

No JSON example applies — the body is the spreadsheet bytes.

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. No test covers this endpoint: 401/403 expectations are verified against the shared middleware/policy paths exercised by named tests on sibling import routes (cited per row); all other rows are code-verified or code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike any JSON body here) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (identical rejection test-verified on sibling import routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller fails the `authorize('download')` policy or lacks the `import-brand` permission — stacked gates as on the sibling actions (policy nearly unreachable after the scoped lookup; code-verified order) | `{message: string, status: boolean}` | None | Policy denial: `"This action is unauthorized"` (fixed string, no period); permission denial: translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Gate failure (code-traced — no endpoint-specific 403 test exists) | See 403 examples |
| 404 | No visible brand-import row matches `{id}` (scoped lookup miss) or non-numeric `{id}` (no route match → `NotFoundHttpException` branch) | `{message: string, status: boolean}` | None | Scoped miss: `"Resource Not Found"` (fixed); non-numeric: `"Not Found"` (fixed, code-traced distinct body) | None | Unknown/foreign id, or non-numeric id | See 404 examples |
| 404 | The import HAS no errors (`empty($import->errors)` — including terminal-success rows): `apiResponse(__('message.MESSAGE.IMPORT_NO_ERRORS'), 404, false)` envelope (code-verified — translated notice inside the controller envelope, NOT the Handler fixed string) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Already-translated string at call time: `en` → `"No errors found"`, `ar` → `"لم يتم العثور على أخطاء"` | None | Error-free import (code-traced — no endpoint-specific test exists) | See 404 example (no errors) |
| 405 | Any HTTP method other than `GET` on this exact URI (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the lookup/build/stream), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Export-build or I/O failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (policy denial; default locale):

```json
{
  "message": "This action is unauthorized",
  "status": false
}
```

403 example (permission — authenticated without `import-brand`; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown or foreign `{id}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404 example (non-numeric `{id}`):

```json
{
  "message": "Not Found",
  "status": false
}
```

404 example (import has no errors; `en` locale):

```json
{
  "status": 404,
  "message": "No errors found",
  "success": false
}
```

405 example (wrong method, e.g. `POST /api/v1/brands/import/17/download-errors`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

429 example (rate limit exceeded):

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

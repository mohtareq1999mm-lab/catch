# Endpoint Name

Brands – Import (`POST /api/v1/brands/import`)

# Description

Accepts a spreadsheet and queues an async brand import with idempotency protection.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/brands/import` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/import` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.brands.import`. Registered BEFORE `apiResource('brands')` so it is never captured by `POST brands/{brand}` (no such store-subordinate route exists, but the static-first ordering is verified in-file with an explicit comment) |
| Controller action | `Marvel\Http\Controllers\BrandImportController@import` (constructor: `auth:sanctum` + `permission:import-brand` for the WHOLE controller) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `import-brand` via constructor middleware (whole controller — every action below included) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`); `Idempotency-Key` (or `X-Idempotency-Key`) — see semantics below |
| Supported request formats | `multipart/form-data` (file upload; JSON bodies cannot carry the file) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.brands.import` |

## Request inputs

`BrandImportRequest` validation runs before the method body (no `failedValidation` override, but the framework default throws `ValidationException` — verified in vendor — so failures still render through `Handler` with the standard 422 shape). Custom per-rule messages verified below.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and owns the import row) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Idempotency-Key` / `X-Idempotency-Key` | header | string | Optional | N/A | No validation; when present, a repeat delivery within 24h returns the ORIGINAL import row with 202 instead of creating a duplicate (file-cache mapping per user+key, under a 10s lock with 5s blocking — code-verified) | Any opaque string | Absent (no key protection; the same-file duplicate check below still applies) | None | `"batch-2026-10-01"` | Safe-retry guard |
| `file` | body (file) | upload | Required | No | `required\|file\|mimes:xlsx,xls,ods\|max:20480` (20MB) with custom messages: missing → `IMPORT.VALIDATION.FILE_REQUIRED`, wrong type → `FILE_MIMES`, oversize → `FILE_MAX` (all file-verified en/ar below; note the rule set does NOT re-check anything else — row-level validation happens asynchronously in the job) | Spreadsheet ≤20MB | None | None | Binary upload | The brands workbook |

Idempotency layers (code-verified order): (1) explicit `Idempotency-Key` replay (24h file-cache mapping, only when the mapped row still exists — otherwise falls through and creates anew); (2) same-user + same-SHA256 + pending/processing + uploaded-within-10-minutes duplicate (file-cache hash mapping, 10min TTL — a re-upload of DIFFERENT bytes always creates a new row). A test-verified live lifecycle proves: 202 + `data.import_id` non-null + row `pending` + file on the PRIVATE `imports` disk (never public) + after synchronous job run, status terminal with `success_rows: 2`, brands created with slugged names, and the source file DELETED on success.

## Request examples

Valid request starting an import (test-verified call shape):

```json
{
  "method": "POST",
  "url": "/api/v1/brands/import",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Idempotency-Key": "batch-2026-10-01"
  },
  "body": {
    "file": "(binary xlsx ≤20MB, multipart/form-data)"
  }
}
```

# Responses

## Success: import queued — HTTP 202

Returned via `ApiResponse::apiResponse(__('message.MESSAGE.BRAND_IMPORT_STARTED'), 202, true, ['import_id' => ..., 'status' => ...])` after persisting the `pending` row, writing the progress signal file, broadcasting the queued event, and dispatching `ImportBrandsJob` — the SAME 202 shape is returned for idempotent replays (code-verified). Verified by `import_dispatches_job_on_meem_high_queue_and_returns_202` (real sample workbook → 202, `success: true`, job pushed; `postJson`, matching this route) and `test_real_brand_import_lifecycle` (full 202→terminal flow; `postJson`). There is exactly one success contract. Processing is ASYNC — the 202 `status` is the row state at creation (`pending`), not a completion proof; poll the status endpoint.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `202` on acceptance |
| `message` | string | Always present | Already-translated string passed through (locale from `lang` header at `__()` time) | `en` → `"Brand import started successfully"`, `ar` → `"تم بدء استيراد العلامات التجارية بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on acceptance |
| `data` | object | Always present on success | `{import_id, status}` — the row id to poll, plus its creation-time status | `import_id`: integer; `status`: `"pending"` for fresh rows (replay returns the stored status, e.g. `"processing"` — code-verified) |

Complete success example (`en` locale):

```json
{
  "status": 202,
  "message": "Brand import started successfully",
  "success": true,
  "data": {
    "import_id": 17,
    "status": "pending"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 202/401/422 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `import_requires_authentication_and_permission` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `import-brand` permission (constructor Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `import-brand` (no import-specific 403 test exists — code-traced through the shared path) | See 403 example |
| 405 | `GET`, `PUT`, `PATCH`, or `DELETE` on `/api/v1/brands/import` (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `BrandImportRequest` failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` — with the CUSTOM per-rule messages below, not framework defaults | `{message: string, status: boolean, errors: object}` | None | First failure message, one of (file-verified both locales): missing → `en` `"An Excel file is required"` / `ar` `"ملف Excel مطلوب"`; wrong type → `en` `"The file must be a valid Excel file (xlsx, xls, or ods)"` / `ar` `"يجب أن يكون الملف بصيغة Excel صالحة (xlsx, xls, or ods)"`; oversize → `en` `"The file size must not exceed 20 MB"` / `ar` `"حجم الملف يجب ألا يتجاوز 20 ميغابايت"` | Map of `file` → message array (exact multi-failure maps **Unverified in source**) | Missing file, non-spreadsheet upload (verified trigger: fake `bad.pdf` → 422), or file over 20MB | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in store/dispatch), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Storage failure (code-traced, no test) | See 500 example |

Row-level workbook problems NEVER surface here — they land asynchronously in the status `errors` array and the downloadable error file (see those docs).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `import-brand` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/brands/import`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (PDF upload instead of spreadsheet):

```json
{
  "message": "The file must be a valid Excel file (xlsx, xls, or ods)",
  "status": false,
  "errors": {
    "file": [
      "The file must be a valid Excel file (xlsx, xls, or ods)"
    ]
  }
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

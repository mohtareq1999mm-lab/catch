# Endpoint Name

Categories – Import (`POST /api/v1/categories/import`)

# Description

Accepts a spreadsheet and queues an async category import with no idempotency protection.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/categories/import` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/import` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.categories.import`, with route-level `permission:import-category` ON TOP of the constructor `import-category` gate (aligned — both require the same permission) |
| Controller action | `Marvel\Http\Controllers\CategoryImportController@import` (constructor: `auth:sanctum` + `permission:import-category`; NO idempotency layers at all — no key header read, no hash dedup: EVERY accepted call creates a new row and dispatches a new job. Code-verified difference from brand import, which has both) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `import-category` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `multipart/form-data` (file upload; JSON bodies cannot carry the file) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.categories.import` |

## Request inputs

`CategoryImportRequest` validation runs before the method body (no `failedValidation` override, but the framework default throws `ValidationException` — verified in vendor — so failures still render through `Handler` with the standard 422 shape). Same custom per-rule messages as brands (file-verified both locales).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and owns the import row) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `file` | body (file) | upload | Required | No | `required\|file\|mimes:xlsx,xls,ods\|max:20480` (20MB) with custom messages: missing → `en` `"An Excel file is required"` / `ar` `"ملف Excel مطلوب"`; wrong type → `en` `"The file must be a valid Excel file (xlsx, xls, or ods)"` / `ar` `"يجب أن يكون الملف بصيغة Excel صالحة (xlsx, xls, or ods)"`; oversize → `en` `"The file size must not exceed 20 MB"` / `ar` `"حجم الملف يجب ألا يتجاوز 20 ميغابايت"` | Spreadsheet ≤20MB | None | None | Binary upload | The categories workbook |

Row-level workbook problems NEVER surface here — they land asynchronously in the status `errors` array and the downloadable error file (see those docs; service-level row rules — deterministic slugs, hierarchy/cycle/duplicate/status/normalization handling — are job-side and out of HTTP scope).

## Request examples

Valid request starting an import (test-verified call shape):

```json
{
  "method": "POST",
  "url": "/api/v1/categories/import",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  },
  "body": {
    "file": "(binary xlsx ≤20MB, multipart/form-data)"
  }
}
```

# Responses

## Success: import queued — HTTP 202

Returned via `ApiResponse::apiResponse(__('message.MESSAGE.CATEGORY_IMPORT_STARTED'), 202, true, ['import_id' => ..., 'status' => ...])` after persisting the `pending` row, writing the progress signal file, broadcasting the queued event, and dispatching `ImportCategoriesJob`. Verified by `test_import_dispatches_job_and_returns_202` (real xlsx → 202, `success: true`, `data: [import_id, status]`, DB row `type: 'category-import'` + `status: 'pending'`, job pushed; `postJson`, matching this route). There is exactly one success contract. Processing is ASYNC — poll the status endpoint.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `202` on acceptance |
| `message` | string | Always present | Already-translated string at call time | `en` → `"Category import started successfully"`, `ar` → `"بدأ استيراد الفئات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on acceptance |
| `data` | object | Always present on success | `{import_id, status}` — the row id to poll, plus its creation-time status | `import_id`: integer; `status`: `"pending"` |

Complete success example (`en` locale):

```json
{
  "status": 202,
  "message": "Category import started successfully",
  "success": true,
  "data": {
    "import_id": 31,
    "status": "pending"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 202/401/422 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_import` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `import-category` permission (BOTH route and constructor gates require it; either rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `import-category` (no import-specific 403 test exists — code-traced through the shared path) | See 403 example |
| 405 | `GET`, `PUT`, `PATCH`, or `DELETE` on `/api/v1/categories/import` (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `CategoryImportRequest` failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` — with the CUSTOM per-rule messages above, not framework defaults | `{message: string, status: boolean, errors: object}` | None | First failure message (one of the six file-verified strings above) | Map of `file` → message array (exact multi-failure maps **Unverified in source**) | Missing file (verified by `test_import_validates_file_required` with `assertJsonValidationErrors(['file'])`), wrong type (verified by `test_import_validates_file_type` with a fake PDF), or oversize file | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in store/dispatch), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Storage failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `import-category` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/categories/import`):

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

# Endpoint Name

Brands – Import Sample (`GET /api/v1/brands/import/sample`)

# Description

Downloads the contract spreadsheet template for brand imports.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/brands/import/sample` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/import/sample` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.brands.import.sample`. Safe from `brands/import/{id}` capture TWO ways: declared first AND guarded by `whereNumber('id')` (`'sample'` is non-numeric — code-verified) |
| Controller action | `Marvel\Http\Controllers\BrandImportController@downloadSample` (reads `config('marvel.import.samples.brand')`; streams the file when present) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `import-brand` via constructor middleware (whole controller) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No inputs of any kind; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.brands.import.sample` |

## Request inputs

No inputs of any kind are honored. There is no validation, so there is no `422` contract.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (errors only — the file itself is locale-free) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/brands/import/sample",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: template file — HTTP 200

A `BinaryFileResponse` download (NOT a JSON envelope — code-verified): file `brand-import-sample.xlsx`, `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`. Verified by `sample_downloads_valid_xlsx_with_contract_headers` (real-token `getJson`... note the test calls `getJson` but asserts `assertOk` + the xlsx content type — matching this route; no test fix needed). There is exactly one success contract.

| Aspect | Value |
| --- | --- |
| HTTP status | `200` (binary download) |
| Filename | `brand-import-sample.xlsx` (fixed — code-verified) |
| Content-Type | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (test-verified) |
| Source | `config('marvel.import.samples.brand')` file path (code-verified config key) |

No JSON example applies — the body is the spreadsheet bytes.

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (correct methods); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the JSON success envelope — which doesn't exist here anyway) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no sample-specific 401 test exists; identical rejection test-verified on sibling import routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `import-brand` permission (constructor Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `import-brand` (no sample-specific 403 test exists — code-traced through the shared path) | See 403 example |
| 404 | The configured sample file is absent from disk: `apiResponse(__('message.IMPORT.SAMPLE_NOT_FOUND'), 404, false)` envelope (code-verified — translated notice inside the controller envelope, NOT the Handler fixed string) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Already-translated string at call time: `en` → `"Sample file not found"`, `ar` → `"ملف العينة غير موجود"` | None | Missing template file on the server (code-traced, no test) | See 404 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/brands/import/sample` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the file serving), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | I/O failure (code-traced, no test) | See 500 example |

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

404 example (template file missing on server; `en` locale):

```json
{
  "status": 404,
  "message": "Sample file not found",
  "success": false
}
```

405 example (wrong method, e.g. `POST /api/v1/brands/import/sample`):

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

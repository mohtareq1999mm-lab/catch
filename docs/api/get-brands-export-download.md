# Endpoint Name

Brands – Download Export (`GET /api/v1/brands/export/{id}/download`)

# Description

Streams the finished export workbook, or 409 while it is not ready.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/brands/export/{id}/download` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/export/{id}/download` (with `whereNumber('id')`) from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.brands.export.download` |
| Controller action | `Marvel\Http\Controllers\BrandExportController@download` (BRAND_EXPORT-scoped lookup + creator-scope for non-super-admins + `authorize('download')` policy; readiness gate on status/file/disk before streaming) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `export-brand` via constructor middleware (whole controller) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.brands.export.download` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes non-super-admin lookups to own rows) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (errors only — the file itself is locale-free) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (route-level `whereNumber` constraint) | Required | No | Must be numeric to match the route at all; then resolved by the BRAND_EXPORT-scoped query (plus `created_by` = caller unless super-admin), throwing `ModelNotFoundException` for unknown OR foreign ids → `Handler` 404 | Id of a brand-export row visible to the caller | None | None | `23` | Identifies the export operation |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/brands/export/23/download",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: workbook file — HTTP 200

A `BinaryFileResponse` download (NOT a JSON envelope — code-verified): the stored workbook with `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, served from the `imports` disk. Readiness requires ALL THREE (code-verified conjunction): row `status === 'completed'` (note: `completed_with_errors` does NOT qualify), non-empty `file_path`, AND the file present on disk. No endpoint-specific success test exists (all expectations below are code-verified or test-verified on siblings as marked). There is exactly one success contract.

| Aspect | Value |
| --- | --- |
| HTTP status | `200` (binary download) |
| Filename | Stored `file_name`, else the basename of `file_path` (code-verified fallback) |
| Content-Type | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| Source | `imports` disk at the row's `file_path` (private disk — never a public URL) |

No JSON example applies — the body is the spreadsheet bytes.

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. No test covers this endpoint: 401/403 expectations are verified against the shared middleware/policy paths exercised by named tests on sibling export routes (cited per row); all other rows are code-verified or code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike any JSON body here) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (identical rejection test-verified on the sibling export-start route) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller fails the `authorize('download')` policy or lacks the `export-brand` permission — stacked gates as on the sibling actions (policy nearly unreachable after the scoped lookup; code-verified order) | `{message: string, status: boolean}` | None | Policy denial: `"This action is unauthorized"` (fixed string, no period); permission denial: translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Gate failure (code-traced — no endpoint-specific 403 test exists) | See 403 examples |
| 404 | No visible brand-export row matches `{id}` (scoped lookup miss) or non-numeric `{id}` (no route match → `NotFoundHttpException` branch) | `{message: string, status: boolean}` | None | Scoped miss: `"Resource Not Found"` (fixed); non-numeric: `"Not Found"` (fixed, code-traced distinct body) | None | Unknown/foreign id, or non-numeric id | See 404 examples |
| 405 | Any HTTP method other than `GET` on this exact URI (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | Export not ready: row status is anything but exactly `'completed'` (including `completed_with_errors` — code-verified strictness), OR empty `file_path`, OR file missing from the `imports` disk — `apiResponse(__('message.MESSAGE.EXPORT_NOT_READY'), 409, false)` envelope | `{status: integer, message: string, success: boolean}`, no `data` key | None | Already-translated string at call time: `en` → `"Export file is not ready yet"`, `ar` → `"ملف التصدير غير جاهز بعد"` | None | Pending/processing/failed/cancelled row, error-completed row, or missing file (code-traced — no endpoint-specific test exists) | See 409 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the lookup/stream), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | I/O failure (code-traced, no test) | See 500 example |

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

403 example (permission — authenticated without `export-brand`; `en` locale):

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

405 example (wrong method, e.g. `POST /api/v1/brands/export/23/download`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (not ready — still processing; `en` locale):

```json
{
  "status": 409,
  "message": "Export file is not ready yet",
  "success": false
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

# Endpoint Name

Categories – Download Export (`GET /api/v1/categories/export/{id}/download`)

# Description

Streams the finished category-export workbook, or 409 while it is not ready.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/categories/export/{id}/download` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/export/{id}/download` (with `whereNumber('id')`) from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.categories.export.download`, with route-level `permission:export-category` ON TOP of the constructor `export-category` gate (aligned) |
| Controller action | `Marvel\Http\Controllers\CategoryExportController@download` (CATEGORY_EXPORT-scoped lookup + creator-scope for non-super-admins + `authorize('download')` policy; readiness gate on status/file/disk before streaming. Structurally identical to the brand twin) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `export-category` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.categories.export.download` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes non-super-admin lookups to own rows) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (errors only — the file itself is locale-free) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (route-level `whereNumber` constraint) | Required | No | Must be numeric to match the route at all; then resolved by the CATEGORY_EXPORT-scoped query (plus `created_by` = caller unless super-admin), throwing `ModelNotFoundException` for unknown OR foreign ids → `Handler` 404 | Id of a category-export row visible to the caller | None | None | `44` | Identifies the export operation |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/categories/export/44/download",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: workbook file — HTTP 200

A `BinaryFileResponse` download (NOT a JSON envelope — code-verified): the stored workbook with `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, served from the `imports` disk. Readiness requires ALL THREE (code-verified conjunction): row `status === 'completed'` (note: `completed_with_errors` does NOT qualify), non-empty `file_path`, AND the file present on disk. Verified by `test_download_returns_file_when_completed` (completed fixture with faked-disk file → file download; `getJson`, matching this route). There is exactly one success contract.

| Aspect | Value |
| --- | --- |
| HTTP status | `200` (binary download) |
| Filename | Stored `file_name`, else the basename of `file_path` (code-verified fallback) |
| Content-Type | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| Source | `imports` disk at the row's `file_path` (private disk — never a public URL) |

No JSON example applies — the body is the spreadsheet bytes.

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/409 contracts are test-verified (correct methods); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike any JSON body here) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no download-specific 401 test exists; identical rejection test-verified on sibling category routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller fails the `authorize('download')` policy or lacks the `export-category` permission — stacked gates as on the sibling actions (policy nearly unreachable after the scoped lookup; code-verified order) | `{message: string, status: boolean}` | None | Policy denial: `"This action is unauthorized"` (fixed string, no period); permission denial: translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Gate failure (code-traced — no endpoint-specific 403 test exists) | See 403 examples |
| 404 | No visible category-export row matches `{id}` (scoped lookup miss) or non-numeric `{id}` (no route match → `NotFoundHttpException` branch) | `{message: string, status: boolean}` | None | Scoped miss: `"Resource Not Found"` (fixed); non-numeric: `"Not Found"` (fixed, code-traced distinct body) | None | Unknown/foreign id, or non-numeric id | See 404 examples |
| 405 | Any HTTP method other than `GET` on this exact URI (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | Export not ready: row status is anything but exactly `'completed'` (including `completed_with_errors` — code-verified strictness), OR empty `file_path`, OR file missing from the `imports` disk — shared generic `EXPORT_NOT_READY` envelope | `{status: integer, message: string, success: boolean}`, no `data` key | None | Already-translated string at call time: `en` → `"Export file is not ready yet"`, `ar` → `"ملف التصدير غير جاهز بعد"` | None | Non-completed row or missing file; verified by `test_download_returns_409_when_not_ready` (processing fixture → 409) | See 409 example |
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

403 example (permission — authenticated without `export-category`; `en` locale):

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

405 example (wrong method, e.g. `POST /api/v1/categories/export/44/download`):

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

# Endpoint Name

Brands – Start Export (`GET|POST /api/v1/brands/export`)

# Description

Queues an async brand export, always creating a new operation unless an idempotency key replays.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` or `POST` (BOTH registered to the same `export` action — code-verified dual registration; the POST twin exists as `admin.brands.export.post`) |
| Full path | `/api/v1/brands/export` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/export` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.brands.export` (GET) / `admin.brands.export.post` (POST). Registered BEFORE `apiResource('brands')` so neither method is captured by `brands/{brand}` (verified in-file comment + order) |
| Controller action | `Marvel\Http\Controllers\BrandExportController@export` (constructor: `auth:sanctum` + `permission:export-brand`; NO request validation of any kind — no body is read) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `export-brand` via constructor middleware (whole controller) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`); `Idempotency-Key` (or `X-Idempotency-Key`) — see semantics below |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.brands.export` (GET) / `admin.brands.export.post` (POST) |

## Request inputs

No inputs are honored except the idempotency header. There is no validation, so there is no `422` contract.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and owns the export row) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Idempotency-Key` / `X-Idempotency-Key` | header | string | Optional | N/A | No validation; when present AND previously mapped (24h file-cache per user+key) AND the mapped row still exists, the ORIGINAL row is replayed with 202 instead of creating a duplicate. WITHOUT a key, EVERY call creates a new operation BY DESIGN (code-verified comment: "No automatic dedup for exports") — the opposite default from import's same-file check | Any opaque string | Absent (always create) | None | `"export-2026-10-01"` | Safe-retry guard |

Idempotency contrast (code-verified): import ALSO consults the key mapping (same 24h replay), but additionally dedups same-file re-uploads; export has NO content-based dedup — repeat calls without a key spawn repeat operations (and repeat queued jobs).

## Request examples

Valid request starting an export (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/brands/export",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: export queued — HTTP 202

Returned via `ApiResponse::apiResponse(__('message.MESSAGE.BRAND_EXPORT_STARTED'), 202, true, ['export_id' => ..., 'status' => ...])` after persisting the `pending` row (empty `file_path`/`file_name` until the job fills them), broadcasting the queued event, and dispatching `ExportBrandsJob` — the SAME 202 shape is returned for idempotent replays (code-verified). Verified by `export_start_returns_202_with_export_id` (202, `data.status: 'pending'`, job pushed; `getJson`, matching the GET twin). There is exactly one success contract. Processing is ASYNC — poll the export-status endpoint; download only when `completed` with a file.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `202` on acceptance |
| `message` | string | Always present | Already-translated string at call time | `en` → `"Brand export started successfully"`, `ar` → `"تم بدء تصدير العلامات التجارية بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on acceptance |
| `data` | object | Always present on success | `{export_id, status}` — the row id to poll, plus its creation-time status (note `export_id`, NOT `import_id` — code-verified key difference from import) | `export_id`: integer; `status`: `"pending"` for fresh rows (replay returns the stored status) |

Complete success example (`en` locale):

```json
{
  "status": 202,
  "message": "Brand export started successfully",
  "success": true,
  "data": {
    "export_id": 23,
    "status": "pending"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 202/401 contracts are test-verified (correct methods for each twin); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `import_requires_authentication_and_permission` expecting 401 on the GET twin | See 401 example |
| 403 | Authenticated caller lacks the `export-brand` permission (constructor Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `export-brand` (no export-specific 403 test exists — code-traced through the shared path) | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on `/api/v1/brands/export` (only GET+POST are registered; note NEITHER 405s for the other twin) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `PUT`/`PATCH`/`DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in row creation/dispatch), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `export-brand` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/brands/export`):

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

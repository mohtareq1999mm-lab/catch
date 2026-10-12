# Endpoint Name

Brands – Import Status (`GET /api/v1/brands/import/{id}`)

# Description

Returns live import progress with signal-file overlays and anti-cache headers.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/brands/import/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/import/{id}` (with `whereNumber('id')`) from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.brands.import.status` |
| Controller action | `Marvel\Http\Controllers\BrandImportController@status` (BRAND_IMPORT-scoped lookup + creator-scope for non-super-admins + `authorize('view')` policy + progress synthesis; plain `response()->json`, plus `Cache-Control: no-cache, no-store, must-revalidate` / `Pragma: no-cache` / `Expires: 0` headers — code-verified, this is a polling endpoint by design) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `import-brand` via constructor middleware (whole controller) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.brands.import.status` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes non-super-admin lookups to own rows) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (route-level `whereNumber` constraint) | Required | No | Must be numeric to match the route at all; then resolved by the BRAND_IMPORT-scoped query (plus `created_by` = caller unless super-admin), throwing `ModelNotFoundException` for unknown OR foreign ids → `Handler` 404. A foreign id is indistinguishable from a nonexistent one (anti-leak by design — code-verified scope; the subsequent `authorize('view')` policy re-check is therefore nearly unreachable) | Id of a brand-import row visible to the caller | None | None | `17` | Identifies the import operation to poll |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/brands/import/17",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: status snapshot — HTTP 200

Returned via plain `response()->json([...])` (implicit 200 with the anti-cache headers above; note: same `{status, message, success, data}` SHAPE as the `apiResponse` envelope but built manually). Verified by `test_real_brand_import_lifecycle` (`assertOk`, `data.status` equals the terminal row status, `data.successful_rows: 2`, `data.id` echo; `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Already-translated string at call time | `en` → `"Brand import status fetched successfully"`, `ar` → `"تم جلب حالة استيراد العلامات التجارية بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Synthesized snapshot (DB row + signal files) | See `data` fields below |

`data` fields (code-verified synthesis rules):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.id` | integer | Always present | Import row id (echo) | Test-verified echo |
| `data.status` | string | Always present | EFFECTIVE status: `'cancelling'` whenever a cancel signal file exists (even if the row still reads `processing`), else the row status | `pending`, `processing`, `cancelling` (synthetic), `completed`, `completed_with_errors`, `failed`, `cancelled` |
| `data.total_rows` | integer | Always present | Row `total_rows` (0 until the job counts) | E.g. `2` |
| `data.processed_rows` / `successful_rows` (note the RENAMED key — row column is `success_rows`) / `failed_rows` | integers | Always present | Live signal-file counters when present, else the row columns | `successful_rows` test-verified `2` |
| `data.progress` | number (float) | Always present | `100.0` when terminal-success (`completed*`); signal `progress` (default `0.0`) when `failed`/`cancelled`; signal `progress` (default `99.0`) when `processing` with a signal and no cancel pending; else `0.0` | Percentage scale 0–100 (code-verified formula) |
| `data.errors` | array or null | Always present | Row `errors` payload (row-level failures land here asynchronously) | Array of `{sheet, row, name_en, name_ar, error_message}` entries (shape verified in the download-errors builder) or null |
| `data.error_count` | integer | Always present | `count(errors)` when array, else `0` | E.g. `0` |
| `data.created_at` | string or null | Always present | Row creation ISO8601 | E.g. `"2026-10-10T12:00:00+00:00"` |
| `data.completed_at` | string or null | Always present | Row `updated_at` ISO8601 when terminal (`completed*`, `failed`, `cancelled`), else `null` | Null while running |

Complete success example (`en` locale; values illustrative for a finished 2-row import, keys verified):

```json
{
  "status": 200,
  "message": "Brand import status fetched successfully",
  "success": true,
  "data": {
    "id": 17,
    "status": "completed",
    "total_rows": 2,
    "processed_rows": 2,
    "successful_rows": 2,
    "failed_rows": 0,
    "progress": 100.0,
    "errors": null,
    "error_count": 0,
    "created_at": "2026-10-10T12:00:00+00:00",
    "completed_at": "2026-10-10T12:05:00+00:00"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (correct methods); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no status-specific 401 test exists; identical rejection test-verified on sibling import routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller fails the `authorize('view')` policy (super-admin OR row owner). Nearly unreachable in practice: the scoped query already 404s foreign rows before the policy runs (code-verified order) — reachable only if scoping and policy disagree | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (fixed `AuthorizationException` string WITHOUT trailing period, per `Handler` — the policy path throws, it is not the Spatie message) | None | Policy denial on a row the scope admitted (code-traced edge; no test) | See 403 example |
| 403 | Authenticated caller lacks the `import-brand` permission (constructor Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `import-brand` (no status-specific 403 test exists — code-traced through the shared path) | See 403 example (permission) |
| 404 | No visible brand-import row matches `{id}`: the scoped `findOrFail` throws `ModelNotFoundException` → `Handler` renders its `ModelNotFoundException` branch. A foreign (another user's non-super-admin-visible) id is indistinguishable from a nonexistent one | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id, foreign id, or a non-import operation id (code-traced — no status-specific 404 test exists) | See 404 example |
| 404 | Non-numeric `{id}`: the `whereNumber` constraint fails so NO route matches → `Handler` renders its `NotFoundHttpException` branch (code-verified distinct body from the lookup miss above) | `{message: string, status: boolean}` | None | `"Not Found"` (fixed string from the empty-exception-message fallback — code-traced) | None | E.g. `/brands/import/sample2` (note: the literal `sample` path has its own route and never reaches here) | See 404 example (non-numeric) |
| 405 | Any HTTP method other than `GET` on `/api/v1/brands/import/{id}` (only `Route::get` is registered; note sibling POST/GET URIs are longer and separate) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the lookup/synthesis), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Serialization/signal-read failure (code-traced, no test; signal reads are internally guarded and return nulls instead of throwing) | See 500 example |

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

405 example (wrong method, e.g. `POST /api/v1/brands/import/17`):

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

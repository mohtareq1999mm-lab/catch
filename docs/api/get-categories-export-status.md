# Endpoint Name

Categories – Export Status (`GET /api/v1/categories/export/{id}`)

# Description

Returns category-export progress without row counters, with anti-cache headers.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/categories/export/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/export/{id}` (with `whereNumber('id')`) from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.categories.export.status`, with route-level `permission:export-category` ON TOP of the constructor `export-category` gate (aligned) |
| Controller action | `Marvel\Http\Controllers\CategoryExportController@status` (CATEGORY_EXPORT-scoped lookup + creator-scope for non-super-admins + `authorize('view')` policy; plain `response()->json` plus `Cache-Control: no-cache, no-store, must-revalidate` / `Pragma: no-cache` / `Expires: 0` — code-verified polling design. Structurally identical to the brand-export status action with CATEGORY_* messages) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `export-category` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.categories.export.status` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes non-super-admin lookups to own rows) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (route-level `whereNumber` constraint) | Required | No | Must be numeric to match the route at all; then resolved by the CATEGORY_EXPORT-scoped query (plus `created_by` = caller unless super-admin), throwing `ModelNotFoundException` for unknown OR foreign ids → `Handler` 404 | Id of a category-export row visible to the caller | None | None | `44` | Identifies the export operation to poll |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/categories/export/44",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: status snapshot — HTTP 200

Returned via plain `response()->json([...])` (implicit 200 with the anti-cache headers above; same `{status, message, success, data}` SHAPE as the `apiResponse` envelope but built manually). Verified by `test_export_status_endpoint_returns_status` (completed fixture 3/3/3/0 → 200, `data.status: 'completed'`, `data.total_rows: 3`, `data.successful_rows: 3`; `getJson`, matching this route). There is exactly one success contract. NOTE the deliberate difference from import status: NO `progress` key and NO signal-file counters here — only row columns (code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Already-translated string at call time | `en` → `"Category export status fetched successfully"`, `ar` → `"تم جلب حالة تصدير الفئات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Raw row columns (no synthesis) | See `data` fields below |

`data` fields (code-verified — straight row reads):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.id` | integer | Always present | Export row id (echo) | E.g. `44` |
| `data.status` | string | Always present | EFFECTIVE status: `'cancelling'` whenever a cancel signal file exists (even if the row still reads `processing`), else the row status | `pending`, `processing`, `cancelling` (synthetic), `completed`, `completed_with_errors`, `failed`, `cancelled` |
| `data.total_rows` | integer | Always present | Row column | Test-verified `3` |
| `data.processed_rows` / `successful_rows` (renamed key — column is `success_rows`) / `failed_rows` | integers | Always present | Raw row columns (NO signal overlay here, unlike import status) | Test-verified `3`/`3`/`0` |
| `data.errors` | array or null | Always present | Row `errors` payload | As stored |
| `data.error_count` | integer | Always present | `count(errors)` when array, else `0` | E.g. `0` |
| `data.created_at` | string or null | Always present | Row creation ISO8601 | E.g. `"2026-10-10T12:00:00+00:00"` |
| `data.completed_at` | string or null | Always present | Row `updated_at` ISO8601 when terminal (`completed*`, `failed`, `cancelled`), else `null` | Null while running |

Complete success example (`en` locale; values illustrative for the test fixture, keys verified):

```json
{
  "status": 200,
  "message": "Category export status fetched successfully",
  "success": true,
  "data": {
    "id": 44,
    "status": "completed",
    "total_rows": 3,
    "processed_rows": 3,
    "successful_rows": 3,
    "failed_rows": 0,
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
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no status-specific 401 test exists; identical rejection test-verified on the sibling category-export start route) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller fails the `authorize('view')` policy or lacks the `export-category` permission — stacked gates as on the sibling actions (policy nearly unreachable after the scoped lookup; code-verified order) | `{message: string, status: boolean}` | None | Policy denial: `"This action is unauthorized"` (fixed string, no period); permission denial: translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Gate failure (code-traced — no endpoint-specific 403 test exists) | See 403 examples |
| 404 | No visible category-export row matches `{id}` (scoped lookup miss) or non-numeric `{id}` (no route match → `NotFoundHttpException` branch) | `{message: string, status: boolean}` | None | Scoped miss: `"Resource Not Found"` (fixed); non-numeric: `"Not Found"` (fixed, code-traced distinct body) | None | Unknown/foreign id, or non-numeric id | See 404 examples |
| 405 | Any HTTP method other than `GET` on `/api/v1/categories/export/{id}` (only `Route::get` is registered; note sibling POST/GET URIs are longer and separate) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the lookup/serialize), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Serialization failure (code-traced, no test) | See 500 example |

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

405 example (wrong method, e.g. `POST /api/v1/categories/export/44`):

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

# Endpoint Name

Categories – Bulk Delete Status (`GET /api/v1/categories/bulk-delete/{id}`)

# Description

Returns bulk-delete progress with a raw-key message and anti-cache headers.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/categories/bulk-delete/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/bulk-delete/{id}` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.categories.bulk-delete.status`, with route-level `permission:delete-category` ON TOP of the constructor `delete-category` gate (aligned). NOTE the authorization gap vs import/export status endpoints: NO creator-scoping, NO `authorize()` policy call — ANY holder of `delete-category` can poll ANY bulk-delete row (code-verified absence) |
| Controller action | `Marvel\Http\Controllers\CategoryController@bulkDeleteStatus` (`Import::findOrFail` by id with NO operation-type filter — a brand-import/export row id resolves here too (code-verified); progress synthesis; plain `response()->json` plus `Cache-Control: no-cache, no-store, must-revalidate` / `Pragma: no-cache` / `Expires: 0`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `delete-category` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware` — though the message below ignores locale entirely) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.categories.bulk-delete.status` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Accepted but IGNORED for the message (see Responses — raw key) | `en`, `ar` | `en` | None | `en` | No effect on this endpoint's message |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (no route-level constraint — note: NO `whereNumber` here, unlike the import/export status routes) | Required | No | Raw segment passed to unscoped `Import::findOrFail` — ANY operation row resolves (brand/category/bulk-delete alike); unknown ids throw `ModelNotFoundException` → `Handler` 404 | Id of any import row | None | None | `51` | Identifies the operation to poll |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/categories/bulk-delete/51",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: status snapshot — HTTP 200

Returned via plain `response()->json([...])` (implicit 200 with the anti-cache headers above). Verified by `test_status_endpoint_returns_progress` in `CategoryBulkDeleteTest` (exact assertions unread in this pass — cited by name; uses `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | THE RAW CONSTANT VALUE — `'message' => CATEGORY_BULK_DELETE_STATUS_FETCHED` passes the PHP constant (`'MESSAGE.CATEGORY_BULK_DELETE_STATUS_FETCHED'`), NOT a translation call (code-verified wart — clients receive the literal key path in EVERY locale; contrast the cancel endpoint, which translates properly) | Always `"MESSAGE.CATEGORY_BULK_DELETE_STATUS_FETCHED"` verbatim |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Synthesized snapshot (DB row + signal files) | See `data` fields below (same synthesis rules as brand import status, minus the `progress` key — code-verified absence: this payload has NO `progress` percentage) |

`data` fields (code-verified):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.id` | integer | Always present | Operation row id (echo) | E.g. `51` |
| `data.status` | string | Always present | EFFECTIVE status: `'cancelling'` whenever a cancel signal file exists, else the row status | `pending`, `processing`, `cancelling` (synthetic), `completed`, `completed_with_errors`, `failed`, `cancelled` |
| `data.total_rows` | integer | Always present | Row column (deduped count at creation) | E.g. `2` |
| `data.processed_rows` / `successful_rows` (renamed — column is `success_rows`) / `failed_rows` | integers | Always present | Live signal counters when present, else row columns | E.g. `2`/`2`/`0` on completion (job-level test-verified) |
| `data.errors` | array or null | Always present | Row `errors` payload (e.g. `{category_id}` entries for skipped parents — job-level test-verified shape) | Array or null |
| `data.error_count` | integer | Always present | `count(errors)` when array, else `0` | E.g. `1` |
| `data.created_at` | string or null | Always present | Row creation ISO8601 | E.g. `"2026-10-10T12:00:00+00:00"` |
| `data.completed_at` | string or null | Always present | Row `updated_at` ISO8601 when terminal, else `null` | Null while running |

Complete success example (keys verified; message is the VERBATIM raw key):

```json
{
  "status": 200,
  "message": "MESSAGE.CATEGORY_BULK_DELETE_STATUS_FETCHED",
  "success": true,
  "data": {
    "id": 51,
    "status": "completed",
    "total_rows": 2,
    "processed_rows": 2,
    "successful_rows": 2,
    "failed_rows": 0,
    "errors": null,
    "error_count": 0,
    "created_at": "2026-10-10T12:00:00+00:00",
    "completed_at": "2026-10-10T12:05:00+00:00"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200 contract is test-verified (`getJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no status-specific 401 test exists; identical rejection test-verified on sibling category routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `delete-category` permission (BOTH route and constructor gates require it) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-category` (no status-specific 403 test exists; the cancel sibling's 403 tests prove the gate — identical path) | See 403 example |
| 404 | No import row matches `{id}`: unscoped `findOrFail` throws `ModelNotFoundException` → `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id (code-traced — no status-specific 404 test exists) | See 404 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/categories/bulk-delete/{id}` (only `Route::get` is registered; note sibling POST URIs are longer and separate) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the lookup/synthesis), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Serialization/signal-read failure (code-traced, no test; signal reads are internally guarded and return nulls instead of throwing) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `delete-category` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{id}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/categories/bulk-delete/51`):

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

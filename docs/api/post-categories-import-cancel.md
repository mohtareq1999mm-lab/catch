# Endpoint Name

Categories – Cancel Import (`POST /api/v1/categories/import/{id}/cancel`)

# Description

Requests cancellation of a running category import and reports the terminal state.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/categories/import/{id}/cancel` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/import/{id}/cancel` (with `whereNumber('id')`) from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.categories.import.cancel`, with route-level `permission:import-category` ON TOP of the constructor `import-category` gate (aligned) |
| Controller action | `Marvel\Http\Controllers\CategoryImportController@cancel` (structurally identical to the brand-import cancel: scoped lookup + creator-scope + `authorize('cancel')` policy; terminal rows rejected with 409; otherwise cancel-signal file + broadcast + best-effort row flip to `cancelled`. Shared generic cancel/success messages with brands — only the start/status messages are category-specific) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `import-category` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.categories.import.cancel` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes non-super-admin lookups to own rows) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (route-level `whereNumber` constraint) | Required | No | Must be numeric to match the route at all; then resolved by the CATEGORY_IMPORT-scoped query (plus `created_by` = caller unless super-admin), throwing `ModelNotFoundException` for unknown OR foreign ids → `Handler` 404 | Id of a category-import row visible to the caller | None | None | `31` | Identifies the import operation to cancel |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/categories/import/31/cancel",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: cancellation recorded — HTTP 200

Returned via `ApiResponse::apiResponse(__('message.MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY'), 200, true, ['import_id' => ..., 'status' => 'cancelled'])` after writing the cancel signal file, broadcasting cancelling→cancelled events, and best-effort flipping the row to `cancelled` (conditional update limited to `pending`/`processing`; a concurrent terminal flip yields the 409 below instead — code-verified race handling; `QueryException` during the flip is only `report()`ed, not fatal). No endpoint-specific success test exists (the identical terminal-state path is test-verified on the sibling brand-import cancel route; all other expectations below are code-verified). There is exactly one success contract. Note the response claims `'status' => 'cancelled'` unconditionally after the signal write — the WORKER may still be winding down.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Already-translated string at call time (SHARED generic message, not category-specific) | `en` → `"Import cancelled successfully"`, `ar` → `"تم إلغاء الاستيراد بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `{import_id, status}` — the row id plus the literal string `'cancelled'` (not re-read from the row) | `import_id`: integer; `status`: always `"cancelled"` here |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Import cancelled successfully",
  "success": true,
  "data": {
    "import_id": 31,
    "status": "cancelled"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. No test covers this endpoint: 401/403/409 expectations are verified against the shared middleware/policy/terminal-state paths exercised by named tests on sibling import routes (cited per row); all other rows are code-verified or code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (identical rejection test-verified on the sibling category-import start route) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller fails the `authorize('cancel')` policy or lacks the `import-category` permission — stacked gates as on the sibling actions (policy nearly unreachable after the scoped lookup; code-verified order) | `{message: string, status: boolean}` | None | Policy denial: `"This action is unauthorized"` (fixed string, no period); permission denial: translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Gate failure (code-traced — no endpoint-specific 403 test exists) | See 403 examples |
| 404 | No visible category-import row matches `{id}` (scoped lookup miss) or non-numeric `{id}` (no route match → `NotFoundHttpException` branch) | `{message: string, status: boolean}` | None | Scoped miss: `"Resource Not Found"` (fixed); non-numeric: `"Not Found"` (fixed, code-traced distinct body) | None | Unknown/foreign id, or non-numeric id | See 404 examples |
| 405 | Any HTTP method other than `POST` on this exact URI (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | The row is already terminal (`completed`, `completed_with_errors`, `failed`, `cancelled`) — checked BEFORE the signal write AND re-checked after the conditional flip (concurrent-completion race) | `{status: integer, message: string, success: boolean}`, no `data` key | None | Already-translated string at call time: `en` → `"Import cannot be cancelled in its current state"`, `ar` → `"لا يمكن إلغاء الاستيراد في حالته الحالية"` (SHARED generic message) | None | Terminal row (identical terminal-state path test-verified on the sibling brand-import cancel route for a completed fixture) | See 409 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the cancel flow outside the guarded flip), rendered by the generic branch of `Handler` (the method has no outer try/catch; the flip's `QueryException` is only reported) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Infrastructure failure (code-traced, no test) | See 500 example |

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

403 example (permission — authenticated without `import-category`; `en` locale):

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

405 example (wrong method, e.g. `GET /api/v1/categories/import/31/cancel`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (terminal row; `en` locale):

```json
{
  "status": 409,
  "message": "Import cannot be cancelled in its current state",
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

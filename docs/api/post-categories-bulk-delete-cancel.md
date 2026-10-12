# Endpoint Name

Categories – Cancel Bulk Delete (`POST /api/v1/categories/bulk-delete/{id}/cancel`)

# Description

Requests cancellation of a running bulk delete and reports the cancelling state.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/categories/bulk-delete/{id}/cancel` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/bulk-delete/{id}/cancel` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.categories.bulk-delete.cancel`, with route-level `permission:delete-category` ON TOP of the constructor `delete-category` gate (aligned — both require the same permission) |
| Controller action | `Marvel\Http\Controllers\CategoryController@cancelBulkDelete` (UNSCOPED `findOrFail` — any operation row by id, no type filter, no creator check, no policy call (code-verified absences — contrast the import/export cancels); terminal rows rejected with 409; otherwise cancel-signal file written and `'cancelling'` reported WITHOUT flipping the row — the worker observes the signal) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `delete-category` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.categories.bulk-delete.cancel` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (no route-level constraint — note: NO `whereNumber` here, unlike the import/export status routes) | Required | No | Raw segment passed to UNSCOPED `Import::findOrFail` — ANY operation row (bulk-delete, import, export) resolves; unknown ids throw `ModelNotFoundException` → `Handler` 404 | Id of any import row | None | None | `51` | Identifies the operation to cancel |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/categories/bulk-delete/51/cancel",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: cancellation recorded — HTTP 200

Returned via `ApiResponse::apiResponse(__('message.MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY'), 200, true, ['bulk_delete_id' => ..., 'status' => 'cancelling'])` after writing the cancel signal file (NO row flip — the row keeps its running status until the worker observes the signal; code-verified difference from import/export cancels, which flip immediately). Verified by `test_cancel_processing_bulk_delete_writes_signal_and_reports_cancelling` (`assertOk`, `success: true`, `data.bulk_delete_id` echo, `data.status: 'cancelling'`, signal file EXISTS afterwards, and the status endpoint subsequently reports `'cancelling'`; uses `postJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Already-translated string at call time (SHARED generic message, not bulk-delete-specific) | `en` → `"Import cancelled successfully"`, `ar` → `"تم إلغاء الاستيراد بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `{bulk_delete_id, status}` — the row id plus the literal string `'cancelling'` (a CLAIM about the worker's next observation, not the row state — code-verified) | `bulk_delete_id`: integer; `status`: always `"cancelling"` here |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Import cancelled successfully",
  "success": true,
  "data": {
    "bulk_delete_id": 51,
    "status": "cancelling"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403/404/409 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no cancel-specific 401 test exists; identical rejection test-verified on sibling category routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `delete-category` permission (BOTH route and constructor gates require it) — AND the rejection is side-effect-free by test (no signal file written, row untouched) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-category`; verified by `test_regression_cat001_user_without_permission_is_rejected_with_no_side_effects` (403 + `status: false` + no signal file + row still `processing`) and `test_view_only_permission_cannot_cancel_bulk_delete` (403 + no signal) | See 403 example |
| 404 | No import row matches `{id}`: unscoped `findOrFail` throws `ModelNotFoundException` → `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id; verified by `test_cancel_nonexistent_bulk_delete_returns_404` expecting 404 for `999999` | See 404 example |
| 405 | Any HTTP method other than `POST` on this exact URI (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | The row is already terminal (`completed`, `completed_with_errors`, `failed`, `cancelled`) — checked BEFORE any signal write, so terminal cancels leave NO signal file (code-verified ordering) | `{status: integer, message: string, success: boolean}`, no `data` key | None | Already-translated string at call time: `en` → `"Import cannot be cancelled in its current state"`, `ar` → `"لا يمكن إلغاء الاستيراد في حالته الحالية"` (SHARED generic message) | None | Terminal row; verified by `test_cancel_terminal_bulk_delete_returns_409_and_no_signal` (completed fixture → 409 + no signal file) | See 409 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the cancel flow), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Infrastructure failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (permission-less user, side-effect-free; `en` locale):

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

405 example (wrong method, e.g. `GET /api/v1/categories/bulk-delete/51/cancel`):

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

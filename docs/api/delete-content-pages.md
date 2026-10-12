# Endpoint Name

Content Pages – Delete Page (`DELETE /api/v1/content-pages/{content_page}`)

# Description

Permanently hard-deletes a content page and returns a confirmation without data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/content-pages/{content_page}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `content-pages/{content_page}` from `Route::apiResource('content-pages', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\ContentPageController@destroy` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-content-pages` via controller constructor `permission:delete-content-pages` middleware (applies only to `destroy`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `content-pages.destroy` (apiResource default naming) |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the deletion.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `content_page` | path | integer id (implicit route-model binding by primary key — no `getRouteKeyName` override) | Required | No | Resolved by Laravel before the method runs; unknown ids throw `ModelNotFoundException` → `Handler` 404 (the method has no try/catch at all) | Id of an existing page | None | None | `4` | Identifies the page to delete |

There are no query parameters, body fields, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

Valid request deleting a page:

```json
{
  "method": "DELETE",
  "url": "/api/v1/content-pages/4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page hard-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(DELETE_DATA_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_admin_can_delete_content_page` (`assertOk`, `success: true`, `assertDatabaseMissing('content_pages', ['id' => ...])` — proving HARD deletion: the model uses no `SoftDeletes` trait; uses `deleteJson`, matching this route). There is exactly one success contract. Attached sections are NOT deleted — their `content_page_id` is set to NULL by the FK `nullOnDelete` rule (migration-verified: `2026_06_01_135945`), so they become unattached but survive.

Complete success example:

```json
{
  "status": 200,
  "message": "Data deleted successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETE_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data deleted successfully"`, `ar` → `"تم حذف البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403/404 contracts are test-verified (all use `deleteJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_delete_content_page` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `delete-content-pages` permission (Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-content-pages`; verified by `test_user_without_delete_content_pages_gets_forbidden` expecting 403 | See 403 example |
| 404 | No page matches `{content_page}`: implicit binding throws `ModelNotFoundException` before the method runs → `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id (verified by `test_delete_content_page_returns_404_for_nonexistent` for id `9999`); slug string instead of id (code-traced — binding is by primary key) | See 404 example |
| 405 | `POST` on `/api/v1/content-pages/{content_page}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `GET` to show, and the two suffixed POST/PATCH URIs are separate actions) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with an id segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the delete), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `delete-content-pages` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{content_page}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/content-pages/4`):

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

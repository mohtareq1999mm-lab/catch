# Endpoint Name

Static Pages – Delete Section (`DELETE /api/v1/static-pages/{static_page:slug}/sections/{static_section}`)

# Description

Deletes a page-owned section with its media and returns a confirmation without data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/static-pages/{slug}/sections/{id}` (page binds on `slug`; section binds on id) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `static-pages/{static_page:slug}/sections/{static_section}` from the static-pages group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\StaticPageController@destroySection` → `StaticPageService::deleteSection` (ownership check → media cleared explicitly → hard delete; media files removed by the Media Library on model delete) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-static-sections` via controller constructor `permission:delete-static-sections` middleware (applies only to `destroySection`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the deletion. Both bindings resolve before the method runs.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `static_page` | path | slug string (implicit route-model binding on the `slug` column) | Required | No | Resolved by Laravel before the method runs; unknown slugs throw `ModelNotFoundException` → `Handler` 404 | An existing page `slug` | None | None | `about-us` | Owning page, by slug |
| `static_section` | path | integer id (implicit route-model binding by primary key) | Required | No | Resolved by Laravel before the method runs — then RE-CHECKED against the page by the service (`assertSectionBelongsToPage`): a section owned by ANOTHER page throws `ModelNotFoundException` → the method's catch returns the 404 ENVELOPE (anti-leak; code-verified) | Id of a section belonging to this page | None | None | `21` | Section to delete, by id |

There are no query parameters, body fields, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/static-pages/about-us/sections/21",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: section hard-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(STATIC_SECTION_DELETED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_delete_section_permission_only_allows_delete` (`assertOk` on this URI; `deleteJson`, matching this route) and `test_full_permissions_allows_all_admin_operations` (same). There is exactly one success contract. Observable effects (code-verified): both media collections cleared explicitly first (files removed even if the DB cascade were interrupted), then the row hard-deleted (`StaticSection` uses no `SoftDeletes` trait). Frontend `static_pages` cache tag flushed.

Complete success example:

```json
{
  "status": 200,
  "message": "Static section deleted successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.STATIC_SECTION_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Static section deleted successfully"`, `ar` → `"تم حذف قسم الصفحة الثابتة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified (all use `deleteJson`); all other rows are code-verified or code-traced as marked. Note the two 404 shapes: binding misses use the Handler fixed string, ownership misses use the 404 envelope.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token (no delete-section-specific 401 test exists; identical rejection test-verified on sibling static routes) | See 401 example |
| 403 | Authenticated caller lacks the `delete-static-sections` permission (Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-static-sections` (no delete-section-specific 403 test exists; identical path test-verified on sibling static routes) | See 403 example |
| 404 | Unknown page slug OR unknown section id: implicit binding throws `ModelNotFoundException` before the method runs → `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown slug or section id | See 404 example (binding) |
| 404 | Section exists but belongs to ANOTHER page: `assertSectionBelongsToPage` throws `ModelNotFoundException`, caught by the method → `apiResponse(NOT_FOUND, 404, false)` envelope (anti-leak by design — indistinguishable from a true miss) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — no test exercises cross-page access) | None | Valid ids with mismatched ownership | See 404 example (ownership) |
| 405 | `POST`, `PUT`, or `PATCH` on `/api/v1/static-pages/{slug}/sections/{id}` (no such routes are registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure other than the caught `ModelNotFoundException` (any exception: media clear, delete), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `delete-static-sections` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (binding miss — unknown slug or section id):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404 example (ownership miss — section of another page; `en` locale):

```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

405 example (wrong method, e.g. `POST /api/v1/static-pages/about-us/sections/21`):

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

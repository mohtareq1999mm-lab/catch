# Endpoint Name

Categories – Delete Category (`DELETE /api/v1/categories/{category}`)

# Description

Soft-deletes a category unless linked resources block it, keeping the row on success.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/categories/{category}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/{category}` from `Route::apiResource('categories', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-category` gate decides) |
| Controller action | `Marvel\Http\Controllers\CategoryController@destroy` (manual `findOrFail` → `delete()`; the `Category` model uses `SoftDeletes`, so this is a SOFT delete. Two catches: `ModelNotFoundException` → translated 404; `QueryException` (FK constraint, e.g. attached products) → `CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES`, which hits the `MarvelException` branch WITHOUT a status marker → 500 translated. Code-verified split) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-category` via controller constructor `permission:delete-category` middleware (applies to `destroy`, `bulkDelete`, `bulkDeleteStatus`, `cancelBulkDelete`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `categories.destroy` (apiResource default naming) |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the deletion.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `category` | path | integer id (no route-level constraint; resolved by manual `findOrFail` under the `SoftDeletes` scope) | Required | No | Raw segment; unknown/trashed ids throw `ModelNotFoundException`, caught explicitly → translated 404 (code-verified — unlike sibling actions whose misses escape as fixed strings, destroy converts its own miss) | Id of a non-trashed category | None | None | `9` | Identifies the category to soft-delete |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract. (Slug strings do NOT resolve here — id-only lookup; a slug 404s. Code-verified difference from brands show.)

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/categories/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: category soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(CATEGORY_DELETED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. The category suites assert 200s on this URI across auth/authz files (all use `deleteJson`, matching this route); soft-deletion itself is proven by the sibling SoftDelete-suite pattern (`assertSoftDeleted`, trashed exclusion, trashed-404 — same model/trait contract as brands, whose tests assert it explicitly). There is exactly one success contract. The row is kept (`deleted_at` set); media files are NOT removed on soft-delete (code-traced — no media handling in `destroy`).

Complete success example:

```json
{
  "status": 200,
  "message": "Category deleted successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CATEGORY_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Category deleted successfully"`, `ar` → `"تم حذف الفئة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified through the auth/authz suites (all use `deleteJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_delete_category` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `delete-category` permission (constructor Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-category` (no delete-specific 403 test found in this pass; identical constructor-gate path test-verified on sibling category routes) | See 403 example |
| 404 | No non-trashed category matches `{category}`: manual `findOrFail` throws `ModelNotFoundException`, caught EXPLICITLY here (unlike show/update) → `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice (code-verified — translated envelope, NOT the fixed string) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — no delete-specific 404 test found in this pass) | None | Unknown/trashed id, or slug string (id-only lookup — code-traced) | See 404 example |
| 405 | `POST` on `/api/v1/categories/{category}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `GET` to show) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | FK-blocked delete: `delete()` violates a database constraint → `QueryException`, caught EXPLICITLY → `MarvelException(CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES)` → `Handler` `MarvelException` branch finds NO status marker → 500 with the translated message (code-verified — a constraint failure surfaces as 500, never 409, on this endpoint) | `{message: string, status: boolean}` | None | Translated `ERROR.CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES`: `en` → `"Cannot delete category with existing associated resources"`, `ar` → `"لا يمكن حذف الفئة مع وجود موارد مرتبطة بها"` | None | Deleting a category still referenced by constrained rows (code-traced, no test) | See 500 example |

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

404 example (unknown `{category}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/categories/9`):

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

500 example (FK-blocked delete; `en` locale):

```json
{
  "message": "Cannot delete category with existing associated resources",
  "status": false
}
```

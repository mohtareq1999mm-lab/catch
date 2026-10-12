# Endpoint Name

Content Pages – Create Page (`POST /api/v1/content-pages`)

# Description

Creates an active content page, deriving its slug from the English title.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/content-pages` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `content-pages` from `Route::apiResource('content-pages', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\ContentPageController@store` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-content-pages` via controller constructor `permission:create-content-pages` middleware (applies only to `store`); the `StoreContentPageRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `content-pages.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`StoreContentPageRequest` validation runs before the method body (`failedValidation` rethrows → 422). Only `title` is read; `slug` is derived and `is_active` forced `true`. Any extra body fields (e.g. `id`, `slug`, `is_active`) are silently ignored — mass-assignment test-verified (`id: 99999` sent → 201, row absent).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `title` | body | object (associative array of locale → string) | Required | No | `required\|array` | Any object whose values each satisfy the per-locale rules | None | None | `{"en": "New Page", "ar": "صفحة جديدة"}` | Localized page titles (stored as translations; DB `title` column is UNIQUE — but uniqueness here is enforced per-locale by the rule below, not as a whole-JSON value) |
| `title.en` | body | string | Required | No | `required\|string\|max:30` | Any string up to 30 chars | None | None | `"New Page"` | English title — also the slug source (`Str::slug`) |
| `title.*` | body (each locale value) | string | Required for `en`; optional for other locales (`nullable`) | Yes (non-`en` locales) | `nullable\|string\|max:30` + `UniqueTranslationRule::for('content_pages', 'title')` (each locale value must be unique among stored translations; exact vendor message text **Unverified in source** — the suite stubs this rule to always pass, so duplicate-title behavior is untested here) | Any unique string per locale, ≤30 chars | None | None | `"صفحة جديدة"` | One locale's title |

There are no path parameters, query parameters, multipart fields, or file uploads. Slug derivation (code-verified): `Str::slug($title['en'])` — e.g. `"New Page"` → `"new-page"` (test-verified in DB). Edge case (code-traced): distinct titles slugifying identically (e.g. `"Hello World"` vs `"hello-world"`) pass validation but violate the `slug` UNIQUE constraint → uncaught `QueryException` → 409 (the method has no try/catch).

## Request examples

Valid request creating a page:

```json
{
  "method": "POST",
  "url": "/api/v1/content-pages",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "title": {
      "en": "New Page",
      "ar": "صفحة جديدة"
    }
  }
}
```

# Responses

## Success: page created — HTTP 201

Returned via `ApiResponse::apiResponse(CREATE_DATA_SUCCESSFULLY, 201, true, ContentPageResource::make($page))`. Verified by `test_admin_can_create_content_page` (`assertStatus(201)`, `success: true`, `assertDatabaseHas('content_pages', ['slug' => 'new-page'])`; uses `postJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CREATE_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data created successfully"`, `ar` → `"تم إنشاء البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created page serialized by `App\Http\Resources\Pages\ContentPageResource` | `id`, `title` (string in the request locale — resolved via app locale, not the raw translations object), `slug` (derived), `is_active: true` (forced); `sections` key ABSENT — the relation is never loaded on the fresh model (code-verified) |

Complete success example (`en` locale; `id` illustrative, all other values verified):

```json
{
  "status": 201,
  "message": "Data created successfully",
  "success": true,
  "data": {
    "id": 9,
    "title": "New Page",
    "slug": "new-page",
    "is_active": true
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 201/401/403/422 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_create_content_page` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `create-content-pages` permission (Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-content-pages`; verified by `test_user_without_create_content_pages_gets_forbidden` expecting 403 | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/content-pages` path (no such routes are registered; note `GET /content-pages` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 409 | Database constraint failure during `create()` (e.g. a `slug` UNIQUE collision from distinct titles slugifying identically — validation cannot catch it since titles differ): the method has no try/catch, so a `QueryException` escapes to `Handler`'s `QueryException` branch; conditional on database state | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Only when persisting violates a database constraint (code-traced, no test) | See 409 example |
| 422 | Any `StoreContentPageRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `title` / `title.{locale}` → message arrays (key verified from rule targets; exact messages **Unverified in source** — the suite stubs `UniqueTranslationRule` to always pass) | Missing/non-array `title`, missing/non-string/over-30 `title.en` (verified trigger: `'not-an-array'` → 422), over-30 or duplicate locale value | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure other than a `QueryException` (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-content-pages` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/content-pages`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (slug unique collision):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

422 example (`title` sent as a string instead of an object):

```json
{
  "message": "The title must be an array.",
  "status": false,
  "errors": {
    "title": [
      "The title must be an array."
    ]
  }
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

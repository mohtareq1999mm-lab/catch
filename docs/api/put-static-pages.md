# Endpoint Name

Static Pages – Update Page (`PUT /api/v1/static-pages/{static_page:slug}`)

# Description

Updates a static page's titles and active flag by slug, flushing the storefront cache.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — no `PATCH` twin, unlike `apiResource` updates) |
| Full path | `/api/v1/static-pages/{slug}` (the `{static_page:slug}` placeholder binds on the `slug` column) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `static-pages/{static_page:slug}` from the static-pages group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\StaticPageController@update` → `StaticPageService::updatePage` (persists `title` + `is_active` only; page identity never changes through this path, per the service comment) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-static-pages` via controller constructor `permission:update-static-pages` middleware (applies only to `update`); the `UpdateStaticPageRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`. Implicit binding resolves `{static_page:slug}` before validation, so an unknown slug 404s even with an invalid body (code-verified order).

## Request inputs

`UpdateStaticPageRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional (`sometimes`) — an empty body passes validation and returns 200 with no changes (code-traced). Only `title` and `is_active` are forwarded (`$request->only(...)`); any extra body fields (notably `slug`) are silently ignored — the slug can never change here.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `static_page` | path | slug string (implicit route-model binding on the `slug` column) | Required | No | Resolved by Laravel before validation; unknown slugs throw `ModelNotFoundException` → `Handler` 404 | An existing page `slug` | None | None | `about-us` | Identifies the page to update, by slug |
| `title` | body | object (associative array of locale → string) | Optional | No | `sometimes\|array`, plus per-locale `title.*`: `sometimes\|string\|max:255` (no uniqueness rule — titles are NOT unique-checked here at all; code-verified) | Object with each supplied locale a string ≤255 chars | Absent (unchanged) | None | `{"en": "About Us v2"}` | Replacement translations (merged per locale) |
| `is_active` | body | integer 0/1 | Optional | No | `sometimes\|in:0,1` (strict `in` — JSON `true`/`false` FAIL; code-traced) | `0`, `1` | Absent (unchanged) | None | `0` | New active flag |

There are no query parameters, multipart fields, or file uploads.

Observable effect (code-verified): the row is updated AND the frontend `static_pages` cache tag is flushed explicitly (`flushTag` in the method; model observers flush it again on the save event — harmless double invalidation).

## Request examples

Valid request renaming a page (test-verified call shape):

```json
{
  "method": "PUT",
  "url": "/api/v1/static-pages/about-us",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "title": { "en": "X" }
  }
}
```

# Responses

## Success: page updated — HTTP 200

Returned via `ApiResponse::apiResponse(STATIC_PAGE_UPDATED_SUCCESSFULLY, 200, true, StaticPageResource::make($page))` with sections+media loaded. Verified by `test_update_page_permission_only_allows_update` (`assertOk` on the slug URI with `update-static-pages`; uses `putJson`, matching this route) and `test_full_permissions_allows_all_admin_operations` (same). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.STATIC_PAGE_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Static page updated successfully"`, `ar` → `"تم تحديث الصفحة الثابتة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated page serialized by `App\Http\Resources\StaticPage\StaticPageResource` | `id`, `slug` (UNCHANGED — never written), `title` (locale string), `is_active` (bool), `sections` (array — always present) |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Static page updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "slug": "about-us",
    "title": "X",
    "is_active": true,
    "sections": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_cannot_update_page` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-static-pages` permission (Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-static-pages`; verified by `test_view_permission_allows_index_and_show_but_blocks_mutations` (PUT → 403) and `test_create_section_permission_only_allows_store` (PUT → 403) | See 403 example |
| 404 | No page has `slug = {static_page}`: implicit binding throws `ModelNotFoundException` before validation runs → `Handler` renders its `ModelNotFoundException` branch (takes precedence over 422 — code-verified order) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown slug (code-traced — no update-specific 404 test exists) | See 404 example |
| 405 | `POST`, `PATCH`, or `DELETE` on `/api/v1/static-pages/{slug}` (no such routes are registered; note suffixed POST URIs are separate actions) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `UpdateStaticPageRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `title` / `title.{locale}` / `is_active` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no update-specific 422 test exists) | Non-array `title`, non-string/over-255 locale value, non-0/1 `is_active` (including JSON booleans — code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in update/serialize), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-static-pages` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown slug):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/static-pages/about-us`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (non-0/1 `is_active`):

```json
{
  "message": "The selected is active is invalid.",
  "status": false,
  "errors": {
    "is_active": [
      "The selected is active is invalid."
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

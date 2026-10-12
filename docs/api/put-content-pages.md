# Endpoint Name

Content Pages – Update Page (`PUT|PATCH /api/v1/content-pages/{content_page}`)

# Description

Updates a page's titles and active flag, returning the page with sections loaded.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`; tests use `putJson`) |
| Full path | `/api/v1/content-pages/{content_page}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `content-pages/{content_page}` from `Route::apiResource('content-pages', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\ContentPageController@update` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-content-pages` via controller constructor `permission:update-content-pages` middleware (applies to `update`, `attachSections`, `toggleActive`); the `UpdateContentPageRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `content-pages.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`UpdateContentPageRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional (`sometimes`) — an empty body passes validation and returns 200 with only timestamps touched (code-traced). Only `title` and `is_active` are persisted (`$request->only(...)`); the lookup uses a MANUAL `find`, not binding (code-verified difference from show/destroy/toggle).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `content_page` | path | integer id | Required | No | No route-level constraint; resolved by manual `ContentPage::find($id)` — a MISS returns the `apiResponse` 404 envelope (NOT the Handler shape; code-verified difference from binding-based endpoints) | Id of an existing page | None | None | `4` | Identifies the page to update |
| `title` | body | object (associative array of locale → string) | Optional | No | `sometimes\|array` | Any object whose values each satisfy the per-locale rules | Absent (unchanged) | None | `{"en": "Renamed Page"}` | Replacement translations (merged by the translations trait — supplied locales overwritten, omitted locales kept; code-traced trait semantics) |
| `title.*` | body (each locale value) | string | Optional per locale | No | `sometimes`, `string`, `max:30`, `UniqueTranslationRule::for('content_pages', 'title')->ignore($this->route('content_page'))` (self excluded via the CORRECT `{content_page}` parameter name — code-verified, unlike the users-update ignore bug) | Any string ≤30 chars, unique across other pages | Absent (unchanged) | None | `"Renamed Page"` | One locale's new title |
| `is_active` | body | integer 0/1 | Optional | No | `sometimes\|in:0,1` (note: strict `in`, so JSON `true`/`false` FAIL — code-traced) | `0`, `1` | Absent (unchanged) | None | `0` | New active flag |

There are no query parameters, multipart fields, or file uploads. Any extra body fields are ignored. The slug is NOT recomputed on title change (code-verified — `update` never touches `slug`, so renaming orphans the slug semantics).

## Request examples

Valid request deactivating a page (the test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/content-pages/4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "is_active": 0
  }
}
```

# Responses

## Success: page updated — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ContentPageResource::make($content_page))` after persisting and loading `sections` with `sectionType.settings`. Verified by `test_admin_can_update_content_page` (`assertOk`, `success: true`, refreshed `is_active === false`; uses `putJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data updated successfully"`, `ar` → `"تم تحديث البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated page serialized by `App\Http\Resources\Pages\ContentPageResource` | `id`, `title` (string in the request locale — resolved via app locale, not the raw translations object), `slug` (UNCHANGED — never recomputed), `is_active` (bool), `sections` (array — always present here since the relation is loaded) |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Data updated successfully",
  "success": true,
  "data": {
    "id": 4,
    "title": "Test Page",
    "slug": "test-page",
    "is_active": false,
    "sections": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/404/422 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked. Note the two different 404-capable paths: manual-find 404 uses the `apiResponse` envelope; anything else uses the Handler shape.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_update_content_page` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-content-pages` permission (Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-content-pages`; verified by `test_user_without_update_content_pages_gets_forbidden` expecting 403 | See 403 example |
| 404 | No page matches `{content_page}`: manual `find` returns null → `apiResponse(NOT_FOUND, 404, false)` envelope (code-verified — translated notice inside the controller envelope, NOT the Handler fixed string) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — the test asserts status only) | None | Unknown id; verified by `test_update_content_page_returns_404_for_nonexistent` expecting 404 for id `9999` with a valid body (proving the lookup precedes validation effects — body was valid) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/content-pages/{content_page}` (no such routes are registered; note `GET` is the separate show endpoint and the two suffixed POST/PATCH URIs are separate actions) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`DELETE` with an id segment (code-traced) | See 405 example |
| 422 | Any `UpdateContentPageRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `title` / `title.{locale}` / `is_active` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no update-specific 422 test exists) | Non-array `title`, non-string/over-30 locale value, duplicate locale value on ANOTHER page (self excluded), non-0/1 `is_active` (including JSON booleans — code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in find/update/serialize), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-content-pages` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{content_page}`; `en` locale):

```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/content-pages/4`):

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

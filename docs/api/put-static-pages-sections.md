# Endpoint Name

Static Pages – Update Section (`PUT /api/v1/static-pages/{static_page:slug}/sections/{static_section}`)

# Description

Partially updates a page-owned section with media replace/clear semantics and anti-leak ownership checks.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — no `PATCH` twin) |
| Full path | `/api/v1/static-pages/{slug}/sections/{id}` (page binds on `slug`; section binds on id) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `static-pages/{static_page:slug}/sections/{static_section}` from the static-pages group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\StaticPageController@updateSection` → `StaticPageService::updateSection` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-static-sections` via controller constructor `permission:update-static-sections` middleware (applies to `updateSection`, `reorderSections`); the `UpdateStaticSectionRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json`, plus `multipart/form-data` when uploading `media` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`. Both bindings resolve before validation, so an unknown slug/id 404s even with an invalid body (code-verified order).

## Request inputs

`UpdateStaticSectionRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional — an empty body passes validation and returns 200 with no changes (code-traced; the service skips the update when `$data` is effectively empty).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json`, or `multipart/form-data` for uploads | None | None | `application/json` | Declares body encoding |
| `static_page` | path | slug string (implicit route-model binding on the `slug` column) | Required | No | Resolved by Laravel before validation; unknown slugs throw `ModelNotFoundException` → `Handler` 404 | An existing page `slug` | None | None | `about-us` | Owning page, by slug |
| `static_section` | path | integer id (implicit route-model binding by primary key) | Required | No | Resolved by Laravel before validation — then RE-CHECKED against the page by the service (`assertSectionBelongsToPage`): a section owned by ANOTHER page throws `ModelNotFoundException` → the method's catch returns the 404 ENVELOPE (anti-leak: existence is never confirmed cross-page; code-verified) | Id of a section belonging to this page | None | None | `21` | Section to update, by id |
| `type` | body | string | Optional | No | `sometimes\|string\|in:text,image,video,screenshot` | Any known type | Absent (type unchanged) | None | `"text"` | New discriminator (media rules resolve against the incoming type when present, else the stored type) |
| `title` | body | object (associative array of locale → string) | Optional | No | `sometimes\|array`, per-locale `title.*`: `sometimes\|nullable\|string\|max:255` | Object with supplied locales nullable strings ≤255 | Absent (unchanged) | None | `{"en": "Y"}` | Replacement translations (test-verified change) |
| `content` | body | object (associative array of locale → array) | Optional | No | `sometimes\|array`, `content.en`/`content.ar`: `nullable\|array`; after-hook: supplied-but-list-shaped content rejected with `STATIC_SECTION_CONTENT_INVALID`; switching type to `text` with explicitly EMPTY content rejected as required | Locale-keyed objects (never a list) | Absent (unchanged) | None | `{"en": {"heading": "Hi"}}` | Replacement content payloads |
| `config` | body | object/array | Optional | Yes (`nullable`) | `sometimes\|nullable\|array` | Any array or null | Absent (unchanged) | None | `{"align": "center"}` | Replacement config JSON |
| `is_active` | body | boolean/integer | Optional | Yes (`nullable`) | `sometimes\|nullable\|in:0,1,true,false,TRUE,FALSE` (service re-parses via `parseBoolean`, so spellings persist as real booleans — code-verified) | `0`/`1`/spellings/null | Absent (unchanged) | None | `1` | New active flag |
| `remove_media` | body | boolean/integer | Optional | Yes (`nullable`) | `sometimes\|nullable\|in:0,1,true,false,TRUE,FALSE` | Truthy clears all media (no new file); falsy/absent keeps | Absent (keep) | None | `1` | Media wipe flag (consumed by service, never persisted) |
| `media` | body (file) | upload | Conditionally required | Depends | Type-dependent: with incoming image/video type → `sometimes\|nullable\|file\|mimes...\|max` (5MB image / 20MB video); with incoming `text` → prohibited; with NO incoming type → loose `sometimes\|nullable\|file\|max:20480` (strict per-type mime then enforced in SERVICE against the resolved type — code-verified split) | Type-appropriate file | None | Required when switching to a media type without existing media in the target collection (service-thrown 422, message verified: `"The media field is required when changing type to {type}."`); forbidden when resolved type is text | Binary upload | Replacement media (replace = clear both collections, attach to resolved type's collection) |

Media matrix (code-verified service semantics): new file supplied → mime-checked, both collections cleared, attached to resolved type's collection; `remove_media` truthy without file → both cleared; type change to text without file/flag → both cleared; type change between media kinds without file and without existing target media → 422; otherwise existing media kept. Frontend `static_pages` cache tag flushed on success.

There are no query parameters. Any extra body fields are ignored.

## Request examples

Valid request renaming a section (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/static-pages/about-us/sections/21",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "title": { "en": "Y" }
  }
}
```

# Responses

## Success: section updated — HTTP 200

Returned via `ApiResponse::apiResponse(STATIC_SECTION_UPDATED_SUCCESSFULLY, 200, true, StaticSectionResource::make($section->load('media')))` after the transaction + media handling + cache flush. Verified by `test_update_section_permission_allows_update_and_reorder` (`assertOk` on this URI; `putJson`, matching this route) and `test_full_permissions_allows_all_admin_operations` (same). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.STATIC_SECTION_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Static section updated successfully"`, `ar` → `"تم تحديث قسم الصفحة الثابتة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated section serialized by `App\Http\Resources\StaticPage\StaticSectionResource` with `media` loaded | `id`, `static_page_id`, `type`, `title` (locale string), `content` (ALL locales object), `config`, `order`, `is_active`, `media` (object or `null`) |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Static section updated successfully",
  "success": true,
  "data": {
    "id": 21,
    "static_page_id": 1,
    "type": "text",
    "title": "Y",
    "content": { "en": { "heading": "Welcome" } },
    "config": null,
    "order": 1,
    "is_active": true,
    "media": null
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: binding misses use the Handler fixed string, ownership misses use the 404 envelope, validation (request AND service-thrown) uses the 422 shape.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token (no update-section-specific 401 test exists; identical rejection test-verified on sibling static routes) | See 401 example |
| 403 | Authenticated caller lacks the `update-static-sections` permission (Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-static-sections` (no update-section-specific 403 test exists; identical path test-verified on sibling static routes) | See 403 example |
| 404 | Unknown page slug OR unknown section id: implicit binding throws `ModelNotFoundException` before validation runs → `Handler` renders its `ModelNotFoundException` branch (takes precedence over 422 — code-verified order) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown slug or section id | See 404 example (binding) |
| 404 | Section exists but belongs to ANOTHER page: `assertSectionBelongsToPage` throws `ModelNotFoundException`, caught by the method → `apiResponse(NOT_FOUND, 404, false)` envelope (anti-leak by design — indistinguishable from a true miss) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — no test exercises cross-page access) | None | Valid ids with mismatched ownership | See 404 example (ownership) |
| 405 | `POST`, `PATCH`, or `DELETE` on `/api/v1/static-pages/{slug}/sections/{id}` (no such routes are registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `UpdateStaticSectionRequest` failure (rules + after-hooks), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` — this ALSO covers service-thrown `ValidationException::withMessages` (media-required-on-type-change, mime re-checks: verified messages `"The media field is required when changing type to {type}."`, `"The media must be an image (jpeg, png, webp, gif)."`, `"The media must be a video (mp4, webm, ogg, mov, avi)."`), which the method rethrows untouched | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `type`, `title`, `title.{locale}`, `content`, `config`, `is_active`, `remove_media`, `media`; exact rule messages **Unverified in source** — no update-specific 422 test exists) | Bad field values, list-shaped/explicitly-empty-for-text `content`, missing media on media-type switch, mistyped media | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure other than the caught `ModelNotFoundException` (any exception: transaction, media attach, serialize), rendered by the generic branch of `Handler` (`ValidationException` is rethrown, not rendered here) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-static-sections` permission; `en` locale):

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

422 example (missing media on switch to image type):

```json
{
  "message": "The media field is required when changing type to image.",
  "status": false,
  "errors": {
    "media": [
      "The media field is required when changing type to image."
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

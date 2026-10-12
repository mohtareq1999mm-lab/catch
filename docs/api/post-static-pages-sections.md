# Endpoint Name

Static Pages – Create Section (`POST /api/v1/static-pages/{static_page:slug}/sections`)

# Description

Creates a typed section with optional media on a page, compensating on media failure.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/static-pages/{slug}/sections` (the `{static_page:slug}` placeholder binds on the `slug` column) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `static-pages/{static_page:slug}/sections` from the static-pages group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\StaticPageController@storeSection` → `StaticPageService::createSection` (transactional row creation + post-commit media attach with compensation delete) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-static-sections` via controller constructor `permission:create-static-sections` middleware (applies only to `storeSection`); the `StoreStaticSectionRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json`, plus `multipart/form-data` when uploading `media` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`. Implicit binding resolves `{static_page:slug}` before validation, so an unknown slug 404s even with an invalid body (code-verified order).

## Request inputs

`StoreStaticSectionRequest` validation runs before the method body (`failedValidation` rethrows → 422). Types: `text`, `image`, `video`, `screenshot` (image-backed: `image` + `screenshot`; code-verified enum).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json`, or `multipart/form-data` for uploads | None | None | `application/json` | Declares body encoding |
| `static_page` | path | slug string (implicit route-model binding on the `slug` column) | Required | No | Resolved by Laravel before validation; unknown slugs throw `ModelNotFoundException` → `Handler` 404 | An existing page `slug` | None | None | `about-us` | Page receiving the section, by slug |
| `type` | body | string | Optional | Yes (`nullable`) | `sometimes\|nullable\|string\|in:text,image,video,screenshot` | Omitted/null defaults to `text` (code-verified normalization); explicit value must be a known type | `text` (effective) | None | `"text"` | Section-type discriminator (drives media requirements) |
| `title` | body | object (associative array of locale → string) | Required | No | `required\|array`, plus `title.en`: `required\|string\|max:255`; other locales `title.*`: `nullable\|string\|max:255` | Object with non-empty `en` ≤255 chars | None | None | `{"en": "New", "ar": "جديد"}` | Localized titles (persisted) |
| `content` | body | object (associative array of locale → array) | Conditionally required | No | `nullable\|array` + `content.en`/`content.ar`: `nullable\|array` each; after-hook: (a) top-level JSON LISTS rejected with `STATIC_SECTION_CONTENT_INVALID` (Spatie single-locale branch guard — code-verified), (b) when resolved type is `text` (the default), `content` must be a non-empty array | Locale-keyed objects (never a list) | None | Required (non-empty) when type resolves to `text` | `{"en": {"heading": "Hi"}}` | Localized content payloads (persisted) |
| `config` | body | object/array | Optional | Yes (`nullable`) | `nullable\|array` | Any array or null | Absent | None | `{"align": "center"}` | Arbitrary config JSON (persisted; explicit `null` stays `null`) |
| `is_active` | body | boolean/integer | Optional | Yes (`nullable`) | `nullable\|in:0,1,true,false,TRUE,FALSE` (string spellings accepted here — code-verified difference from the strict `in:0,1` flags elsewhere) | `0`/`1`/spellings/null | `true` when absent (code-verified service default) | None | `true` | Active flag |
| `remove_media` | body | — | FORBIDDEN | N/A | `prohibited` — presence in ANY form fails validation (code-verified; this flag exists only on update) | Must be absent | N/A | None | N/A | Rejected field |
| `media` | body (file) | upload | Conditionally required | Depends on type | Image types (`image`, `screenshot`): `required\|file\|mimetypes:image/jpeg,image/png,image/jpg,image/webp,image/gif\|max:5120` (5MB); `video`: `required\|file\|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo\|max:20480` (20MB); `text`/absent type: `nullable\|prohibited` (must NOT be supplied) | Type-appropriate file | None | Required for image/video; forbidden for text | Binary upload | Media attached post-commit to the type's collection |

There are no query parameters. Any extra body fields are ignored.

Creation semantics (code-verified): row created in a transaction (sortable auto-assigns `order`); media attached AFTER commit into the image or video collection by type; service-level mime re-check per type; on media failure the row is COMPENSATED (deleted) and the error propagates — so a failed upload never leaves an orphan section. Frontend `static_pages` cache tag flushed on success.

## Request examples

Valid request creating a text section (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/static-pages/about-us/sections",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "title": { "en": "New", "ar": "جديد" },
    "content": { "en": { "heading": "Hi" }, "ar": { "heading": "مرحبا" } }
  }
}
```

Valid multipart request creating an image section (field `media` carries the file; `Content-Type: multipart/form-data`):

```json
{
  "method": "POST",
  "url": "/api/v1/static-pages/about-us/sections",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  },
  "body": {
    "type": "image",
    "title": { "en": "Hero" },
    "content": { "en": {} },
    "media": "(binary file, jpeg/png/webp/gif ≤5MB)"
  }
}
```

# Responses

## Success: section created — HTTP 200

Returned via `ApiResponse::apiResponse(STATIC_SECTION_CREATED_SUCCESSFULLY, 200, true, StaticSectionResource::make($section->load('media')))` — note 200, NOT 201 (code-verified). Verified by `test_create_section_permission_only_allows_store` and `test_full_permissions_allows_all_admin_operations` (both `assertOk` on this URI with title+content; `postJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (not 201) |
| `message` | string | Always present | Translated `MESSAGE.STATIC_SECTION_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Static section created successfully"`, `ar` → `"تم إنشاء قسم الصفحة الثابتة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created section serialized by `App\Http\Resources\StaticPage\StaticSectionResource` with `media` loaded | `id`, `static_page_id`, `type` (effective, default `text`), `title` (locale string), `content` (ALL locales object), `config`, `order` (int, auto-assigned), `is_active`, `media` (object or `null`) |

Complete success example (`en` locale; `id` illustrative):

```json
{
  "status": 200,
  "message": "Static section created successfully",
  "success": true,
  "data": {
    "id": 21,
    "static_page_id": 1,
    "type": "text",
    "title": "New",
    "content": { "en": { "heading": "Hi" }, "ar": { "heading": "مرحبا" } },
    "config": null,
    "order": 1,
    "is_active": true,
    "media": null
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: request-validation 422 (Handler), service-thrown `ValidationException` 422 (Handler — media-required/mime cases), and uncaught `QueryException`/others (409/500).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_cannot_create_section` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `create-static-sections` permission (Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-static-sections`; verified by `test_view_permission_allows_index_and_show_but_blocks_mutations` (POST → 403) | See 403 example |
| 404 | No page has `slug = {static_page}`: implicit binding throws `ModelNotFoundException` before validation runs → `Handler` renders its `ModelNotFoundException` branch (takes precedence over 422 — code-verified order) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown slug (code-traced — no endpoint-specific 404 test exists) | See 404 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on `/api/v1/static-pages/{slug}/sections` (no such routes are registered; the reorder `POST` URI is longer and separate) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 409 | Database constraint failure during `create()` (the method/service has no `QueryException` catch — it escapes to `Handler`'s `QueryException` branch); conditional on database state | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Only when persisting violates a database constraint (code-traced, no test) | See 409 example |
| 422 | Any `StoreStaticSectionRequest` failure (rules + `with_product`-less after-hooks: list-shaped `content`, missing/empty `content` for text, `media` presence/absence/mime/size violations, `remove_media` present), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` — this ALSO covers service-thrown `ValidationException::withMessages` (media-required guard, mime re-check), which the controller does NOT catch | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure; service messages verified verbatim: `"The media field is required."`, `"The media must be an image (jpeg, png, webp, gif)."`, `"The media must be a video (mp4, webm, ogg, mov, avi)."`; request message verified: `"The section content must be an object keyed by locale"` / `"يجب أن يكون محتوى القسم كائنًا بمفاتيح اللغات"`) | Map of field → message arrays (keys: `type`, `title`, `title.{locale}`, `content`, `config`, `is_active`, `remove_media`, `media`; exact rule messages **Unverified in source** — no store-specific 422 test exists) | Missing/non-array `title`, missing/non-string `title.en`, list-shaped or missing-for-text `content`, unknown `type`, present `remove_media`, missing/forbidden/mistyped/oversize `media` | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure other than validation (any exception: transaction, media attach, serialization), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-static-sections` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown page slug):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/static-pages/about-us/sections`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (database constraint failure during `create()`):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

422 example (missing `title`):

```json
{
  "message": "The title field is required.",
  "status": false,
  "errors": {
    "title": [
      "The title field is required."
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

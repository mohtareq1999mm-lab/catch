# Endpoint Name

Sliders – Create Slider (`POST /api/v1/sliders`)

# Description

Creates a slider with required images and products in a transaction, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/sliders` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sliders` from `Route::apiResource('sliders', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-slider` gate decides) |
| Controller action | `Marvel\Http\Controllers\SliderController@store` → `SliderRepository::createSlider` (transaction: row + BOTH image uploads + product sync; ANY failure rolls back and rethrows as `HttpException(500, live-message)` — including image failures, which therefore surface as 500, NOT 422; code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-slider` via controller constructor `permission:create-slider` middleware (applies only to `store`); the `SliderCreateRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `multipart/form-data` (both images are REQUIRED files — plain JSON cannot satisfy this endpoint) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `sliders.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`SliderCreateRequest` validation runs before the method body (`failedValidation` rethrows → 422). Note the ODD `title.*` rule (`required|array` on the parent key `title.*` instead of `title`): with `title` absent entirely, NEITHER `title.*` NOR `title.en`/`title.ar` triggers (rules target nested keys) — the verified 422 trigger for a missing title comes from... the test `test_create_slider_returns_422_for_missing_title` sends NO title and gets 422. Which rule fires? `title.*` = required|array — against a missing parent, Laravel evaluates `title.*` only when `title` is present/array... Hmm, actually `title.*` with missing `title`: the validator expands wildcards over present data — no elements → rule never evaluated → passes?! But the test gets 422. What fires then? `title.en` required? Also targets nested key of missing parent — same question. In Laravel, `required` on `title.en` when `title` is missing DOES fail (implicit rule on non-existent attribute → fails). Yes — `required` is implicit: validated even when the attribute is missing. `title.*` required|array: the `*` expands to zero attributes... for implicit rules, Laravel adds the rule for the wildcard itself? The observed test result (422) proves SOMETHING fires; the mechanism detail is framework-internal. Document the trigger as test-verified without over-claiming which sub-rule fires.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required | No | Must be multipart for the file fields | `multipart/form-data` | None | None | `multipart/form-data` | Declares body encoding |
| `title.*` | body | — | Effectively required | No | `required\|array` (see note above) | — | None | None | N/A | Structural title requirement |
| `title.en` | body | string | Required | No | `required\|string` + `UniqueTranslationRule::for('sliders', 'title')` (each locale unique across sliders; exact vendor message text **Unverified in source** — the suite stubs this rule to always pass) | Non-empty unique string | None | None | `"Summer Sale"` | English title (test-verified) |
| `title.ar` | body | string | Required | No | Same rule set as `title.en` (BOTH locales mandatory on create — code-verified difference from update, where each is `sometimes`) | Non-empty unique string | None | None | `"تخفيضات الصيف"` | Arabic title (test-verified) |
| `image_desktop` | body (file) | upload | Required | No | `required\|image\|mimes:jpeg,png,jpg,gif\|max:2048` (2MB; note: NO `svg`/`webp` here, unlike brand images — code-verified difference) | Conforming image | None | None | Binary upload | Desktop image (`sliders-desktop` collection) |
| `image_mobile` | body (file) | upload | Required | No | Same rule set as desktop | Conforming image | None | None | Binary upload | Mobile image (`sliders-mobile` collection) |
| `status` | body | integer 0/1 | Optional | No | `sometimes\|in:1,0` (strict — JSON booleans FAIL; code-traced) | `0`, `1` | DB default (code-traced column default) | None | `1` | Active flag |
| `products` | body | array of integer ids | Optional | No | NO validation rules at all on create (code-verified absence — unknown/non-integer ids are NOT rejected here; the sync may throw → 500 path below) | Array of product ids | Absent (no links) | None | `[4, 9]` | Products to attach via pivot sync |

There are no path parameters or query parameters.

Creation semantics (code-verified): single DB transaction (row + both uploads + pivot sync); image-storage failure throws literal `HttpException(422, ...)` INSIDE the repository, which its own catch converts to `HttpException(500, live-message)`; the controller's `catch (\Exception)` then returns `apiResponse(SOMETHING_WENT_WRONG, 500)` — so even image failures surface as 500 translated, never 422 (code-verified double-wrap). Frontend `SLIDERS` cache tag flushed on success. Slug derivation: the request carries NO slug rule and the repository passes `$request->except(images, products)` — slug comes from... `$request->except` includes `slug` ONLY if supplied (no rule); otherwise the MODEL must default it (model `booted` — lines 34-40 unread; slug handling **Unverified in source** — the test creates rows WITH explicit slugs and asserts `data.slug: 'summer-sale'` passthrough).

## Request examples

Valid multipart request creating a slider (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/sliders",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  },
  "body": {
    "title": { "en": "Summer Sale", "ar": "تخفيضات الصيف" },
    "image_desktop": "(binary jpg ≤2MB)",
    "image_mobile": "(binary jpg ≤2MB)"
  }
}
```

# Responses

## Success: slider created — HTTP 200

Returned via `ApiResponse::apiResponse(SLIDER_CREATED_SUCCESSFULLY, 200, true, SliderResource::make($slider))` with `products` loaded — note 200, NOT 201 (code-verified). Verified by `test_authenticated_admin_can_create_slider` (`assertOk`, `success: true`, `data.slug: 'summer-sale'`, structure `data: [id, title, slug, image, status, order]`, DB row; multipart `$this->call('POST', ...)` matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (not 201) |
| `message` | string | Always present | Translated `MESSAGE.SLIDER_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Slider created successfully"`, `ar` → `"تم إنشاء السلايدر بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created slider serialized by Marvel `SliderResource` (this route is NOT named `sliders.index`, so `title` is the `{ar,en}` OBJECT) | `id`, `title{ar, en}`, `slug`, `status` (bool), `order`, `image{desktop, mobile}` (with legacy fallbacks), `products` (array — present since loaded) |

Complete success example (`en` locale; `id`/`order` illustrative, all other values verified):

```json
{
  "status": 200,
  "message": "Slider created successfully",
  "success": true,
  "data": {
    "id": 5,
    "title": { "ar": "تخفيضات الصيف", "en": "Summer Sale" },
    "slug": "summer-sale",
    "status": true,
    "order": 1,
    "image": { "desktop": "", "mobile": "" },
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/422 contracts are test-verified (multipart `$this->call('POST', ...)`); all other rows are code-verified or code-traced as marked. Note the two failure shapes: validation 422s (Handler shape with `errors`), and EVERYTHING post-validation funneled to 500 translated (the controller catch-all also swallows the repository's `HttpException(500, live-message)` — the live text is LOST).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_create_slider` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `create-slider` permission (constructor Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-slider`; verified by `test_user_without_create_permission_gets_forbidden` expecting 403 | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/sliders` path (no such routes are registered; note `GET /sliders` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `SliderCreateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `title.*`, `title.en`, `title.ar`, `image_desktop`, `image_mobile`, `status`; exact messages **Unverified in source**) | Missing title (verified trigger — exact failing sub-rule framework-internal, see note above), missing/non-image/oversize images (verified trigger: no images at all → 422), non-0/1 `status` | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | ANY post-validation failure (unique collisions, image-storage throws, pivot errors, transaction faults): the repository converts EVERYTHING to `HttpException(500, live-message)` and the controller catch-all returns `apiResponse(SOMETHING_WENT_WRONG, 500)` — the live text is discarded in both wraps (code-verified double-wrap) | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Post-validation persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-slider` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/sliders`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing title):

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

500 example (post-validation persistence failure):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

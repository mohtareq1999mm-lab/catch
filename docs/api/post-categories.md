# Endpoint Name

Categories – Create Category (`POST /api/v1/categories`)

# Description

Creates a category with required images, derived slug, and product links in a transaction.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/categories` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories` from `Route::apiResource('categories', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-category` gate decides) |
| Controller action | `Marvel\Http\Controllers\CategoryController@store` → `CategoryRepository::saveCategory` (transaction: row + product sync + BOTH image uploads; `ValidationException`s rethrown untouched, everything else wrapped as `HttpException(500, live-message)` — including image failures, which therefore surface as 500, NOT 422; code-verified. The controller catch-all then converts to `MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` → 500 translated) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-category` via controller constructor `permission:create-category` middleware (applies only to `store`); the `CategoryCreateRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `multipart/form-data` (both images are REQUIRED files — plain JSON cannot satisfy this endpoint) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `categories.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`CategoryCreateRequest` validation runs before the method body (`failedValidation` rethrows → 422). Persisted fields are `name`/`details`/`parent_id?`... precisely: `$request->only($this->dataArray)` — the dataArray was not fully read in this pass; verified members include at least `name`, `details` (per rules + resource), plus `slug` derived — parent linkage and remaining keys per the repository's array (**Unverified in source** beyond rules/resource evidence — flagged honestly). `products` syncs the pivot when present; images upload to media collections. Any other body fields are ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required | No | Must be multipart for the file fields | `multipart/form-data` | None | None | `multipart/form-data` | Declares body encoding |
| `name` | body | object (associative array of locale → string) | Required | No | `required\|array`, per-locale `name.*`: `required\|string` + `UniqueTranslationRule::for('categories', 'name')` (each locale unique across categories; exact vendor message text **Unverified in source** — sibling suites stub this rule to always pass; note NO `max` length rule — code-verified absence) | Object with every supplied locale a non-empty string | None | None | `{"en": "Shoes", "ar": "أحذية"}` | Localized names |
| `image-desktop` | body (file) | upload | Required | No | `required\|file\|mimes:jpeg,png,jpg,gif,svg\|max:2048` (2MB) | Conforming image | None | None | Binary upload | Desktop image (`categories-desktop` collection) |
| `image-mobile` | body (file) | upload | Required | No | Same rule set as desktop | Conforming image | None | None | Binary upload | Mobile image (`categories-mobile` collection) |
| `parent_id` | body | integer | Optional | Yes (`nullable`) | `nullable\|integer\|exists:categories,id` | Existing category id or null | Absent (root) | None | `4` | Parent linkage (hierarchy level derived server-side — code-traced service behavior) |
| `details` | body | string (NOT a translations object — code-verified difference from brands, whose details are per-locale) | Optional | No | `sometimes\|string\|min:3\|max:2500` | Strings ≥3 ≤2500 chars | Absent | None | `"Top shoes"` | Plain details text |
| `status` | body | — | Optional | — | NO rule in `CategoryCreateRequest` (code-verified absence — any value passes validation; persistence depends on the unverified `dataArray` membership) | Any | DB default | None | N/A | Unvalidated field |
| `products` | body | array of integer ids | Optional | No | `sometimes\|array`, per-id `exists:products,id` (non-integer ids fail; non-positive ids are NOT filtered here unlike brands — code-verified absence of the `array_filter` guard) | Array of existing product ids | Absent (no links) | Present → pivot SYNCED | `[4, 9]` | Products to attach via pivot sync |

Slug derivation (code-verified `makeSlug`): explicit `slug` field wins when present (fillable), else derived from `name.en` and uniquified via `globalSlugify`.

Creation semantics (code-verified): single DB transaction (row + pivot sync + both uploads); image-storage failure throws literal `HttpException(422, ...)` INSIDE the repository, which its own catch converts to `HttpException(500, live-message)`; the controller's `catch (MarvelException)` does NOT catch `HttpException`... wait — flow check: repository throws `HttpException(500, msg)` → controller `catch (MarvelException $th)` does NOT match `HttpException` → escapes to `Handler` `HttpException` branch → 500 with the LIVE message (not the translated constant). Correction to the funnel: store failures surface 500 + LIVE text (like sliders), because the controller only converts `MarvelException`. Document precisely that. Frontend `CATEGORIES` cache tag flushed on success (controller entanto? — store does NOT flushTag! Lines 131-141: no flushTag call — code-verified ABSENCE (unlike update). Hmm: store has no flushTag. State it.)

There are no path parameters or query parameters.

## Request examples

Valid multipart request creating a category (call shape per sibling brand tests):

```json
{
  "method": "POST",
  "url": "/api/v1/categories",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Shoes", "ar": "أحذية" },
    "image-desktop": "(binary jpg ≤2MB)",
    "image-mobile": "(binary jpg ≤2MB)"
  }
}
```

# Responses

## Success: category created — HTTP 200

Returned via `ApiResponse::apiResponse(CATEGORY_CREATED_SUCCESSFULLY, 200, true, CategoryResource::make($category))` with `products` loaded — note 200, NOT 201 (code-verified). No endpoint-specific success test was found in this pass (the category suites cover auth/authz/validation/translation/relationships; all expectations below are code-verified). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (not 201) |
| `message` | string | Always present | Translated `MESSAGE.CATEGORY_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Category created successfully"`, `ar` → `"تم إنشاء الفئة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created category serialized by Marvel `CategoryResource` | `id`, `name` (LOCALE STRING here — not the `{ar,en}` object; the object form appears ONLY on `categories.show`), `slug`, `parent_id`, `parent` (nested or null), `level`, `image{desktop, mobile}`, `is_featured`, `products_count`, `status`, `details` (OMITTED on non-`categories.index`... wait: merged when NOT `categories.index` — this route is not index, so `details` IS present; code-verified), `products` (array — present since loaded) |

Complete success example (`en` locale; `id` illustrative, all other values code-verified):

```json
{
  "status": 200,
  "message": "Category created successfully",
  "success": true,
  "data": {
    "id": 9,
    "name": "Shoes",
    "slug": "shoes",
    "parent_id": null,
    "parent": null,
    "level": 0,
    "image": { "desktop": "", "mobile": "" },
    "is_featured": false,
    "products_count": 0,
    "status": true,
    "details": null,
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 401/403/422 contracts are test-verified through the auth/authz/validation suites (correct methods); all other rows are code-verified or code-traced as marked. Note the three failure shapes: validation 422s (Handler shape with `errors`), image-storage 422-literals (untranslated, no map), and repository `HttpException(500, live-text)` failures rendering 500 with LIVE text (NOT the translated constant — code-verified, since the controller only converts `MarvelException`).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_create_category` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `create-category` permission (constructor Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-category` (no store-specific 403 test found in this pass; identical constructor-gate path test-verified on sibling category routes) | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/categories` path (no such routes are registered; note `GET /categories` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `CategoryCreateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `name`, `name.{locale}`, `image-*`, `parent_id`, `details`, `products*`; exact messages **Unverified in source** — the Validation suite covers partitions of these) | Missing/non-array `name`, empty/non-string locale value, duplicate locale name (stub-bypassed in suites), missing/non-image/oversize images, unknown `parent_id`, short/long `details`, unknown product ids | See 422 example |
| 422 | Image-storage failure AFTER validation: `uploadSingleImage` returns falsy → literal `HttpException(422, 'Logo upload failed, please check the file format or size.')` — English-only, NO `errors` map (code-verified shape difference; the transaction then rolls back) | `{message: string, status: boolean}` | None | `"Logo upload failed, please check the file format or size."` (literal) | None | Storage-backend failure for either image (code-traced, no test) | See 422 example (media) |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Repository failure wrapped as `HttpException(500, live-message)` (unique collisions, pivot errors, transaction faults): escapes the controller's `MarvelException`-only catch → `Handler` `HttpException` branch renders 500 with the LIVE text (code-verified — NOT the translated constant) | `{message: string, status: boolean}` | None | Live exception/operation text (dynamic, **Unverified in source** per case) | None | Post-validation persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-category` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/categories`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `name`):

```json
{
  "message": "The name field is required.",
  "status": false,
  "errors": {
    "name": [
      "The name field is required."
    ]
  }
}
```

422 example (image storage failure — note no `errors` map):

```json
{
  "message": "Logo upload failed, please check the file format or size.",
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

500 example (post-validation persistence failure; text dynamic):

```json
{
  "message": "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'shoes' for key 'categories.slug'",
  "status": false
}
```

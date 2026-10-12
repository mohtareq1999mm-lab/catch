# Endpoint Name

Brands – Create Brand (`POST /api/v1/brands`)

# Description

Creates a brand with required images, derived slug, and product links in a transaction.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/brands` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands` from `Route::apiResource('brands', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-brand` gate decides) |
| Controller action | `Marvel\Http\Controllers\BrandController@store` → `BrandRepository::saveBrand` (transaction: row + product sync + BOTH image uploads; ANY failure rolls back, logs, and surfaces 500 — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-brand` via controller constructor `permission:create-brand` middleware (applies only to `store`); the `BrandCreateRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `multipart/form-data` (both images are REQUIRED files — plain JSON cannot satisfy this endpoint) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `brands.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`BrandCreateRequest` validation runs before the method body (`failedValidation` rethrows → 422). Persisted fields are `name`/`details`/`status` (+ derived `slug`); `products` syncs the pivot; images upload to media collections. Any other body fields are ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required | No | Must be multipart for the file fields | `multipart/form-data` | None | None | `multipart/form-data` | Declares body encoding |
| `name` | body | object (associative array of locale → string) | Required | No | `required\|array`, per-locale `name.*`: `required\|string` + `UniqueTranslationRule::for('brands', 'name')` (each locale unique across brands; exact vendor message text **Unverified in source** — the suite stubs this rule to always pass, so duplicate-name behavior is untested here; note NO `max` length rule — code-verified absence) | Object with every supplied locale a non-empty string | None | None | `{"en": "Nike", "ar": "نايك"}` | Localized names (test-verified round trip per locale) |
| `image-desktop` | body (file) | upload | Required | No | `required\|file\|mimes:jpeg,png,jpg,gif,svg\|max:2048` (2MB) | Conforming image | None | None | Binary upload | Desktop image (stored in `brands-desktop` collection) |
| `image-mobile` | body (file) | upload | Required | No | Same rule set as desktop | Conforming image | None | None | Binary upload | Mobile image (stored in `brands-mobile` collection) |
| `details` | body | object | Optional | No | `sometimes\|array`, per-locale `details.*`: `required_with:details\|string\|min:3\|max:2500` | Locales ≥3 ≤2500 chars | Absent | None | `{"en": "Sportswear"}` | Localized details |
| `status` | body | integer 0/1 | Optional | No | `sometimes\|in:1,0` (strict — JSON booleans FAIL; code-traced) | `0`, `1` | DB default (code-traced column default; the request key stays absent) | None | `1` | Active flag (verified trigger: `99` → 422) |
| `products` | body | array of integer ids | Optional | No | `sometimes\|array`, per-id `integer\|exists:products,id` (non-positive ids FILTERED OUT silently by the repository — `array_filter(intval > 0)` — code-verified; unknown positive ids fail validation first) | Array of existing product ids | Absent (no links) | None | `[4, 9]` | Products to attach via pivot sync (test-verified association) |

Slug derivation (code-verified `makeSlug` + model-level regression tests): explicit `slug` field wins when present (note: `slug` has NO validation rule — it passes through `$request->only(dataArray)` since it IS fillable); else derived from `name.en`; uniqueness enforced by `globalSlugify`. There are no path parameters or query parameters.

Creation semantics (code-verified): single DB transaction (row + pivot sync + both uploads); image-storage failure throws `HttpException(422, 'Logo upload failed, please check the file format or size.')` — LITERAL English string, untranslated (code-verified); ANY exception rolls back, logs (`Brand save failed`), and the controller converts to `MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` → 500 translated. Frontend `BRANDS` cache tag flushed on success.

## Request examples

Valid multipart request creating a brand (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/brands",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Nike", "ar": "نايك" },
    "image-desktop": "(binary jpg ≤2MB)",
    "image-mobile": "(binary jpg ≤2MB)"
  }
}
```

# Responses

## Success: brand created — HTTP 201

Returned via `ApiResponse::apiResponse(BRAND_CREATED_SUCCESSFULLY, 201, true, BrandResource::make($brand))` with `products` loaded. Verified by `test_authenticated_admin_can_create_brand` (`assertCreated`, `success: true`, structure `data: [id, name, slug, image, details, status]`, `data.slug: 'nike'`, DB row; multipart `$this->call('POST', ...)` matching this route) and `test_create_brand_with_product_association`. There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.BRAND_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Brand created successfully"`, `ar` → `"تم إنشاء العلامة التجارية بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created brand serialized by Marvel `BrandResource` | `id`, `name` (LOCALE STRING here — not the `{ar,en}` object; the object form appears ONLY on `brands.show`), `slug`, `image{desktop, mobile}` (URLs or `""` — test-verified empty strings with no media... note: store REQUIRES images, so URLs are normally present), `details` (locale string), `status` (bool), `products` (array — present since loaded; item shape `{id, name, slug, status, image{thumbnail}}`) |

Complete success example (`en` locale; `id` illustrative, all other values verified):

```json
{
  "status": 201,
  "message": "Brand created successfully",
  "success": true,
  "data": {
    "id": 5,
    "name": "Nike",
    "slug": "nike",
    "image": { "desktop": "", "mobile": "" },
    "details": "Sportswear",
    "status": true,
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 201/401/403/422 contracts are test-verified (multipart `$this->call('POST', ...)`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: validation 422s (Handler shape with `errors`), image-storage 422s (literal `HttpException` message, NO `errors` map), and everything else funneled to 500 translated (the controller catch-all converts ANY repository exception, including `QueryException`s, to `COULD_NOT_CREATE_THE_RESOURCE`).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_create_brand` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `create-brand` permission (constructor Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-brand`; verified by `test_user_without_create_permission_gets_forbidden` expecting 403 | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/brands` path (no such routes are registered; note `GET /brands` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `BrandCreateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `name`, `name.{locale}`, `image-desktop`, `image-mobile`, `details*`, `status`, `products*`; exact messages **Unverified in source**) | Missing/non-array `name` (verified trigger), missing/non-image/oversize images, short/long `details`, non-0/1 `status` (verified trigger: `99`), unknown product ids | See 422 example |
| 422 | Image-storage failure AFTER validation: `uploadSingleImage` returns falsy → `HttpException(422, 'Logo upload failed, please check the file format or size.')` — LITERAL English string, untranslated, NO `errors` map (code-verified shape difference from validation 422s; the transaction then rolls back) | `{message: string, status: boolean}` | None | `"Logo upload failed, please check the file format or size."` (literal) | None | Storage-backend failure for either image (code-traced, no test) | See 422 example (media) |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | ANY repository failure (unique collisions incl. slug, pivot errors, non-image exceptions): the controller's `catch (\Exception)` converts EVERYTHING to `MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` → `Handler` renders 500 translated (code-verified funnel — even `QueryException`s land here, never 409) | `{message: string, status: boolean}` | None | Translated `ERROR.COULD_NOT_CREATE_THE_RESOURCE`: `en` → `"Could not create the resource"`, `ar` → `"تعذر إنشاء المورد"` | None | Post-validation persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-brand` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/brands`):

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

500 example (post-validation persistence failure; `en` locale):

```json
{
  "message": "Could not create the resource",
  "status": false
}
```

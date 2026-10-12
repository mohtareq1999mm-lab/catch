# Endpoint Name

Banners – Create Banner (`POST /api/v1/banners`)

# Description

Creates one banner with bilingual title and required desktop/mobile images, returning 200 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/banners` |
| Path composition | `api/v1` prefix + `banners` from `Route::apiResource('banners', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-banners` gate decides) |
| Controller action | `Marvel\Http\Controllers\BannerController@store` → `BannerRepository::createBanner` in a DB transaction: creates the row (all fields except the two images), syncs `products` when the key is present, uploads both images via `MediaManager`; ANY failure rolls back and rethrows as `HttpException(500, live-message)` → controller catch-all converts to 500 `SOMETHING_WENT_WRONG` (the live message is swallowed — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-banners` via controller constructor (applies only to `store`); `BannerCreateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` (two image files are required) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `multipart/form-data` (test-verified via `$this->call('POST', ..., [], [], [images], ...)`); plain JSON without files fails with 422 |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `banners.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`. NOTE (code-verified): success status is **200, NOT 201** — the controller passes `200` to `apiResponse` and the test asserts `assertOk`.

## Request inputs

`BannerCreateRequest` validation runs before the method body (`failedValidation` rethrows → 422). Slug is NOT a request field: `Banner::booted` derives `slug` from the English title via `Str::slug` on every save. `id` is not fillable (verified by `test_banner_mass_assignment_protection` — `id: 99999` ignored, still 200).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `title` | body | object | Required | No | `required\|array` | Object with `en`+`ar` | None | None | `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}` | Localized titles |
| `title.en` | body | string | Required | No | `required\|string\|max:255\|min:3` + `UniqueTranslationRule::for('banners', 'title')` (vendor text **Unverified in source** — suites stub this rule to always pass) | String 3–255 chars, unique | None | None | `"Summer Sale"` | English title (also the slug source) |
| `title.ar` | body | string | Required | No | Same rule set as `title.en` (BOTH locales mandatory on create) | String 3–255 chars, unique | None | None | `"تخفيضات الصيف"` | Arabic title |
| `description` | body | object | Required (wrapper) | No | `required\|array` — but BOTH children are `nullable`, so `"description": {}` passes validation (code-verified laxity) | Object | None | None | `{"en": "Best deals this summer"}` | Localized descriptions wrapper |
| `description.en` | body | string | Optional | Yes | `nullable\|string\|max:500\|min:5` (+ unique-translation rule; NOTE create uses `min:5` while update uses `min:10` — code-verified asymmetry) | String 5–500 chars or null | `null` | None | `"Best deals this summer"` | English description |
| `description.ar` | body | string | Optional | Yes | Same rule set as `description.en` | String 5–500 chars or null | `null` | None | `"أفضل العروض هذا الصيف"` | Arabic description |
| `image_desktop` | body (file) | file (image) | Required | No | `required\|image\|mimes:jpeg,png,jpg,gif\|max:2048` (kilobytes) | jpeg/png/jpg/gif ≤ 2 MB | None | None | `desktop.jpg` | Desktop banner image (stored in `banners-desktop` media collection) |
| `image_mobile` | body (file) | file (image) | Required | No | Same rule set as `image_desktop` | jpeg/png/jpg/gif ≤ 2 MB | None | None | `mobile.jpg` | Mobile banner image (stored in `banners-mobile` media collection) |
| `status` | body | string/integer | Optional | No | `sometimes\|in:0,1` (strict `1`/`0`) | `1`, `0` | Model default | None | `1` | Active flag |
| `products` | body | array of integers | Optional | No | `sometimes\|array`, per-element `products.*`: `exists:products,id` (scopeless) | Existing product ids | Absent (no links) | None | `[3]` | Products to attach via `banner_product` pivot (verified by `test_create_banner_with_product_association`) |

No path or query parameters.

## Request examples

Multipart request creating a banner (test-verified call shape; files shown as placeholders):

```json
{
  "method": "POST",
  "url": "/api/v1/banners",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "multipart/form-data",
    "lang": "en"
  },
  "body": {
    "title": { "en": "Summer Sale", "ar": "تخفيضات الصيف" },
    "description": { "en": "Best deals this summer", "ar": "أفضل العروض هذا الصيف" },
    "image_desktop": "<file desktop.jpg>",
    "image_mobile": "<file mobile.jpg>"
  }
}
```

# Responses

## Success: banner created — HTTP 200

Returned via `ApiResponse::apiResponse(BANNER_CREATED_SUCCESSFULLY, 200, true, BannerResource::make($banner))` with `products` loaded. Verified by `test_authenticated_admin_can_create_banner` (`assertOk`, `data.slug: summer-sale`, DB row asserted) using a multipart call. NOTE the 200 (not 201) and the locale-string `title` (route is NOT `banners.show`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (NOT 201 — code-verified) |
| `message` | string | Always present | Translated `MESSAGE.BANNER_CREATED_SUCCESSFULLY` | `en` → `"Banner created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Marvel `BannerResource` | `id`, `title` (string), `slug` (derived from English title), `description`, `image{desktop, mobile}` (URLs or omitted when empty via `when()`), `status` (bool), `products` (array — loaded) |

```json
{
  "status": 200,
  "message": "Banner created successfully",
  "success": true,
  "data": {
    "id": 1,
    "title": "Summer Sale",
    "slug": "summer-sale",
    "description": null,
    "image": { "desktop": null, "mobile": null },
    "status": true,
    "products": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token (`auth:sanctum` before validation) | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_create_banner`, `postJson`) | See 401 example |
| 403 | Authenticated caller lacks `create-banners` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-banners` (verified by `test_user_without_create_permission_gets_forbidden_for_store`) | See 403 example |
| 422 | `BannerCreateRequest` fails (missing `title`, missing images, bad mime/oversize, bad `products.*`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Images without `title` (verified by `test_create_banner_returns_422_for_missing_title`); title without images (verified by `test_create_banner_returns_422_for_missing_images`) | See 422 example |
| 500 | Repository/transaction failure AFTER validation (image upload failure, DB error) — the live message is swallowed and replaced by the generic key (code-verified) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong"` | None | Post-validation failure (code-traced; no test forces this path) | See 500 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example:

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

422 example (title without images):

```json
{
  "message": "The image desktop field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "image_desktop": ["The image desktop field is required."],
    "image_mobile": ["The image mobile field is required."]
  }
}
```

500 example (post-validation failure):

```json
{
  "status": 500,
  "message": "Something went wrong",
  "success": false
}
```

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

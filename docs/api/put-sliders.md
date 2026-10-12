# Endpoint Name

Sliders – Update Slider (`PUT|PATCH /api/v1/sliders/{slider}`)

# Description

Partially updates a slider with image replacement and returns the bilingual object.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`; tests use `putJson`) |
| Full path | `/api/v1/sliders/{slider}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sliders/{slider}` from `Route::apiResource('sliders', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-slider` gate decides) |
| Controller action | `Marvel\Http\Controllers\SliderController@update` → `SliderRepository::updateSlider` (transaction: row + image replaces + product sync; ANY failure rolls back and rethrows as `HttpException(400, NOT_FOUND)` — then the controller catch-all converts to the 404 envelope; code-verified double-funnel) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-slider` via controller constructor `permission:update-slider` middleware (applies to `update`, `changeStatus`, `reorder`) AND route-level `permission:update-slider` (aligned); the `SliderUpdateRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json`, plus `multipart/form-data` when replacing images |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `sliders.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`SliderUpdateRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional (`sometimes`) — an empty body passes validation and returns 200 with no changes (code-traced). Persisted fields are whatever is supplied (`$slider->update($data)` covers title/status/images/products); any other body fields are ignored. Slug is NEVER recomputed here (no `makeSlug` call — code-verified difference from brands).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json`, or `multipart/form-data` for uploads | None | None | `application/json` | Declares body encoding |
| `slider` | path | integer id (no route-level constraint; resolved by manual `findOrFail` under the `SoftDeletes` scope inside the repository transaction) | Required | No | Raw segment; unknown/trashed ids throw `ModelNotFoundException` → repository catch converts to `HttpException(400, NOT_FOUND)` → controller catch converts to the 404 envelope (double-funnel, code-verified) | Id of a non-trashed slider | None | None | `5` | Identifies the slider to update |
| `title` | body | object (associative array of locale → string) | Optional | No | `sometimes\|array` | Any object whose values each satisfy the per-locale rules | Absent (unchanged) | None | `{"en": "Winter Sale", "ar": "تخفيضات الشتاء"}` | Replacement translations (test-verified change) |
| `title.en` / `title.ar` | body (each locale) | string | Optional per locale | No | Each `sometimes\|string` + `UniqueTranslationRule::for('sliders', 'title')->ignore($this->slider)` (self excluded via the RAW `{slider}` segment — correct here since the segment IS the id; code-verified) | Strings unique across other sliders | Absent (unchanged) | None | `"Winter Sale"` | One locale's new title |
| `image_desktop` / `image_mobile` | body (files) | upload | Optional | No | Each `sometimes\|image\|mimes:jpeg,png,jpg,gif\|max:2048` (2MB; note NO `svg`/`webp` — code-verified difference from brands) | Conforming images | Absent (kept) | Each present file REPLACES its collection via `updateSingleImage`; storage failure throws literal `HttpException(422, ...)` inside the repository, converted by its catch to `HttpException(400, NOT_FOUND)` → controller catch → 404 envelope (code-verified: image failures surface as 404 here, NOT 422) | Binary upload | Replacement images |
| `status` | body | integer 0/1 | Optional | No | `sometimes\|in:1,0` (strict — JSON booleans FAIL; code-traced) | `0`, `1` | Absent (unchanged) | None | `0` (test-verified change) | New active flag |
| `products` | body | array of integer ids | Optional | No | NO validation rules at all on update (code-verified absence — unknown ids are NOT rejected here and surface in the pivot sync/transaction outcome) | Array of product ids | Absent (links untouched) | Present (even `[]`) → pivot SYNCED (code-traced) | `[4, 9]` | Complete replacement product set |

There are no query parameters. Image-failure nuance (code-verified): unlike store (which funnels everything to 500 translated), update failures of ANY kind — including image-storage throws — land in the 404 envelope via the double funnel.

## Request examples

Valid request renaming and deactivating (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/sliders/5",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "title": { "en": "Winter Sale", "ar": "تخفيضات الشتاء" },
    "status": 0
  }
}
```

# Responses

## Success: slider updated — HTTP 200

Returned via `ApiResponse::apiResponse(SLIDER_UPDATED_SUCCESSFULLY, 200, true, SliderResource::make($slider))` with `products` loaded. Verified by `test_authenticated_admin_can_update_slider` (`assertOk`, `success: true`; uses `putJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SLIDER_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Slider updated successfully"`, `ar` → `"تم تحديث السلايدر بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated slider serialized by Marvel `SliderResource` (this route is not named `sliders.index`, so `title` is the `{ar,en}` OBJECT) | `id`, `title{ar, en}`, `slug` (UNCHANGED — never recomputed), `status` (bool), `order`, `image{desktop, mobile}`, `products` (array — present since loaded) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Slider updated successfully",
  "success": true,
  "data": {
    "id": 5,
    "title": { "ar": "تخفيضات الشتاء", "en": "Winter Sale" },
    "slug": "summer-sale",
    "status": false,
    "order": 1,
    "image": { "desktop": "", "mobile": "" },
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/404 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked. Note the two failure shapes: validation 422s (Handler shape with `errors`), and EVERYTHING post-validation funneled to 404 translated (never 500 — the double funnel converts all repository exceptions).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_update_slider` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-slider` permission (BOTH route and constructor gates require it; either rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-slider`; verified by `test_user_without_update_permission_gets_forbidden` expecting 403 | See 403 example |
| 404 | No non-trashed slider matches `{slider}` (manual `findOrFail` miss) — OR ANY post-validation failure (unique collision, image-storage throw, pivot error, transaction fault): the repository converts to `HttpException(400, NOT_FOUND)` and the controller catch-all converts to `apiResponse(NOT_FOUND, 404)` with the translated notice (code-verified double funnel) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — the test asserts status only) | None | Unknown/trashed id (verified by `test_update_slider_returns_404_for_nonexistent_id` for `9999`); any post-validation exception (code-traced) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/sliders/{slider}` (no such routes are registered; note `GET` is the separate show endpoint) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`DELETE` with a segment (code-traced) | See 405 example |
| 422 | Any `SliderUpdateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `title`, `title.{locale}`, `image_*`, `status`; exact messages **Unverified in source** — no update-specific 422 test exists) | Bad field values, duplicate locale name on ANOTHER slider (self excluded via raw segment) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on this action (all repository exceptions funnel to the 404 above — a REAL outage here still renders 404; code-verified consequence).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-slider` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{slider}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/sliders/5`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (non-0/1 `status`):

```json
{
  "message": "The selected status is invalid.",
  "status": false,
  "errors": {
    "status": [
      "The selected status is invalid."
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

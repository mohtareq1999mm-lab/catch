# Endpoint Name

Brands – Update Brand (`PUT|PATCH /api/v1/brands/{brand}`)

# Description

Partially updates a brand with slug recomputation, product sync, and image replacement.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`; tests use `putJson`) |
| Full path | `/api/v1/brands/{brand}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/{brand}` from `Route::apiResource('brands', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-brand` gate decides) |
| Controller action | `Marvel\Http\Controllers\BrandController@update` (merges the path id into the request for the unique-ignore, then `brandUpdate` → `findOrFail` + `updateBrand` in a transaction with rollback, logging, and tag flush) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-brand` via controller constructor `permission:update-brand` middleware (applies to `update` AND `reorder`); the `BrandUpdateRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json`, plus `multipart/form-data` when replacing images |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `brands.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`BrandUpdateRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional (`sometimes`) — an empty body passes validation and returns 200 with no changes (code-traced). Persisted fields are `name`/`details`/`status` (+ recomputed `slug` when `name` present); `products` syncs the pivot; images replace via `updateSingleImage`. Any other body fields are ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json`, or `multipart/form-data` for uploads | None | None | `application/json` | Declares body encoding |
| `brand` | path | integer id (no route-level constraint; resolved by manual `findOrFail`, NOT binding — trashed rows 404 via the `SoftDeletes` scope) | Required | No | Raw segment merged into the request as `id` for BOTH the lookup and the unique-ignore (`$this->route('brand')` — the CORRECT parameter name here, unlike the users-update bug; code-verified) | Id of a non-trashed brand | None | None | `5` | Identifies the brand to update |
| `name` | body | object (associative array of locale → string) | Optional | No | `sometimes\|array`, per-locale `sometimes\|string` + `UniqueTranslationRule::for('brands')->ignore($id)` (self excluded; exact vendor message text **Unverified in source** — the suite stubs this rule to always pass; note NO `max` rule — code-verified absence) | Object with supplied locales as strings | Absent (unchanged) | Triggers slug recomputation (see below) | `{"en": "Adidas", "ar": "أديداس"}` | Replacement names (test-verified change with slug flip to `adidas`) |
| `image-desktop` / `image-mobile` | body (files) | upload | Optional | No | Each `sometimes\|file\|mimes:jpeg,png,jpg,gif,svg\|max:2048` (2MB) | Conforming images | Absent (kept) | Each present file REPLACES its collection via `updateSingleImage`; storage failure throws literal `HttpException(422, 'Logo upload failed...')` (code-verified, untranslated) | Binary upload | Replacement images |
| `details` | body | object | Optional | No | `sometimes\|array`, per-locale `required_with:details\|string\|min:3\|max:2500` | Locales ≥3 ≤2500 chars | Absent (unchanged) | None | `{"en": "Sportswear"}` | Replacement details |
| `status` | body | integer 0/1 | Optional | No | `sometimes\|in:1,0` (strict — JSON booleans FAIL; code-traced) | `0`, `1` | Absent (unchanged) | None | `0` (test-verified change) | New active flag |
| `products` | body | array of integer ids | Optional | No | `sometimes\|array`, per-id `integer\|exists:products,id` (non-positive ids FILTERED OUT silently; code-verified) | Array of existing product ids | Absent (links untouched) | Present (even `[]`) → pivot SYNCED (pass `[]` to detach all — code-traced) | `[4, 9]` | Complete replacement product set |

Slug behavior (code-verified `makeSlug` + model-level regression tests): recomputed ONLY when `name` is present — explicit `slug` field wins, else derived from `name.en` and uniquified; name changes WITHOUT an explicit slug RE-SLUG (`Old Name`→`New Name` flips `old-name`→`new-name`); name-absent updates (e.g. `status` only) leave the slug stable; explicit slug + new name keeps the explicit slug.

There are no query parameters. Creation semantics: single DB transaction (row + pivot sync + image replaces); ANY exception rolls back, logs (`Brand update failed`), and the controller's `catch (\Exception)` converts EVERYTHING — including `QueryException`s — to `MarvelException(NOT_FOUND)` → 404 translated (code-verified over-broad funnel: a post-validation DB failure reports 404, never 500). Frontend `BRANDS` cache tag flushed on success.

## Request examples

Valid request renaming and deactivating (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/brands/5",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Adidas", "ar": "أديداس" },
    "status": 0
  }
}
```

# Responses

## Success: brand updated — HTTP 200

Returned via `ApiResponse::apiResponse(BRAND_UPDATED_SUCCESSFULLY, 200, true, BrandResource::make($brand))` with `products` loaded (re-read via `findOrFail` post-commit). Verified by `test_authenticated_admin_can_update_brand` (`assertOk`, `success: true`, `assertDatabaseHas(['slug' => 'adidas'])`; uses `putJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BRAND_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Brand updated successfully"`, `ar` → `"تم تحديث العلامة التجارية بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated brand serialized by Marvel `BrandResource` (`name` as LOCALE STRING — this route is not named `brands.show`) | `id`, `name`, `slug` (recomputed or stable per rules above), `image{desktop, mobile}`, `details`, `status`, `products` (array — present since loaded) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Brand updated successfully",
  "success": true,
  "data": {
    "id": 5,
    "name": "Adidas",
    "slug": "adidas",
    "image": { "desktop": "", "mobile": "" },
    "details": "Sportswear",
    "status": false,
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/404 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: validation 422s (Handler shape with `errors`), image-storage 422s (literal message, NO `errors` map), and EVERYTHING else funneled to 404 translated (never 500 — the controller catch-all converts all repository exceptions).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_update_brand` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-brand` permission (constructor Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-brand`; verified by `test_user_without_update_permission_gets_forbidden` expecting 403 | See 403 example |
| 404 | No non-trashed brand matches `{brand}` (manual `findOrFail` miss) — OR ANY post-validation failure (unique collision, pivot error, image-storage throw, transaction fault): the controller's `catch (\Exception)` converts EVERYTHING to `MarvelException(NOT_FOUND)` → `Handler` renders 404 translated (code-verified over-broad funnel) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — the test asserts status only) | None | Unknown/trashed id (verified by `test_update_brand_returns_404_for_nonexistent_id` for `9999`); any post-validation exception (code-traced) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/brands/{brand}` (no such routes are registered; note `GET` is the separate show endpoint) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`DELETE` with a segment (code-traced) | See 405 example |
| 422 | Any `BrandUpdateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `name`, `name.{locale}`, `image-*`, `details*`, `status`, `products*`; exact messages **Unverified in source** — no update-specific 422 test exists) | Bad field values, duplicate locale name on ANOTHER brand (self excluded via merged id) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on this action (all repository exceptions funnel to the 404 above — a REAL outage here still renders 404; code-verified consequence).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-brand` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{brand}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/brands/5`):

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

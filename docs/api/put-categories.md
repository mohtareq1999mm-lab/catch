# Endpoint Name

Categories – Update Category (`PUT|PATCH /api/v1/categories/{category}`)

# Description

Partially updates a category with cycle-guarded parenting, slug recomputation, and sync.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`; tests use `putJson`) |
| Full path | `/api/v1/categories/{category}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/{category}` from `Route::apiResource('categories', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-category` gate decides) |
| Controller action | `Marvel\Http\Controllers\CategoryController@update` (merges the path id into the request for the unique-ignore, then `categoryUpdate` → `findOrFail` + `updateCategory` in a transaction with rollback, logging... wait: category `updateCategory` has NO Log call (unlike brands) — verified absence; transaction + rollback only) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-category` via controller constructor `permission:update-category` middleware (applies only to `update` — note: NOT `addOrRemoveCategoryFromFeature`, which carries its own identical gate; both require the same permission); the `CategoryUpdateRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json`, plus `multipart/form-data` when replacing images |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `categories.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`CategoryUpdateRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional (`sometimes`) — an empty body passes validation and returns 200 with no changes (code-traced). Persisted fields are `name`/`details`/`status` (+ recomputed `slug` when `name` present); `products` syncs the pivot; images replace. The unique-ignore resolves `$this->route('id') ?? $this->route('category') ?? $this->input('id')` — the apiResource param is `{category}` (raw segment) AND the controller merges path id into input, so self-exclusion works by two paths (code-verified, unlike the users-update bug).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json`, or `multipart/form-data` for uploads | None | None | `application/json` | Declares body encoding |
| `category` | path | integer id (no route-level constraint; resolved by manual `findOrFail` under the `SoftDeletes` scope) | Required | No | Raw segment (also merged into input as `id`); unknown/trashed ids throw `ModelNotFoundException`, which ESCAPES the `MarvelException`-only controller catch → `Handler` 404 fixed string (code-verified — the miss does NOT take the translated path) | Id of a non-trashed category | None | None | `9` | Identifies the category to update |
| `name` | body | object (associative array of locale → string) | Optional | No | `sometimes\|array`, per-locale `sometimes\|string` + `UniqueTranslationRule::for('categories')->ignore($id)` (self excluded; exact vendor message text **Unverified in source** — sibling suites stub this rule to always pass; note NO `max` rule — code-verified absence) | Object with supplied locales as strings | Absent (unchanged) | Triggers slug recomputation (see below) | `{"en": "Sneakers"}` | Replacement names |
| `image-desktop` / `image-mobile` | body (files) | upload | Optional | No | Each `sometimes\|file\|mimes:jpeg,png,jpg,gif,svg\|max:2048` (2MB) | Conforming images | Absent (kept) | Each present file REPLACES its collection via `updateSingleImage`; storage failure throws literal `HttpException(422, ...)` inside the repository, converted by its catch to `HttpException(500, live-message)` → escapes the controller's `MarvelException`-only catch → `Handler` renders 500 + LIVE text (code-verified chain) | Binary upload | Replacement images |
| `parent_id` | body | integer | Optional | Yes (`nullable`) | `nullable\|integer\|exists:categories,id` + CLOSURE anti-cycle rule: assigning a parent that `createsCycle(id, value)` fails with `ERROR.CATEGORY_CIRCULAR_REFERENCE` (translated message — key verified, exact strings **Unverified in source**); null/skipped-id short-circuits the check (code-verified) | Existing category id, null (reroot), or absent | Absent (unchanged) | None | `4` | New parent (hierarchy level re-derived server-side — code-traced service behavior) |
| `details` | body | string (NOT a translations object — code-verified difference from brands) | Optional | No | `sometimes\|string\|min:3\|max:2500` | Strings ≥3 ≤2500 chars | Absent (unchanged) | None | `"Top sneakers"` | Replacement details text |
| `status` | body | integer 0/1 | Optional | No | `sometimes\|in:1,0` (strict — JSON booleans FAIL; code-traced) | `0`, `1` | Absent (unchanged) | None | `0` | New active flag |
| `products` | body | array of integer ids | Optional | No | `sometimes\|array`, per-id `exists:products,id` (non-integer ids fail; non-positive ids are NOT filtered here unlike brands — code-verified absence of the `array_filter` guard) | Array of existing product ids | Absent (links untouched) | Present (even `[]`) → pivot SYNCED (code-traced) | `[4, 9]` | Complete replacement product set |

There are no query parameters. Any extra body fields are ignored.

Slug behavior (code-verified `makeSlug` + `isset($data['name'])` gate): recomputed ONLY when `name` is present — explicit `slug` field wins, else derived from `name.en` and uniquified; name-absent updates leave the slug stable.

Creation semantics: single DB transaction (row + pivot sync + image replaces); the repository catch converts `ValidationException`→rethrow, everything else→`HttpException(500, live-message)`; the controller catch converts `MarvelException`→NOT_FOUND (effectively dead for repository failures, which arrive as `HttpException` and escape to `Handler`). Frontend `CATEGORIES` cache tag flushed on success.

## Request examples

Valid request renaming a category (test-verified call shape):

```json
{
  "method": "PUT",
  "url": "/api/v1/categories/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Sneakers" }
  }
}
```

# Responses

## Success: category updated — HTTP 200

Returned via `ApiResponse::apiResponse(CATEGORY_UPDATED_SUCCESSFULLY, 200, true, CategoryResource::make($category))` with `products` loaded (re-read post-commit). The category suites assert 200s on this URI across auth/authz files (all use `putJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CATEGORY_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Category updated successfully"`, `ar` → `"تم تحديث الفئة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated category serialized by Marvel `CategoryResource` (`name` as LOCALE STRING — this route is not named `categories.show`) | `id`, `name`, `slug` (recomputed or stable per rules above), `parent_id`, `parent`, `level`, `image{desktop, mobile}`, `is_featured`, `products_count`, `status`, `details`, `products` (array — present since loaded) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Category updated successfully",
  "success": true,
  "data": {
    "id": 9,
    "name": "Sneakers",
    "slug": "sneakers",
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

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified through the auth/authz suites (all use `putJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: validation 422s (Handler shape with `errors`), image-storage 422-literals (untranslated, no map), and repository `HttpException(500, live-text)` failures rendering 500 with LIVE text.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_update_category` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-category` permission (constructor Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-category` (no update-specific 403 test found in this pass; identical constructor-gate path test-verified on sibling category routes) | See 403 example |
| 404 | No non-trashed category matches `{category}`: manual `findOrFail` throws `ModelNotFoundException`, which ESCAPES the `MarvelException`-only controller catch → `Handler` renders its `ModelNotFoundException` branch (code-verified — fixed string, NOT the translated notice) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown/trashed id (code-traced — no update-specific 404 test found in this pass) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/categories/{category}` (no such routes are registered; note `GET` is the separate show endpoint) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`DELETE` with a segment (code-traced) | See 405 example |
| 422 | Any `CategoryUpdateRequest` validation failure (rules + parent-cycle closure), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `name`, `name.{locale}`, `image-*`, `parent_id`, `details`, `status`, `products*`; exact messages **Unverified in source** — the Validation suite covers partitions of these) | Bad field values, duplicate locale name on ANOTHER row (self excluded), cyclic `parent_id` | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Repository failure wrapped as `HttpException(500, live-message)` (unique collisions, pivot errors, image-storage throws, transaction faults): escapes the controller's `MarvelException`-only catch → `Handler` `HttpException` branch renders 500 with the LIVE text (code-verified — NOT the translated constant) | `{message: string, status: boolean}` | None | Live exception/operation text (dynamic, **Unverified in source** per case) | None | Post-validation persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-category` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{category}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/categories/9`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (cyclic `parent_id` — message file-verified):

```json
{
  "message": "The selected parent category creates a circular reference.",
  "status": false,
  "errors": {
    "parent_id": [
      "The selected parent category creates a circular reference."
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

500 example (post-validation persistence failure; text dynamic):

```json
{
  "message": "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'sneakers' for key 'categories.slug'",
  "status": false
}
```

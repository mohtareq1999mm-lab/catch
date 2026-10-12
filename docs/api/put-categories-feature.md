# Endpoint Name

Categories – Feature Toggle (`PUT /api/v1/categories/feature`)

# Description

Flips a category's featured flag by body id and returns a dataless confirmation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/categories/feature` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/feature` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, with route-level `permission:update-category` ON TOP of the constructor `update-category` gate (aligned — both require the same permission) |
| Controller action | `Marvel\Http\Controllers\CategoryController@addOrRemoveCategoryFromFeature` (`Category::find` → flip → save; the apparent null-deref on unknown ids is UNREACHABLE through HTTP — validation rejects unknown ids first with 422; code-verified order) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `update-category` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route (the pasted line carries no `->name()`) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`CategoryFeatureToggleRequest` validation runs before the method body (`failedValidation` rethrows → 422). Only `id` is read; any extra body fields are ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `id` | body | integer | Required | No | `required\|integer\|exists:categories,id` (table-level check WITHOUT Eloquent scopes — a trashed id PASSES validation; the subsequent `find` (also scopeless — plain `Category::find`, no SoftDeletes exclusion... wait: `Category::find` DOES apply the SoftDeletes global scope, so a trashed id returns null → null-deref fatal → 500. Hmm: validation passes (scopeless exists), find returns null (scoped), `->is_featured` on null → Error → Handler 500. Code-verified chain — trashed id yields 500, NOT 404. Flagged below) | Id of an existing category row | None | None | `9` | Identifies the category to flip |

There are no path parameters, query parameters, multipart fields, or file uploads.

## Request examples

Valid request flipping the flag (test-verified call shape):

```json
{
  "method": "PUT",
  "url": "/api/v1/categories/feature",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "id": 9
  }
}
```

# Responses

## Success: flag flipped — HTTP 200

Returned via `ApiResponse::apiResponse(CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent — the NEW flag value is NOT returned (client must re-read; code-verified). Verified by `test_toggle_featured_works_with_update_permission` (`assertOk`, exact message assertion, refreshed `is_featured === true`) and `test_toggle_featured_twice_reverts` (symmetric flip proven; both use `putJson`, matching this route). There is exactly one success contract. Frontend `CATEGORIES` cache tag flushed on success.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY` — exact `en` text test-verified | `en` → `"Category feature toggled successfully"`, `ar` → `"تم تبديل ميزة الفئة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Category feature toggled successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/422 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no feature-specific 401 test exists; identical rejection test-verified on sibling category routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `update-category` permission (BOTH route and constructor gates require it; either rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-category`; verified by `test_toggle_featured_requires_update_permission` expecting 403 | See 403 example |
| 405 | Any HTTP method other than `PUT` on `/api/v1/categories/feature` (only `Route::put` is registered — no `PATCH` twin) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 422 | Any `CategoryFeatureToggleRequest` failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `id` → message array (exact messages **Unverified in source**) | Missing/non-integer `id` (verified by `test_toggle_featured_validates_id_required` with `assertJsonValidationErrors(['id'])`) or unknown id (verified by `test_toggle_featured_validates_id_exists` for `99999` — proving the null-deref is unreachable: validation 422s first) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Trashed-id null dereference: a TRASHED id passes the scopeless `exists` check, then `Category::find` (scoped) returns null and `->is_featured` fatals with `Error`, rendered by the generic branch of `Handler` (code-verified chain — the method has no try/catch; no test covers a trashed id here) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Trashed `{id}` only | See 500 example |

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

405 example (wrong method, e.g. `POST /api/v1/categories/feature`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `id`):

```json
{
  "message": "The id field is required.",
  "status": false,
  "errors": {
    "id": [
      "The id field is required."
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

500 example (trashed `{id}`; `app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

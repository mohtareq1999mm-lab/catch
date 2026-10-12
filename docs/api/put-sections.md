# Endpoint Name

Sections – Update Section (`PUT|PATCH /api/v1/sections/{section}`)

# Description

Partially updates a section's fields and returns the section with resolved settings.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`; tests use `putJson`) |
| Full path | `/api/v1/sections/{section}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sections/{section}` from `Route::apiResource('sections', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\SectionController@update` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-sections` via controller constructor `permission:update-sections` middleware (applies to `update`, `reorder`, `toggleStatus`); the `UpdateSectionRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `sections.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`. Implicit binding resolves `{section}` before validation, so an unknown id 404s even with an invalid body (code-verified order).

## Request inputs

`UpdateSectionRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional (`sometimes`/`nullable`) — an empty body passes validation and returns 200 with no changes (code-traced). NOTE what is NOT updatable here: `type`, `endpoint`, `order` (use reorder), and `content_page_id` (use attach-sections) have no rules and are silently ignored — only the fields below persist.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `section` | path | integer id (implicit route-model binding by primary key) | Required | No | Resolved by Laravel before validation; unknown ids throw `ModelNotFoundException` → `Handler` 404 | Id of an existing section | None | None | `11` | Identifies the section to update |
| `title` | body | object (associative array of locale → string) | Optional | No | `sometimes\|array`, plus per-locale `title.*`: `sometimes\|string\|max:50` (no uniqueness rule on update — code-verified difference from store, which has none either; titles are NOT unique-checked here at all) | Object with each supplied locale a string ≤50 chars | Absent (unchanged) | None | `{"en": "New Title"}` | Replacement translations (merged per locale) |
| `is_active` | body | boolean/integer | Optional | No | `sometimes\|in:0,1` (JSON booleans coerce loosely and pass — same mechanism as store) | `0`, `1` (or loose boolean equivalents) | Absent (unchanged) | None | `0` | New active flag (test-verified change) |
| `title_visible` | body | boolean/integer | Optional | No | `sometimes\|in:0,1` | `0`, `1` | Absent (unchanged) | None | `1` | New title-visibility flag |
| `order` | body | integer | Optional | No | `sometimes\|integer` | Any integer | Absent (unchanged) | None | `3` | Direct position override — `order` IS fillable and present in validated data, so it persists (unlike `type`/`endpoint`/`content_page_id`, which have no rules and are dropped by `validated()`); bypasses `setNewOrder` semantics |
| `setting` | body | object | Optional | Yes (`nullable`) | `nullable\|array`, with `setting.front` / `setting.back` each `nullable\|array` when present | Arbitrary nested arrays | Absent (unchanged); explicit `null` clears to null (then resource falls back to type defaults — code-traced) | None | `{"front": {}, "back": {}}` | Replacement settings payload |
| `with_product` | body | boolean | Optional | N/A | NO rule (validation-only flag for the `after` hook; never persisted) | Truthy to trigger the restriction | Absent → hook falls back to the section's STORED `with_product` attribute (which has no column — always falsy in practice; code-verified, so the restriction effectively triggers only when the request itself carries it) | When truthy (from input or stored value), `setting.back` may contain ONLY the `slug` key | `true` | Product-scoped section marker |

The `title` fallback quirk from store (`!isset($data['title']) && $request->has('title')`) is repeated here but equally unreachable in practice (validated `title` is either present or absent wholesale).

There are no query parameters, multipart fields, or file uploads. Any extra body fields (notably `type`, `endpoint`, `content_page_id`) are silently ignored.

## Request examples

Valid request deactivating a section (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/sections/11",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "is_active": 0
  }
}
```

# Responses

## Success: section updated — HTTP 200

Returned via `ApiResponse::apiResponse(SECTION_UPDATED_SUCCESSFULLY, 200, true, PagesSectionResource::make($section))` after persisting and `loadMissing('sectionType.settings')`. Verified by `test_admin_can_update_section` (`assertOk`, `success: true`, refreshed `is_active === false`; uses `putJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SECTION_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Section updated successfully"`, `ar` → `"تم تحديث القسم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated section serialized by `App\Http\Resources\Pages\SectionResource` | `id`, `type` (unchanged — not updatable), `title`, `is_active`, `endpoint`, `order`, `setting{front, back}` |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Section updated successfully",
  "success": true,
  "data": {
    "id": 11,
    "type": "banner",
    "title": "Test Section",
    "is_active": false,
    "endpoint": "general/banner?",
    "order": 1,
    "setting": { "front": [], "back": [] }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/404 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_update_section` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-sections` permission (Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-sections`; verified by `test_user_without_update_sections_gets_forbidden` expecting 403 | See 403 example |
| 404 | No section matches `{section}`: implicit binding throws `ModelNotFoundException` before validation runs → `Handler` renders its `ModelNotFoundException` branch (takes precedence over 422 — code-verified order) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id (verified by `test_update_section_returns_404_for_nonexistent` for id `9999` with a valid body) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/sections/{section}` (no such routes are registered; note `GET` is the separate show endpoint and the suffixed PATCH URI is a separate action) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`DELETE` with an id segment (code-traced) | See 405 example |
| 422 | Any `UpdateSectionRequest` validation failure (including the `with_product` after-hook), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `title`, `title.{locale}`, `order`, `is_active`, `title_visible`, `setting*`, `setting.back`; exact messages **Unverified in source** — no update-specific 422 test exists) | Non-array `title`, non-string/over-50 locale value, non-integer `order`, non-0/1 flags, non-array `setting*`, extra `setting.back` keys when `with_product` truthy | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in update/serialize), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-sections` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{section}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/sections/11`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (non-integer `order`):

```json
{
  "message": "The order must be an integer.",
  "status": false,
  "errors": {
    "order": [
      "The order must be an integer."
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

# Endpoint Name

Static Pages – Reorder Sections (`POST /api/v1/static-pages/{static_page:slug}/sections/reorder`)

# Description

Persists a page-scoped section order, rejecting foreign or duplicate ids with 404.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/static-pages/{slug}/sections/reorder` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `static-pages/{static_page:slug}/sections/reorder` from the static-pages group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group. Declared before the `{static_section}` member routes (per the code comment) — though methods differ (`POST` vs `PUT`/`DELETE`), so no capture could occur either way; the ordering is defense-in-depth |
| Controller action | `Marvel\Http\Controllers\StaticPageController@reorderSections` → `StaticPageService::reorderSections` (page-scoped count check + page-scoped `setNewOrder` in a transaction) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-static-sections` via controller constructor `permission:update-static-sections` middleware (applies to `updateSection`, `reorderSections`); the `ReorderStaticSectionsRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`. Implicit binding resolves `{static_page:slug}` before validation, so an unknown slug 404s even with an invalid body (code-verified order).

## Request inputs

`ReorderStaticSectionsRequest` validation runs before the method body (`failedValidation` rethrows → 422). Only `sections` is read; any extra body fields are ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `static_page` | path | slug string (implicit route-model binding on the `slug` column) | Required | No | Resolved by Laravel before validation; unknown slugs throw `ModelNotFoundException` → `Handler` 404 | An existing page `slug` | None | None | `about-us` | Page whose sections are reordered, by slug |
| `sections` | body | array of integer ids, in desired display order (first = order 1) | Required | No | `required\|array` | Non-empty array of ids (emptiness NOT rejected here — but an empty array passes validation and then fails the service count check... see below: `count([]) = 0` vs `existingCount = 0` → EQUAL → proceeds to reorder nothing → 200. Code-traced: empty array returns 200 with no changes) | None | None | `[22, 21]` | Complete new page-scoped ordering |
| `sections.*` | body (each element) | integer id reference | Required for every element | No | `required\|integer\|distinct\|exists:static_sections,id` (GLOBAL existence — page ownership is enforced later by the service, not by validation) | Ids of existing sections, no duplicates | None | None | `22` | One section placed at its index position |

Service check (code-verified, AFTER validation): the count of DISTINCT supplied ids belonging to THIS page must equal the distinct count — foreign ids, duplicate ids (already rejected by `distinct`, defense-in-depth), and unknown ids (already rejected by `exists`) throw `ModelNotFoundException` → the method's catch returns the 404 ENVELOPE. In practice only cross-page ids reach this check (a section of another page passes `exists` but fails the page scope — anti-leak: reported as 404, never confirmed). The reorder itself is page-scoped (`setNewOrder` constrained to `static_page_id`) in a transaction. Frontend `static_pages` cache tag flushed on success.

There are no query parameters, multipart fields, or file uploads.

## Request examples

Valid request reordering two sections (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/static-pages/about-us/sections/reorder",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "sections": [22, 21]
  }
}
```

# Responses

## Success: order persisted — HTTP 200

Returned via `ApiResponse::apiResponse(STATIC_SECTIONS_REORDERED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_update_section_permission_allows_update_and_reorder` and `test_full_permissions_allows_all_admin_operations` (both `assertOk` on this URI; `postJson`, matching this route). There is exactly one success contract.

Complete success example:

```json
{
  "status": 200,
  "message": "Static sections reordered successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.STATIC_SECTIONS_REORDERED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Static sections reordered successfully"`, `ar` → `"تمت إعادة ترتيب أقسام الصفحة الثابتة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: binding misses use the Handler fixed string, ownership misses use the 404 envelope, validation uses the 422 shape.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_cannot_reorder_sections` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-static-sections` permission (Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-static-sections` (no reorder-specific 403 test exists — the 403 matrix covers update/delete siblings; identical middleware path) | See 403 example |
| 404 | Unknown page slug: implicit binding throws `ModelNotFoundException` before validation runs → `Handler` renders its `ModelNotFoundException` branch (takes precedence over 422 — code-verified order) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown slug (code-traced — no endpoint-specific 404 test exists) | See 404 example (binding) |
| 404 | Supplied id belongs to ANOTHER page (or vanished after validation): the service count check throws `ModelNotFoundException`, caught by the method → `apiResponse(NOT_FOUND, 404, false)` envelope (anti-leak by design) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — no test exercises cross-page reorder) | None | Valid ids with mismatched page ownership | See 404 example (ownership) |
| 405 | `GET`, `PUT`, `PATCH`, or `DELETE` on `/api/v1/static-pages/{slug}/sections/reorder` (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `ReorderStaticSectionsRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `sections` / `sections.{index}` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no reorder-specific 422 test exists) | Missing/non-array `sections`, non-integer element, duplicate element (`distinct`), or id of a nonexistent section (`exists`) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any exception inside the try block other than the caught `ModelNotFoundException` (e.g. transaction failure), caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403/422 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Service failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-static-sections` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (binding miss — unknown slug):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404 example (ownership miss — foreign section id; `en` locale):

```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

405 example (wrong method, e.g. `GET /api/v1/static-pages/about-us/sections/reorder`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (unknown section id):

```json
{
  "message": "The selected sections.0 is invalid.",
  "status": false,
  "errors": {
    "sections.0": [
      "The selected sections.0 is invalid."
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

500 example (service failure inside the try):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

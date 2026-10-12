# Endpoint Name

Content Pages – Attach Sections (`POST /api/v1/content-pages/{content_page}/attach-sections`)

# Description

Replaces a page's section attachments with the given ids, or detaches all when empty.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/content-pages/{content_page}/attach-sections` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `content-pages/{content_page}/attach-sections` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group. Registered before `apiResource('content-pages')`; no capture conflict (longer URI) |
| Controller action | `Marvel\Http\Controllers\ContentPageController@attachSections` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-content-pages` via controller constructor `permission:update-content-pages` middleware (applies to `update`, `attachSections`, `toggleActive`); the `AttachSectionsRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`AttachSectionsRequest` validation runs before the method body. `prepareForValidation` normalizes a missing/null `sections` to `[]` (code-verified) — so omitting the field detaches everything, exactly like sending `[]`. The request has NO `failedValidation` override, but the framework default throws `ValidationException` (verified in vendor `FormRequest:152-159`), so failures still render through `Handler` with the standard 422 shape.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `content_page` | path | integer id (implicit route-model binding by primary key) | Required | No | Resolved by Laravel before validation runs; unknown ids throw `ModelNotFoundException` → `Handler` 404 (the method has no try/catch at all) | Id of an existing page | None | None | `4` | Identifies the page receiving the sections |
| `sections` | body | array of integer ids | Effectively required but auto-defaulted | No | `present\|array` — the `prepareForValidation` hook injects `[]` when missing/null, so the rule always sees an array | Array of existing section ids (possibly empty) | `[]` (injected when absent) | None | `[11, 12]` | Complete replacement attachment set |
| `sections.*` | body (each element) | integer id reference | Required for every element | No | `integer\|exists:sections,id` (NO `distinct` rule — duplicates accepted and harmlessly re-saved; code-verified) | Ids of existing sections (any page or unattached) | None | None | `11` | One section to attach (re-parented via `content_page_id` overwrite — sections are MOVED, not copied) |

There are no query parameters, multipart fields, or file uploads. Any extra body fields are ignored.

Two behaviors (code-verified branch): non-empty `sections` → each section's `content_page_id` is overwritten with this page's id (via `attachSectionsByIds`, which silently SKIPS unknown ids — but validation rejects them first, so the skip path is unreachable through this endpoint), the frontend content-pages cache tag is flushed EXPLICITLY (required because the detach path uses a query-builder update firing no model events — per the code comment), and the page is returned with sections loaded; empty `sections` → ALL currently attached sections are detached (`content_page_id = null`) and a dataless `DELETE_DATA_SUCCESSFULLY` 200 is returned.

## Request examples

Valid request attaching two sections (test-verified call shape):

```json
{
  "method": "POST",
  "url": "/api/v1/content-pages/4/attach-sections",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "sections": [11, 12]
  }
}
```

Valid request detaching all sections:

```json
{
  "method": "POST",
  "url": "/api/v1/content-pages/4/attach-sections",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "sections": []
  }
}
```

# Responses

## Success: sections attached — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ContentPageResource::make($content_page))` with sections loaded. Verified by `test_admin_can_attach_sections_to_content_page` (`assertOk`, `success: true`, `assertDatabaseHas('sections', ... 'content_page_id' => page)` for both; uses `postJson`, matching this route). There are two success contracts (attached vs. detached-all).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated message (locale from `lang` header) | Non-empty: `en` → `"Data updated successfully"`, `ar` → `"تم تحديث البيانات بنجاح"`; empty: `en` → `"Data deleted successfully"`, `ar` → `"تم حذف البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Present ONLY on the non-empty path | The page serialized by `App\Http\Resources\Pages\ContentPageResource` with the newly attached sections | `id`, `title` (locale string), `slug`, `is_active`, `sections` (array); ABSENT on the detach-all path (dataless response) |

Complete success example (non-empty path; `en` locale):

```json
{
  "status": 200,
  "message": "Data updated successfully",
  "success": true,
  "data": {
    "id": 4,
    "title": "Test Page",
    "slug": "test-page",
    "is_active": true,
    "sections": []
  }
}
```

Complete success example (detach-all path; `en` locale):

```json
{
  "status": 200,
  "message": "Data deleted successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/422 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_attach_sections` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-content-pages` permission (Spatie middleware rejects before validation runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-content-pages`; verified by `test_user_without_update_content_pages_gets_forbidden_for_attach_sections` expecting 403 | See 403 example |
| 404 | No page matches `{content_page}`: implicit binding throws `ModelNotFoundException` before validation runs → `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id (code-traced — no endpoint-specific 404 test exists; note it precedes even 422) | See 404 example |
| 405 | Any HTTP method other than `POST` on this exact URI (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 422 | Any `AttachSectionsRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `sections` / `sections.{index}` → message arrays (key verified from rule targets; exact messages **Unverified in source**) | Non-integer element or id of a nonexistent section (verified by `test_attach_sections_returns_422_for_invalid_section_ids` for `[9999]`); a non-array `sections` CANNOT occur (normalized to `[]` first — code-verified) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in attach/detach/serialize), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-content-pages` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{content_page}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/content-pages/4/attach-sections`):

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

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

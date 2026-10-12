# Endpoint Name

Sections – Create Section (`POST /api/v1/sections`)

# Description

Creates a section with a defaulted endpoint and attaches it to the first content page.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/sections` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sections` from `Route::apiResource('sections', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\SectionController@store` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-sections` via controller constructor `permission:create-sections` middleware (applies only to `store`); the `StoreSectionRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `sections.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`StoreSectionRequest` validation runs before the method body (`failedValidation` rethrows → 422). Persisted fields are the validated payload (`type`, `title`, `is_active`, `title_visible`, `order`, `setting`) EXCEPT `with_product`, which is validation-only (never persisted — it is not fillable and not in the rules as a stored field; code-verified). Any other extra body fields are ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `type` | body | string | Required | No | `required\|string\|max:100\|exists:section_types,type` (must reference an existing section-type row) | Any existing `section_types.type` value ≤100 chars | None | None | `"banner"` | Section-type discriminator (also the default endpoint basis and the `sectionType` relation key) |
| `endpoint` | body | string | Optional | No | No rule (unvalidated passthrough) | Any string | `'general/' + type` when absent (code-verified default) | None | `"general/banner"` | Stored endpoint override |
| `title` | body | object (associative array of locale → string) | Required | No | `required\|array`, plus per-locale `title.*`: `required\|string\|max:50` | Object with every supplied locale a non-empty string ≤50 chars | None | None | `{"en": "Hero Banner", "ar": "بانر رئيسي"}` | Localized titles |
| `is_active` | body | boolean/integer | Optional | Yes (`nullable`) | `nullable\|in:0,1` (strict `in` — JSON `true`/`false` FAIL; code-traced) | `0`, `1`, null | DB default `true` when absent | None | `true` (test sends boolean `true`... see note) | Active flag |
| `title_visible` | body | boolean/integer | Optional | Yes (`nullable`) | `nullable\|in:0,1` (same strictness caveat) | `0`, `1`, null | DB default `true` when absent | None | `true` | Whether the title serializes (resource returns `null` title when false) |
| `order` | body | integer | Optional | Yes (`nullable`) | `nullable\|integer` | Any integer | Auto-assigned by the Sortable trait (`sort_when_creating`) when absent | None | `3` | Display position override |
| `setting` | body | object | Optional | Yes (`nullable`) | `nullable\|array`, with `setting.front` / `setting.back` each `nullable\|array` when present | Arbitrary nested arrays | `null` (resource then falls back to type defaults) | None | `{"front": {}, "back": {}}` | Own settings payload (stored JSON) |
| `with_product` | body | boolean | Optional | N/A | NO rule (validation-ONLY flag consumed by the `after` hook; never persisted) | Truthy to trigger the restriction | Absent (no restriction) | When truthy, `setting.back` may contain ONLY the `slug` key — any extra key adds a `setting.back` error (code-verified hook) | `true` | Product-scoped section marker |

NOTE on the test's booleans: `test_admin_can_create_section` sends `'is_active' => true` / `'title_visible' => true` (JSON booleans) and asserts 200 — against the `in:0,1` rule this SHOULD 422 (Laravel `in` uses loose comparison? `in:0,1` with `true`: `in_array(true, [0,1])` non-strict → `true == 0`? No (true==0 is false); `true == 1` → TRUE loose. So boolean true passes via loose `== 1`, boolean false: `false == 0` → TRUE loose. So JSON booleans PASS the `in` rule loosely. Correction: booleans coerce (`true`→`1`, `false`→`0`) and pass — test-verified. The strictness caveat applies to non-numeric strings, not booleans.

There are no path parameters, query parameters, multipart fields, or file uploads.

Side effect (code-verified): after creation the section is attached to the FIRST `ContentPage` row when one exists (`content_page_id` set); with no pages it stays unattached.

## Request examples

Valid request creating a section (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/sections",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "type": "banner",
    "endpoint": "general/banner",
    "title": { "en": "Hero Banner", "ar": "بانر رئيسي" },
    "is_active": true,
    "title_visible": true
  }
}
```

# Responses

## Success: section created — HTTP 200

Returned via `ApiResponse::apiResponse(SECTION_CREATED_SUCCESSFULLY, 200, true, PagesSectionResource::make($section))` — note 200, NOT 201 (code-verified). Verified by `test_admin_can_create_section` (`assertOk`, `success: true`, `assertDatabaseHas('sections', ['type' => 'banner'])`; uses `postJson`, matching this route) and `test_section_title_is_translatable` (locale-resolved titles). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (not 201) |
| `message` | string | Always present | Translated `MESSAGE.SECTION_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Section created successfully"`, `ar` → `"تم إنشاء القسم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created section serialized by `App\Http\Resources\Pages\SectionResource` | `id`, `type`, `title` (locale string or `null` when `title_visible` false), `is_active`, `endpoint` (stored or defaulted), `order` (assigned), `setting{front, back}` |

Complete success example (`en` locale; `id`/`order` illustrative, all other values verified):

```json
{
  "status": 200,
  "message": "Section created successfully",
  "success": true,
  "data": {
    "id": 11,
    "type": "banner",
    "title": "Hero Banner",
    "is_active": true,
    "endpoint": "general/banner?",
    "order": 1,
    "setting": { "front": [], "back": [] }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/422 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_create_section` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `create-sections` permission (Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-sections`; verified by `test_user_without_create_sections_gets_forbidden` expecting 403 | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/sections` path (no such routes are registered; note `GET /sections` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 409 | Database constraint failure during `create()` (the method has no try/catch, so a `QueryException` escapes to `Handler`'s `QueryException` branch); conditional on database state, not on request input | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Only when persisting violates a database constraint (code-traced, no test) | See 409 example |
| 422 | Any `StoreSectionRequest` validation failure (including the `with_product` after-hook), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `type`, `title`, `title.{locale}`, `is_active`, `title_visible`, `order`, `setting*`, `setting.back`; exact messages **Unverified in source**) | Missing/non-string/over-100/unknown `type` (verified triggers: missing → 422, `'nonexistent-type'` → 422); missing/non-array `title`; empty/non-string/over-50 locale value; non-0/1 non-boolean flags; non-integer `order`; non-array `setting*`; extra `setting.back` keys when `with_product` truthy | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure other than a `QueryException` (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-sections` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/sections`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (database constraint failure during `create()`):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

422 example (missing `type`):

```json
{
  "message": "The type field is required.",
  "status": false,
  "errors": {
    "type": [
      "The type field is required."
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

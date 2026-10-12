# Endpoint Name

Section Types – Create Type (`POST /api/v1/section-types`)

# Description

Creates a section-type row and returns the raw model.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/section-types` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `section-types` from `Route::apiResource('section-types', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\SectionTypeController@store` → `SectionTypeService::createType` (`SectionType::create`, NO resource wrapper — the raw model serializes) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-section-types` via controller constructor `permission:create-section-types` middleware (applies only to `store`); the `StoreSectionTypeRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `section-types.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`StoreSectionTypeRequest` validation runs before the method body (`failedValidation` rethrows → 422). Only `type` is read; any extra body fields are silently ignored (`createType` receives `$request->validated()`, and the model fills ONLY `type` — mass-assignment test-verified pattern on the sibling endpoint).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `type` | body | string | Required | No | `required\|string\|max:100\|unique:section_types,type` | Any string ≤100 chars not already used | None | None | `"hero"` | New type discriminator (also future sections' relation key) |

There are no path parameters, query parameters, multipart fields, or file uploads.

## Request examples

Valid request creating a type (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/section-types",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "type": "hero"
  }
}
```

# Responses

## Success: type created — HTTP 200

Returned via `ApiResponse::apiResponse(TYPE_CREATED_SUCCESSFULLY, 200, true, $type)` — note 200, NOT 201, and `$type` is the RAW `SectionType` model (no resource): `{id, type, created_at, updated_at}` (timestamps via default Eloquent behavior — code-traced). Verified by `test_admin_can_create_section_type` (`assertOk`, `success: true`, `assertDatabaseHas('section_types', ['type' => 'hero'])`; uses `postJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (not 201) |
| `message` | string | Always present | Translated `MESSAGE.TYPE_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Type created successfully"`, `ar` → `"تم إنشاء النوع بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Raw created row | `id`, `type`, `created_at`, `updated_at` (exact timestamp format **Unverified in source**) |

Complete success example (`en` locale; `id`/timestamps illustrative):

```json
{
  "status": 200,
  "message": "Type created successfully",
  "success": true,
  "data": {
    "id": 7,
    "type": "hero",
    "created_at": "2026-10-10T12:00:00.000000Z",
    "updated_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/422 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked. Note the two different failure envelopes: validation escapes to `Handler` (422 shape), while service failures are caught in-method (500 envelope).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_create_section_type` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `create-section-types` permission (Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-section-types`; verified by `test_user_without_create_section_types_gets_forbidden` expecting 403 | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/section-types` path (no such routes are registered; note `GET /section-types` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `StoreSectionTypeRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `type` → message array (exact messages **Unverified in source**) | Missing/empty (`''` — verified by `test_create_section_type_returns_422_for_empty_type`), non-string, over-100, or duplicate `type` (verified by `test_create_section_type_returns_422_for_duplicate_type` for an existing `'hero'`) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any exception inside `createType` (e.g. a race on the `type` UNIQUE constraint passing validation then colliding), caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403/422 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Service/persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-section-types` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/section-types`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (duplicate `type`):

```json
{
  "message": "The type has already been taken.",
  "status": false,
  "errors": {
    "type": [
      "The type has already been taken."
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

500 example (service failure inside `createType`):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

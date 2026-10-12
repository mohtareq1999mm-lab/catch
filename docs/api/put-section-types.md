# Endpoint Name

Section Types – Update Type (`PUT|PATCH /api/v1/section-types/{section_type}`)

# Description

Renames a section type by its current type string and returns the raw row.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`; tests use `putJson`) |
| Full path | `/api/v1/section-types/{section_type}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `section-types/{section_type}` from `Route::apiResource('section-types', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\SectionTypeController@update` → `SectionTypeService::updateType` (find-by-ID then update; the bound model's id is forwarded — the lookup-by-string happens only in binding) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-section-types` via controller constructor `permission:update-section-types` middleware (applies to `update`, `updateSettings`); the `UpdateSectionTypeRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `section-types.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`. Implicit binding resolves `{section_type}` by the `type` column before validation, so an unknown type 404s even with an invalid body (code-verified order).

## Request inputs

`UpdateSectionTypeRequest` validation runs before the method body (no `failedValidation` override, but the framework default throws `ValidationException` — verified in vendor — so failures still render through `Handler` with the standard 422 shape). The field is optional (`sometimes`) — an empty body passes validation and returns 200 with no changes (code-traced). Only `type` is read; any extra body fields are silently ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `section_type` | path | string (implicit route-model binding via `getRouteKeyName() = 'type'`) | Required | No | Resolved by Laravel before validation; unknown type strings throw `ModelNotFoundException` → `Handler` 404 | An existing `section_types.type` value | None | None | `banner` | Identifies the row to rename, by current type string |
| `type` | body | string | Optional | No | `sometimes\|string\|max:100\|unique:section_types,type,<bound-id>` (self excluded via the BOUND MODEL's id — `$this->route('section_type')->id`; resubmitting the unchanged type passes — code-verified) | Any string ≤100 chars, unique across other rows | Absent (unchanged) | None | `"hero"` | New type discriminator (cascades nowhere automatically — sections referencing the old string keep it; code-traced consequence, untested) |

There are no query parameters, multipart fields, or file uploads.

## Request examples

Valid request renaming a type (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/section-types/banner",
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

## Success: type renamed — HTTP 200

Returned via `ApiResponse::apiResponse(TYPE_UPDATED_SUCCESSFULLY, 200, true, $type)` — `$type` is the RAW updated model (no resource): `{id, type, created_at, updated_at}`. Verified by `test_admin_can_update_section_type` (`assertOk`, `success: true`, `assertDatabaseHas('hero')` + `assertDatabaseMissing('banner')`; uses `putJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.TYPE_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Type updated successfully"`, `ar` → `"تم تحديث النوع بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Raw updated row | `id`, `type` (new value), `created_at`, `updated_at` (exact timestamp format **Unverified in source**) |

Complete success example (`en` locale; `id`/timestamps illustrative):

```json
{
  "status": 200,
  "message": "Type updated successfully",
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

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (all use `putJson`); the 404 path is binding-verified by convention (no update-specific 404 test exists); all other rows are code-verified or code-traced as marked. Note the two different failure envelopes: service exceptions escape to `Handler` (no in-method catch on `update`), while validation renders the 422 shape.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_update_section_type` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-section-types` permission (Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-section-types`; verified by `test_user_without_update_section_types_gets_forbidden` expecting 403 | See 403 example |
| 404 | No `section_types` row has `type = {section_type}`: implicit binding throws `ModelNotFoundException` before validation runs → `Handler` renders its `ModelNotFoundException` branch (takes precedence over 422 — code-verified order) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown type string (code-traced — no update-specific 404 test exists; the settings sibling's 404 test covers the equivalent miss) | See 404 example |
| 405 | `POST` on `/api/v1/section-types/{type}` (no such route is registered; note `GET` is the separate show endpoint and `DELETE` is destroy) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a type segment (code-traced) | See 405 example |
| 422 | Any `UpdateSectionTypeRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `type` → message array (exact messages **Unverified in source** — no update-specific 422 test exists) | Non-string/over-100 `type`, or a value already used by ANOTHER row (self excluded via bound id) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in lookup/update/serialize, e.g. a unique-race between validation and write), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Service failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-section-types` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{section_type}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/section-types/banner`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (duplicate `type` on another row):

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

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

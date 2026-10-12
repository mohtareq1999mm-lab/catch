# Endpoint Name

Sections – Toggle Active (`PATCH /api/v1/sections/{section}/toggle-active`)

# Description

Flips a section's active flag and returns the section with resolved settings.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` |
| Full path | `/api/v1/sections/{section}/toggle-active` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sections/{section}/toggle-active` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group. Registered before `apiResource('sections')`; no capture conflict exists either way (the resource's `PATCH sections/{section}` is a shorter, different URI) |
| Controller action | `Marvel\Http\Controllers\SectionController@toggleStatus` (note the action name differs from the URI verb) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-sections` via controller constructor `permission:update-sections` middleware (applies to `update`, `reorder`, `toggleStatus`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the toggle.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (also selects the resolved `title` string) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `section` | path | integer id (implicit route-model binding by primary key) | Required | No | Resolved by Laravel before the method runs; unknown ids throw `ModelNotFoundException` → `Handler` 404 (the method has no try/catch at all) | Id of an existing section | None | None | `11` | Identifies the section to flip |

There are no query parameters, body fields, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

```json
{
  "method": "PATCH",
  "url": "/api/v1/sections/11/toggle-active",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: flag flipped — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, PagesSectionResource::make($section))` after inverting `is_active`, saving, and `loadMissing('sectionType.settings')`. Verified by `test_admin_can_toggle_section_status` (`assertOk`, `success: true`, `data.is_active === false` after flipping a `true` section; uses `patchJson`, matching this route and this exact URI). There is exactly one success contract. The operation is symmetric: calling it twice restores the original flag (code-traced). Unlike the content-page toggle, `setting` IS included here (code-verified difference).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data updated successfully"`, `ar` → `"تم تحديث البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The flipped section serialized by `App\Http\Resources\Pages\SectionResource` | `id`, `type`, `title`, `is_active` (bool — the NEW value), `endpoint`, `order`, `setting{front, back}` |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Data updated successfully",
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

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified (all use `patchJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_toggle_section_status` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-sections` permission (Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-sections`; verified by `test_user_without_update_sections_gets_forbidden_for_toggle_status` expecting 403 | See 403 example |
| 404 | No section matches `{section}`: implicit binding throws `ModelNotFoundException` before the method runs → `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id (code-traced — no endpoint-specific 404 test exists) | See 404 example |
| 405 | Any HTTP method other than `PATCH` on this exact URI (only `Route::patch` is registered; note `PUT`/`PATCH sections/{id}` without the suffix is the separate update endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the flip-and-save), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

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

405 example (wrong method, e.g. `POST /api/v1/sections/11/toggle-active`):

```json
{
  "message": "Method Not Allowed",
  "status": false
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

# Endpoint Name

Section Types – Update Settings (`POST /api/v1/section-types/{type}/settings`)

# Description

Replaces a type's front/back settings wholesale and returns the grouped result.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/section-types/{type}/settings` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `section-types/{type}/settings` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\SectionTypeController@updateSettings` → `SectionTypeService::upsertSettings` (delete-all-then-recreate per key; explicit string lookup, NO route-model binding) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-section-types` via controller constructor `permission:update-section-types` middleware (applies to `update`, `updateSettings`); the method itself performs no inline check beyond that |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching validation.

## Request inputs

Inline `$request->validate()` runs first (code-verified order): an invalid body yields 422 before the type lookup, so 422 takes precedence over 404 here (code-verified reverse of the update-type ordering). A `ValidationException` is NOT caught (the try starts after validation + lookup) and renders through `Handler` as 422.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `type` | path | string (plain segment — NO binding; passed verbatim to `getByType`) | Required | No | No validation of any kind; unknown values yield the 404 envelope below (after validation passes) | Any string | None | None | `banner` | Type name whose settings are replaced |
| `front` | body | object/array | Optional | Yes (`nullable`) | `nullable\|array` | Any array (falsy values, incl. `[]`, are SKIPPED, not stored — code-verified `if ($front = ... ?? null)` truthiness) | Absent (key ends deleted with nothing recreated) | None | `{"title": "Welcome"}` | New front settings (replaces all existing `front` rows) |
| `back` | body | object/array | Optional | Yes (`nullable`) | `nullable\|array` | Any array (same falsy-skip) | Absent (key ends deleted with nothing recreated) | None | `{"slug": "welcome-slug"}` | New back settings (replaces all existing `back` rows) |

There are no query parameters, multipart fields, or file uploads. Any extra body fields are ignored. Replace semantics (code-verified): ALL existing setting rows for the type are deleted first, then only truthy `front`/`back` payloads are recreated — sending `{}` (or omitting both) WIPES every setting for the type and still returns 200. Sections already carrying own `setting` rows are unaffected (resource prefers own rows over type defaults — code-verified).

## Request examples

Valid request replacing settings (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/section-types/banner/settings",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "front": { "title": "Welcome" },
    "back": { "slug": "welcome-slug" }
  }
}
```

# Responses

## Success: settings replaced — HTTP 200

Returned via `ApiResponse::apiResponse(SETTINGS_UPDATED_SUCCESSFULLY, 200, true, $grouped)` after upsert + explicit frontend cache-tag flush (required because the bulk delete uses a query builder firing no model events — per the code comment). Verified by `test_admin_can_update_type_settings` (`assertOk`, `success: true`, `assertDatabaseHas('section_type_settings', ... 'front'/'back')`; uses `postJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SETTINGS_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Settings updated successfully"`, `ar` → `"تم تحديث الإعدادات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Regrouped settings after the replace | `{front: stored value or [], back: stored value or []}` |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Settings updated successfully",
  "success": true,
  "data": {
    "front": { "title": "Welcome" },
    "back": { "slug": "welcome-slug" }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/404 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked. Note the three different failure envelopes: validation escapes to `Handler` (422 shape), the miss returns the `apiResponse` 404 envelope, and service failures return the 500 envelope.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_update_settings` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-section-types` permission (Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-section-types`; verified by `test_user_without_update_section_types_gets_forbidden_for_settings` expecting 403 | See 403 example |
| 404 | No `section_types` row has `type = {type}` (with a VALID body — validation runs first): explicit `getByType` miss → `apiResponse(NOT_FOUND, 404, false)` envelope (code-verified — translated notice inside the controller envelope, NOT the Handler fixed string) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced) | None | Unknown type string with valid body; verified by `test_update_settings_returns_404_for_nonexistent_type` expecting 404 for `'nonexistent'` | See 404 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on `/api/v1/section-types/{type}/settings` (no such routes are registered; note `GET` is the separate get-settings endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Inline validation failure (`front`/`back` non-array): the `ValidationException` is NOT caught (try starts later) and renders through `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `front` / `back` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no endpoint-specific 422 test exists) | Non-array `front` or `back` (e.g. strings) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any exception inside the try block (`upsertSettings` failure), caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403/422 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Upsert failure (code-traced, no test) | See 500 example |

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

404 example (unknown `{type}` with valid body; `en` locale):

```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/section-types/banner/settings`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (non-array `front`):

```json
{
  "message": "The front must be an array.",
  "status": false,
  "errors": {
    "front": [
      "The front must be an array."
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

500 example (upsert failure inside the try):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

# Endpoint Name

Roles – Create Role (`POST /api/v1/roles`)

# Description

Creates a new `api`-guard role whose URL-safe name is derived from its English display name.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/roles` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/roles` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: roles use explicit routes, not `apiResource`) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@addRole` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-roles` via controller constructor `permission:create-roles` middleware (applies to `addRole`); the method itself performs no inline permission check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` (single body field; no file upload applies) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching validation.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `display_name` | body | object (associative array of locale → string) | Required | No | `required\|array` (inline `$request->validate` in `addRole`; no FormRequest applies) | Any object whose values each satisfy the per-locale rules | None | None | `{"en": "Moderator", "ar": "مشرف"}` | Localized display names stored as role translations |
| `display_name.*` | body (each locale value) | string | Required for every supplied locale | No | `required`, `string`, `UniqueTranslationRule::for('roles', 'display_name')` (each locale value must be unique among stored `roles.display_name` translations) | Any non-empty unique string per locale | None | None | `"Moderator"` | One locale's display name |

Validation runs before any persistence: any rule failure throws `ValidationException`, which the controller rethrows untouched, so `Handler` renders 422. There are no path parameters, query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored: only `display_name` is read.

Name derivation (code-verified): the stored machine `name` is computed as `strtolower(str_replace(' ', '_', $request->display_name['en']))` — e.g. `"Store Manager"` → `"store_manager"` — and `guard_name` is always `'api'`. Edge case (code-traced, no test): the rules require `display_name` to be an array but do not require the `en` key itself, so omitting `en` passes validation and derives an empty-string `name`; a second such call then violates the `roles.name`/`guard_name` uniqueness constraint and falls into the 500 path below.

## Request examples

Valid request creating a role:

```json
{
  "method": "POST",
  "url": "/api/v1/roles",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "display_name": {
      "en": "Moderator",
      "ar": "مشرف"
    }
  }
}
```

# Responses

## Success: role created — HTTP 200

Returned via `ApiResponse::apiResponse(ROLE_ADDED_SUCCESSFULLY, 200, true, RoleResource::make($role))`. There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ROLE_ADDED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Role added successfully"`, `ar` → `"تمت إضافة الدور بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created role serialized by `Marvel\Http\Resources\RoleResource` | See `data` fields below |

`data` fields (`RoleResource::toArray`):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.id` | integer | Always present | New role id | Auto-incremented id |
| `data.name` | string | Always present | Derived machine name | Lowercase `display_name.en` with spaces converted to underscores (e.g. `"moderator"`); verified by `assertDatabaseHas('roles', ['name' => 'moderator', 'guard_name' => 'api'])` |
| `data.display_name` | string | Always present | Raw stored translations JSON (note: string, not object — the resource uses `getRawOriginal('display_name')`) | E.g. `'{"en":"Moderator","ar":"مشرف"}'` (verified by `assertJsonPath('data.display_name', '{"en":"Moderator","ar":"مشرف"}')`) |
| `data.created_at` | string (datetime) | Always present | Creation timestamp | E.g. `"2026-10-10T12:00:00.000000Z"`; exact format **Unverified in source** |
| `data.updated_at` | string (datetime) | Always present | Last-update timestamp | Same format note as `created_at` |
| `data.permissions` | omitted | Always absent for this endpoint | Role permissions | `RoleResource` includes `permissions` only `whenLoaded('permissions')`; `addRole` never loads the relation, so the key is absent |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Role added successfully",
  "success": true,
  "data": {
    "id": 12,
    "name": "moderator",
    "display_name": "{\"en\":\"Moderator\",\"ar\":\"مشرف\"}",
    "created_at": "2026-10-10T12:00:00.000000Z",
    "updated_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

The `id`, `created_at`, and `updated_at` values above are illustrative; every other field value is verified. An internal audit entry is recorded via the role observer (`App\Observers\RoleObserver`), which has no externally observable HTTP effect.

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 422, 500, and 409 contracts below have different bodies and must not be conflated.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_create_role` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `create-roles` permission (Spatie `permission:create-roles` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-roles`; verified by `test_create_role_without_permission_returns_403` expecting 403 | See 403 example |
| 405 | Any HTTP method other than `POST` on the bare `/api/v1/roles` path (only `Route::post('/roles')` is registered there; `GET /roles/{id}`, `PUT /roles/{id}`, `DELETE /roles/{id}` are separate routes requiring an id) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `PUT`, `PATCH`, `DELETE` to `/api/v1/roles` (note: `GET /api/v1/roles` is the separate role-list endpoint, so a bare-path `GET` returns the list, not 405) | See 405 example |
| 409 | Database constraint failure during `save()` (e.g. a `roles.name`/`guard_name` uniqueness violation when two different display names derive the same machine name), rendered by the `QueryException` branch of `Handler`; conditional on database state, not on request input. Note: this path applies only if the `QueryException` escapes the method's generic `catch (\Exception)` — code-traced, see 500 row | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Only when persisting the role violates a database constraint | See 409 example |
| 422 | Any `display_name` validation failure: the `ValidationException` is rethrown untouched (`catch (ValidationException $e) { throw $e; }`) and rendered by the `ValidationException` branch of `Handler`, whose message is `ValidationException::summarize` (first failure message, plus `" (and N more errors)"` when multiple fail) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure; example below is for `display_name: "not-an-array"`, the test-verified trigger) | Map of `display_name` / `display_name.{locale}` → message arrays (key verified from rule targets; full multi-failure map **Unverified in source**) | Missing `display_name`, non-array value (verified by `test_create_role_validates_display_name` expecting 422 for `'not-an-array'`), empty/non-string locale value, or a locale value already used by another role's translations (`UniqueTranslationRule`; exact duplicate message text **Unverified in source** — vendor package internals not inspected) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any non-validation exception inside the `try` block (e.g. translation-storage or database failure during `save()`), caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike every other error on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Post-validation failure (e.g. duplicate derived machine `name` from distinct display names such as `"Moderator"` vs `"moderator"` — code-traced, no test; the QueryException-is-caught-first ordering means the 409 branch above applies only if the exception escapes this catch — code-traced) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-roles` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/roles`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (database constraint failure during `save()`):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

422 example (`display_name` sent as a string instead of an object):

```json
{
  "message": "The display name must be an array.",
  "status": false,
  "errors": {
    "display_name": [
      "The display name must be an array."
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

500 example (post-validation failure inside `addRole`):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

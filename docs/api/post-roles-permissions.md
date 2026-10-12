# Endpoint Name

Roles – Assign Permissions To Role (`POST /api/v1/roles/{roleId}/permissions`)

# Description

Replaces a role's permission set with a new list, restricted to super-admin callers.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/roles/{roleId}/permissions` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/roles/{roleId}/permissions` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: roles use explicit routes, not `apiResource`) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@assignPermissionToRole` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Restricted once: `super-admin` role via controller constructor `role:super-admin` middleware (applies to `assignPermissionToRole` among others) — note this is a role gate, not a permission gate; the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or non-super-admin call fails with 401/403 without reaching validation.

## Request inputs

Validation runs before the role lookup (code-verified order): an invalid body yields 422 even when `{roleId}` does not exist. A `ValidationException` is rethrown untouched and rendered by `Handler` as 422.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `roleId` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the raw value is passed to `Role::findById($roleId, 'api')`, which throws `RoleDoesNotExist` for unknown ids (converted to `MarvelException(NOT_FOUND)` → 404) | Id of an existing `api`-guard role | None | None | `5` | Identifies the role receiving the permissions |
| `permissions` | body | array of integer ids | Required | No | `required\|array` (`required` also rejects an empty array per framework rule semantics — so clearing a role's permissions through this endpoint is impossible) | Array of `api`-guard permission ids | None | None | `[4, 9]` | The complete replacement permission set |
| `permissions.*` | body (each element) | integer id reference | Required for every element | No | `integer`, `distinct`, `Rule::exists('permissions', 'id')->where(guard_name = 'api')` | Id of an existing `api`-guard permission; duplicates rejected | None | None | `4` | One permission in the replacement set |

There are no query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored: only `permissions` is read.

## Request examples

Valid request setting a role's permissions (replaces any previously held permissions — Spatie `syncPermissions` semantics; code-traced):

```json
{
  "method": "POST",
  "url": "/api/v1/roles/5/permissions",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "permissions": [4, 9]
  }
}
```

# Responses

## Success: permissions set — HTTP 200

Returned via `ApiResponse::apiResponse(PERMISSION_ASSIGNED_SUCCESSFULLY, 200, true, RoleResource::make($role))` after `$role->syncPermissions($permissions)` and `->load('permissions')`, so the `permissions` key is always present. Verified by `test_assign_permissions_to_role` (`assertOk`, `success: true`, structure `data: [id, display_name, permissions]`, plus `assertDatabaseHas('role_has_permissions', ...)` proving the grant persisted). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.PERMISSION_ASSIGNED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Permission assigned successfully"`, `ar` → `"تم تعيين الصلاحية بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The role serialized by `RoleResource` with permissions loaded | `id`, `name`, `display_name` (raw JSON string), `created_at`, `updated_at`, `permissions` (`[{id, label}]`, always present here — code-verified via `->load('permissions')`; note: no `guard_name` key since 2026-10-10) |

Complete success example (`en` locale; timestamps illustrative, all other values code-verified):

```json
{
  "status": 200,
  "message": "Permission assigned successfully",
  "success": true,
  "data": {
    "id": 5,
    "name": "editor_role",
    "display_name": "{\"en\":\"Editor Role\",\"ar\":\"دور المحرر\"}",
    "created_at": "2026-10-10T12:00:00.000000Z",
    "updated_at": "2026-10-10T12:00:00.000000Z",
    "permissions": [
      {
        "id": 4,
        "label": "edit-articles"
      },
      {
        "id": 9,
        "label": "publish-articles"
      }
    ]
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/404/422 contracts are test-verified (all four tests correctly use `postJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no endpoint-specific 401 test exists; identical rejection test-verified on sibling roles routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller without the `super-admin` role (Spatie `role:super-admin` middleware rejects before validation runs, so even a malformed body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_ROLES` via `Handler::messageForSpatieUnauthorized` (role branch; no role-name suffix: `display_role_in_exception` is `false`): `en` → `"User does not have the required roles."`, `ar` → `"لا يمتلك المستخدم الأدوار المطلوبة."` (note: "roles", not "permissions" — this gate checks the caller's role; no endpoint-specific 403 test exists — code-traced through the shared path) | None | Valid token + caller lacking the `super-admin` role | See 403 example |
| 404 | No `api`-guard role matches `{roleId}` (with a valid body): `Role::findById($roleId, 'api')` throws `RoleDoesNotExist`, which the method converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced — the test asserts status only) | None | Nonexistent `{roleId}` with a valid `permissions` body; verified by `test_assign_permissions_to_nonexistent_role_returns_error` expecting 404 for id `99999` | See 404 example |
| 405 | Any HTTP method other than `POST` on `/api/v1/roles/{roleId}/permissions` (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 422 | Any `permissions` validation failure (runs before the role lookup, so it takes precedence over 404): the `ValidationException` is rethrown untouched and rendered by `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `permissions` / `permissions.{index}` → message arrays (key verified from rule targets; exact messages **Unverified in source**) | Missing or non-array `permissions`, empty array, non-integer element (verified by `test_assign_permissions_validates_integer_input` for `['not-an-integer']`), duplicate element, or id of a nonexistent / non-`api`-guard permission (verified by `test_assign_permissions_validates_ids_exist` for `[99999]`) — both expecting 422 | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any other exception inside the `try` block, caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403/404 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Post-validation internal failure (e.g. `syncPermissions` failure — code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated caller without the `super-admin` role; `en` locale):

```json
{
  "message": "User does not have the required roles.",
  "status": false
}
```

404 example (nonexistent `{roleId}` with a valid body; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/roles/5/permissions`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (unknown permission id; message shape per file-verified `validation.php` `exists` text):

```json
{
  "message": "The selected permissions.0 is invalid.",
  "status": false,
  "errors": {
    "permissions.0": [
      "The selected permissions.0 is invalid."
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

500 example (post-validation internal failure):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

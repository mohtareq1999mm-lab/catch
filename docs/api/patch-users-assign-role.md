# Endpoint Name

User – Assign Role (`PATCH /api/v1/users/{userId}/assign-role`)

# Description

Replaces the roles of a non-customer user with the given api-guard roles and returns the updated user.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` (changed from `POST` on 2026-10-10; the pre-existing tests still call it with `postJson` and therefore currently receive 405 — flagged, not fixed, per docs-only scope) |
| Full path | `/api/v1/users/{userId}/assign-role` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `users/{userId}/assign-role` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@assignRole` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required. `assign-role` via controller constructor `permission:assign-role` middleware (applies only to `assignRole`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` (body fields only; no file upload applies) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |
| Route constraint on `{userId}` | None (no `whereNumber`): any value, including non-numeric strings, reaches the controller and resolves via `User::findOrFail($userId)` |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching validation.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `userId` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists for the path value; it is passed to `User::findOrFail($userId)` under the `SoftDeletes` scope, so trashed rows and unknown ids throw `ModelNotFoundException`, which this method's `catch (\Exception)` converts to a 500 response (not 404) | Id of an existing active user row whose `type` is not `'user'` | None | None | `27` | Identifies the user whose roles are replaced |
| `role_ids` | body | array | Required | No (`required`, not `nullable`; an explicit `null` fails) | `required\|array` via inline `$request->validate()`; must be present and non-empty — an empty array fails `required` | Non-empty array of role ids | None | Each element is validated by `role_ids.*` | `[3]` | Complete replacement set of roles (sync semantics: existing roles not in the list are removed) |
| `role_ids.*` | body | integer id reference | Required for every element | No | `Rule::exists('roles', 'id')->where(fn ($q) => $q->where('guard_name', 'api'))` — each item must match an existing `roles.id` row with `guard_name = 'api'`; there is no `distinct` rule, so duplicates are accepted | Ids of existing `api`-guard roles | None | Applies to every element of `role_ids` | `3` | One role id to assign |

There are no query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored: only `role_ids` is read. The replacement is a sync: roles currently held but absent from `role_ids` are detached (`User::syncRoles`, which also fires the internal `UserRolesUpdated` event with no HTTP effect).

## Request examples

Valid request assigning one role (target must have `type` other than `'user'`):

```json
{
  "method": "PATCH",
  "url": "/api/v1/users/27/assign-role",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "role_ids": [3]
  }
}
```

# Responses

## Success: roles replaced — HTTP 200

Returned via `ApiResponse::apiResponse(ROLE_ASSIGNED_SUCCESSFULLY, 200, true, UserResource::make($user))` after `$user->syncRoles($roles)->load('roles','permissions')`. There is exactly one success contract.

Verification note: no passing runtime observation of this body exists — the repository's success test (`test_assign_role_to_user`) targets a customer whose `type` defaults to `'user'` and currently fails with 400 against this code (confirmed by a live test run; see Errors). The contract below is therefore built strictly from the inspected response path (`UserResource` with `roles` and `permissions` loaded).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ROLE_ASSIGNED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Role assigned successfully"`, `ar` → `"تم تعيين الدور بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | The updated user as a Marvel `UserResource` | Object described below |
| `data.id` | integer | Always present | User primary key | Echo of `{userId}` |
| `data.name` | string | Always present | Stored display name | As stored |
| `data.email` | string | Always present | Stored email | As stored |
| `data.email_verified_at` | string (datetime) or null | Always present | Verification timestamp as stored | Timestamp or `null` |
| `data.is_active` | integer/boolean | Always present | Stored activation flag | `0` or `1` |
| `data.image` | string (URL) or null | Always present | Public URL of the first `user-image` media item | URL string or `null` |
| `data.type` | string | Always present | User discriminator as stored | Never `'user'` on success (that case is rejected with 400); e.g. `"admin"` |
| `data.phone_number` | string or null | Always present | Stored phone number | Value or `null` |
| `data.created_at` | string (datetime) | Always present | Creation timestamp | Timestamp value |
| `data.updated_at` | string (datetime) | Always present | Last-update timestamp | Timestamp value |
| `data.roles` | array of objects | Always present; reflects the new set (empty only if an empty set could be synced — but `[]` fails validation, so in practice non-empty) | Assigned roles after sync | Each item is a `RoleResource` (`id`, `name`, `display_name`, `created_at`, `updated_at` — note: `guard_name` was removed from `RoleResource` on 2026-10-10); nested `permissions` is omitted because `roles.permissions` is never eager-loaded here |
| `data.permissions` | array of objects | Always present; may be empty when the new roles grant no permissions | Effective permissions via `getPermissionsViaRoles()` | Each item is a `PermissionResource`: `{id: integer, label: string}` where `label` is the `permissions.{name}` translation when it exists, else the raw permission name |
| `data.address` | omitted | Always absent for this endpoint | Customer addresses | `whenLoaded('address')` and `address` is never loaded here |

Fields never exposed: `password` and `remember_token` (hidden via `User::$hidden`).

Complete success example (constructed from the verified response path; no passing live observation exists for the reason stated above):

```json
{
  "status": 200,
  "message": "Role assigned successfully",
  "success": true,
  "data": {
    "id": 27,
    "name": "Store Manager",
    "email": "manager@example.com",
    "email_verified_at": "2026-10-09T10:00:00.000000Z",
    "is_active": 1,
    "image": null,
    "type": "admin",
    "phone_number": "01000000077",
    "created_at": "2026-10-09T10:00:00.000000Z",
    "updated_at": "2026-10-10T11:00:00.000000Z",
    "roles": [
      {
        "id": 3,
        "name": "mod_role",
        "display_name": "Mod Role",
        "created_at": "2026-10-09T10:00:00.000000Z",
        "updated_at": "2026-10-10T11:00:00.000000Z"
      }
    ],
    "permissions": [
      {
        "id": 5,
        "label": "create-user"
      }
    ]
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. Note the deliberate contract of this method: lookup failures (`ModelNotFoundException`) are caught by `catch (\Exception)` and returned as 500 via `apiResponse(SOMETHING_WENT_WRONG, 500, false)` — never 404.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Target user's `type` is exactly `'user'` (strict `===`), returned directly via `apiResponse(CANNOT_ASSIGN_ROLE_TO_USER, 400, false)` with HTTP 400 | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `ERROR.CANNOT_ASSIGN_ROLE_TO_USER`: `en` → `"Cannot assign role to this user"`, `ar` → `"لا يمكن تعيين دور للمستخدم"` | None | Caller authenticated with `assign-role` permission; `{userId}` resolves to a row with `type === 'user'` (the database default). Runtime-verified: a live run assigning to such a user returns 400, and the repository test asserting 200 for that case currently fails | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success/400/500 envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; code-verified via middleware (endpoint-specific 401 test exists only for sibling role endpoints, asserting 401) | See 401 example |
| 403 | Authenticated caller lacks the `assign-role` permission (Spatie `permission:assign-role` middleware rejects before validation runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `assign-role`; runtime-verified: `test_assign_role_without_permission_returns_403` passes (note: that test still calls with `postJson` and therefore currently receives 405 instead — flagged, not fixed, per docs-only scope) | See 403 example |
| 405 | Any HTTP method other than `PATCH` on `/api/v1/users/{userId}/assign-role` (only `Route::patch` is registered since 2026-10-10) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `DELETE` to the same URI — including the pre-existing tests, which still use `postJson` (flagged, not fixed, per docs-only scope) | See 405 example |
| 422 | Inline validation failure (`role_ids` required array; each item must exist in `roles.id` with `guard_name = 'api'`; `ValidationException` is rethrown and rendered with an `errors` map) | `{message: string, status: boolean, errors: object}` where `errors` maps field → string array | None | Validator message in the active `lang` locale | Exact structure `{role_ids: [...], role_ids.0: [...]}`; multiple messages possible | Missing, null, non-array, or empty-array `role_ids` (`required\|array`); item id with no matching `roles` row (runtime-verified: `test_assign_role_validates_role_ids_exist` passes for `[99999]`); item whose role has a different guard | See 422 examples |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Lookup or processing failure: `User::findOrFail($userId)` throws `ModelNotFoundException` for unknown, soft-deleted (scope-excluded), or non-numeric ids, and the method's `catch (\Exception)` returns `apiResponse(SOMETHING_WENT_WRONG, 500, false)` with HTTP 500; the same contract covers any other exception (e.g. role-query or `syncRoles` failure) | `{status: integer, message: string, success: boolean}`, no `data` key (controller envelope, not the Handler shape) | None | Translated `ERROR.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Unknown id (runtime-verified: `test_assign_role_to_nonexistent_user_returns_error` passes for id `99999`); soft-deleted or non-numeric `{userId}` follow the identical `findOrFail` → `catch (\Exception)` lines (code-traced, not live-run) | See 500 example |

400 example (customer-type target; `en` locale):

```json
{
  "status": 400,
  "message": "Cannot assign role to this user",
  "success": false
}
```

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `assign-role` permission):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/users/27/assign-role` — now including the stale test calls):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `role_ids`):

```json
{
  "message": "The role ids field is required.",
  "status": false,
  "errors": {
    "role_ids": ["The role ids field is required."]
  }
}
```

422 example (nonexistent role id):

```json
{
  "message": "The selected role_ids.0 is invalid.",
  "status": false,
  "errors": {
    "role_ids.0": ["The selected role_ids.0 is invalid."]
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

500 example (unknown `{userId}`; `en` locale):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

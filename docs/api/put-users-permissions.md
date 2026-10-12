# Endpoint Name

Users – Sync Direct Permissions (`PUT /api/v1/users/{userId}/permissions`)

# Description

Replaces a user's direct permissions with a new set, restricted to super-admin callers.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/users/{userId}/permissions` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/users/{userId}/permissions` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: no `apiResource` competes for this URI) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@syncPermissions` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Restricted once: `super-admin` role via controller constructor `role:super-admin` middleware (applies to `syncPermissions` among others) — note this is a role gate, not a permission gate; the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or non-super-admin call fails with 401/403 without reaching validation.

## Request inputs

Validation runs before the user lookup (code-verified order in `syncPermissions`): an invalid body yields 422 even when `{userId}` does not exist. A `ValidationException` is rethrown untouched and rendered by `Handler` as 422.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `userId` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the raw value is passed to `User::findOrFail($userId)` under the `SoftDeletes` scope, throwing `ModelNotFoundException` for unknown ids (converted to `MarvelException(NOT_FOUND)` → 404) | Id of an existing non-trashed user | None | None | `27` | Identifies the user whose permissions are replaced |
| `permissions` | body | array of integer ids | Required | No | `required\|array` (`required` also rejects an empty array per framework rule semantics — so clearing all direct permissions through this endpoint is impossible; use the remove endpoint instead) | Array of `api`-guard permission ids | None | None | `[9]` | The complete replacement set of direct permissions |
| `permissions.*` | body (each element) | integer id reference | Required for every element | No | `integer`, `distinct`, `Rule::exists('permissions', 'id')->where(guard_name = 'api')` | Id of an existing `api`-guard permission; duplicates rejected | None | None | `9` | One permission in the replacement set |

There are no query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored: only `permissions` is read.

## Request examples

Valid request replacing direct permissions (revokes everything not listed — verified: `sync-perm-1` revoked, `sync-perm-2` granted):

```json
{
  "method": "PUT",
  "url": "/api/v1/users/27/permissions",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "permissions": [9]
  }
}
```

# Responses

## Success: permissions replaced — HTTP 200

Returned via `ApiResponse::apiResponse(PERMISSION_ASSIGNED_SUCCESSFULLY, 200, true, UserResource::make($user))` after `$user->syncPermissions($permissions)` and `->load('roles', 'permissions')`. Verified by `test_sync_permissions_on_user` (`assertOk`, `success: true`, `assertFalse(hasPermissionTo('sync-perm-1'))` + `assertTrue(hasPermissionTo('sync-perm-2'))` proving replacement, not addition). There is exactly one success contract. Note the message says "assigned" even though the operation is a replacement (code-verified constant reuse).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.PERMISSION_ASSIGNED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Permission assigned successfully"`, `ar` → `"تم تعيين الصلاحية بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The user serialized by `Marvel\Http\Resources\UserResource` with `roles` loaded | See `data` fields below |

`data` fields (`UserResource::toArray`):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.id` | integer | Always present | User id | The `{userId}` value |
| `data.name` | string | Always present | User name | Stored name |
| `data.email` | string | Always present | User email | Stored email |
| `data.email_verified_at` | string (datetime) or null | Always present | Verification timestamp | Null when unverified; exact format **Unverified in source** |
| `data.is_active` | boolean/integer | Always present | Active flag | Stored `is_active` |
| `data.image` | string or null | Always present | Avatar URL | First `user-image` media URL, else null |
| `data.type` | string | Always present | User type | E.g. `"user"`, `"admin"` |
| `data.phone_number` | string or null | Always present | Phone | Stored phone |
| `data.created_at` | string (datetime) | Always present | Creation timestamp | Exact format **Unverified in source** |
| `data.updated_at` | string (datetime) | Always present | Last-update timestamp | Exact format **Unverified in source** |
| `data.roles` | array of objects | Always present for this endpoint (possibly empty) | Assigned roles (`RoleResource` collection; present because `syncPermissions` loads the relation). Each item carries `id`, `name`, `display_name` (raw JSON string), timestamps — but no `guard_name` (removed from `RoleResource` on 2026-10-10) and no nested `permissions` key, since each role's own permissions relation is not loaded | E.g. `{"id": 3, "name": "editor", ...}` |
| `data.permissions` | array of objects | Always present for this endpoint | Permissions serialized by `PermissionResource` — note it uses `getPermissionsViaRoles()`, i.e. role-derived permissions only; the synced direct permissions are NOT surfaced in this array (code-verified serialization nuance) | Each item: `{id: integer, label: string}` |
| `data.address` | omitted | Always absent for this endpoint | User address | Included only `whenLoaded('address')`; never loaded here |

Complete success example (`en` locale; timestamps illustrative, all other values code-verified):

```json
{
  "status": 200,
  "message": "Permission assigned successfully",
  "success": true,
  "data": {
    "id": 27,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "email_verified_at": null,
    "is_active": true,
    "image": null,
    "type": "user",
    "phone_number": null,
    "created_at": "2026-10-10T12:00:00.000000Z",
    "updated_at": "2026-10-10T12:00:00.000000Z",
    "roles": [],
    "permissions": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200 contract is test-verified (`test_sync_permissions_on_user`, which correctly uses `putJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no endpoint-specific 401 test exists; identical rejection test-verified on sibling roles routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller without the `super-admin` role (Spatie `role:super-admin` middleware rejects before validation runs, so even a malformed body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_ROLES` via `Handler::messageForSpatieUnauthorized` (role branch; no role-name suffix: `display_role_in_exception` is `false`): `en` → `"User does not have the required roles."`, `ar` → `"لا يمتلك المستخدم الأدوار المطلوبة."` (note: "roles", not "permissions" — this gate checks the caller's role; no endpoint-specific 403 test exists — code-traced through the shared path) | None | Valid token + caller lacking the `super-admin` role | See 403 example |
| 404 | No non-trashed user matches `{userId}` (with a valid body): `User::findOrFail($userId)` throws `ModelNotFoundException`, which `syncPermissions` converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced — no endpoint-specific test) | None | Nonexistent or soft-deleted `{userId}` with a valid `permissions` body | See 404 example |
| 405 | `POST` or `PATCH` on `/api/v1/users/{userId}/permissions` (registered to the sibling give/remove endpoints' sibling URIs — `POST` maps to give-permission and `DELETE` to remove-permission on this exact URI, so those do not 405; truly unregistered methods such as `PATCH` do) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Unregistered method on the exact URI, e.g. `PATCH` (code-traced) | See 405 example |
| 422 | Any `permissions` validation failure (runs before the user lookup, so it takes precedence over 404): the `ValidationException` is rethrown untouched and rendered by `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure; the identical rule set is test-verified to 422 on the sibling give-permission endpoint for unknown ids) | Map of `permissions` / `permissions.{index}` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no endpoint-specific 422 test exists) | Missing or non-array `permissions`, empty array (`required` rejects it), non-integer element, duplicate element (`distinct`), or id of a nonexistent / non-`api`-guard permission | See 422 example |
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

404 example (nonexistent `{userId}` with a valid body; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `PATCH /api/v1/users/27/permissions`):

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

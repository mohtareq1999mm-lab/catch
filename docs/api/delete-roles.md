# Endpoint Name

Roles – Delete Role (`DELETE /api/v1/roles/{id}`)

# Description

Permanently hard-deletes a role, refusing roles that still have assigned users.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/roles/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/roles/{id}` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: roles use explicit routes, not `apiResource`) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@destroyRole` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-roles` via controller constructor `permission:delete-roles` middleware (applies to `destroyRole`); the method itself performs no inline permission check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the lookup.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the raw value is passed to `Role::findById($id, 'api')`, which throws `RoleDoesNotExist` for unknown ids (converted to `MarvelException(NOT_FOUND)` → 404) | Id of an existing `api`-guard role | None | None | `9` | Identifies the role to delete |

There are no query parameters, body fields, multipart fields, or file uploads for this endpoint. A non-numeric `{id}` fails the same guard-scoped lookup and yields 404 (code-traced). There is no `422` contract.

## Request examples

Valid request deleting a role:

```json
{
  "method": "DELETE",
  "url": "/api/v1/roles/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: role hard-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(ROLE_DELETED_SUCCESSFULLY, 200, true, null)` — `null` data is empty, so the `data` key is absent. Verified by `test_super_admin_can_delete_role` (`assertOk` + `assertJsonPath('success', true)` + `assertDatabaseMissing('roles', ['id' => ...])`). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ROLE_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Role deleted successfully"`, `ar` → `"تم حذف الدور بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Observable effect (code- and test-verified): the row is permanently removed — roles are hard-deleted, not soft-deleted (`assertDatabaseMissing`, no `deleted_at` semantics apply). An internal audit entry is recorded via the role observer (`App\Observers\RoleObserver`), which has no externally observable HTTP effect.

Complete success example:

```json
{
  "status": 200,
  "message": "Role deleted successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint. The two 409 paths below return the identical body by design.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token (no delete-specific 401 test exists; the identical `auth:sanctum` rejection is test-verified on sibling roles routes, e.g. `test_unauthenticated_user_cannot_access_roles` expecting 401 on `GET /roles`) | See 401 example |
| 403 | Authenticated caller lacks the `delete-roles` permission (Spatie `permission:delete-roles` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-roles` (no delete-specific 403 test exists; the identical middleware/Handler path is test-verified on `POST /users/{userId}/assign-role` expecting 403) | See 403 example |
| 404 | No `api`-guard role matches `{id}`: `Role::findById($id, 'api')` throws `RoleDoesNotExist` (or `ModelNotFoundException`), which `destroyRole` converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced — the test asserts status only) | None | Nonexistent id (verified by `test_delete_nonexistent_role_returns_error` expecting 404 for id `99999`); non-numeric `{id}` (code-traced through the same guard-scoped lookup) | See 404 example |
| 405 | `POST` or `PATCH` on `/api/v1/roles/{id}` (no such routes are registered; note `GET /roles/{id}` is the separate show endpoint and `PUT /roles/{id}` is the separate update endpoint, so those do not 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`PATCH` with an id segment (code-traced) | See 405 example |
| 409 | The role still has assigned users: `$role->users()->count() > 0` returns the conflict response before any deletion; the role row is kept (verified by `assertDatabaseHas('roles', ['id' => $role->id])`) | `{status: integer, message: string, success: boolean}` (note: `apiResponse` envelope with integer `status` + `success` flag, not the Handler shape) | None | Translated `ERROR.CANNOT_DELETE_ROLE_WITH_ASSIGNED_USERS` (note the `ERROR` domain, resolved by the same `translateNotice`): `en` → `"Cannot delete role with assigned users"`, `ar` → `"لا يمكن حذف الدور مع وجود مستخدمين مرتبطين به"` | None | Role has ≥1 assigned user; verified by `test_delete_role_with_assigned_users_returns_conflict` (`assertStatus(409)` + `assertJsonPath('success', false)`) | See 409 example |
| 409 | A `QueryException` during lookup/deletion (e.g. a foreign-key race where assignments appear between the count check and `delete()`), caught by the dedicated `catch (\Illuminate\Database\QueryException)` which returns the same conflict body as above | Same `apiResponse` envelope as above | None | Same translated message as above | None | Database-level referential failure (code-traced, no test) | See 409 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any other exception inside the `try` block, caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403/404 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Post-lookup internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `delete-roles` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (nonexistent or non-numeric `{id}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/roles/9`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (role has assigned users; `en` locale):

```json
{
  "status": 409,
  "message": "Cannot delete role with assigned users",
  "success": false
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

500 example (post-lookup internal failure):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

# Endpoint Name

Admin – Delete User Forever (`DELETE /api/v1/admin-users/delete-forever/{id}`)

# Description

Permanently removes a user record, including soft-deleted ones, unless the target is a super admin or the caller themselves.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin-users/delete-forever/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `admin-users/delete-forever/{id}` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\UserController@adminDeleteUsersForever` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required. `delete-user` via controller constructor `permission:delete-user` middleware (applies to `adminDeleteUsers`, `adminDeleteUsersForever`, and `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read. No `Content-Type` is required. Any body or query string sent is ignored: the method signature takes only the `$id` route parameter and never reads the request object |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |
| Route constraint on `{id}` | None (no `whereNumber`): any value, including non-numeric strings, reaches the controller and resolves via `User::withTrashed()->findOrFail($id)` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the value is passed to `User::withTrashed()->findOrFail($id)`, which searches active and soft-deleted rows and throws `ModelNotFoundException` when nothing matches | Id of an existing user row (active or soft-deleted), except a super-admin id or the caller's own id (those are rejected with 400, not deleted) | None | None | `27` | Identifies the user to permanently delete |

There are no query parameters, JSON body fields, multipart fields, or file uploads for this endpoint. The lookup uses `withTrashed()`, so both active and already soft-deleted users are found; unlike the restore endpoint there is no `trashed()` precondition, so an active user is permanently deleted directly without being soft-deleted first.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin-users/delete-forever/27",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: user permanently deleted — HTTP 200

Returned via `ApiResponse::apiResponse(USER_DELETED_SUCCESSFULLY, 200, true)` with no data payload, so the `data` key is absent (`apiResponse` adds `data` only when non-empty). There is exactly one success contract; it is identical whether the target was active or already soft-deleted.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.USER_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"User deleted successfully"`, `ar` → `"تم حذف المستخدم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Observable effect (code-verified): the `users` row is permanently removed (`forceDelete`), verified by `assertDatabaseMissing('users', ['id' => $target->id])`; attached media rows/files are deleted via `MediaCleanupObserver::forceDeleting`; an internal audit entry is recorded via `UserObserver::forceDeleted`. Note the code-verified difference from the soft-delete endpoint (`adminDeleteUsers`): this method issues no `$user->tokens()->delete()` call, so no token revocation step runs here; downstream token-row cleanup is **Unverified in source**.

Complete success example:

```json
{
  "status": 200,
  "message": "User deleted successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no endpoint-specific validation (no FormRequest), so there is no `422` contract and no `errors` map for this endpoint.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Target user has the `super_admin` role (`$user->hasRole('super_admin')`), returned directly via `apiResponse(USER_ADMIN_CANNOT_BE_DELETED, 400, false)` with HTTP 400 | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `ERROR.USER_ADMIN_CANNOT_BE_DELETED`: `en` → `"User cannot be deleted"`, `ar` → `"لا يمكن حذف المستخدم اذي كان أدمن من فضلك تأكد من الادوار"` | None | Caller authenticated with `delete-user` permission; target found (active or trashed) and holds the `super_admin` role; verified by `test_force_delete_fails_for_super_admin` expecting 400 | See 400 example |
| 400 | Caller attempts to delete their own account (`$user->id === auth()->id()`), same response contract as above | `{status: integer, message: string, success: boolean}`, no `data` key | None | Same `ERROR.USER_ADMIN_CANNOT_BE_DELETED` message as above | None | Caller authenticated; `{id}` equals the caller's own user id | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success/400 envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_force_delete_fails_for_unauthenticated_user` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `delete-user` permission (Spatie `permission:delete-user` middleware rejects before the controller runs) | `{message: string, status: boolean}` | None | Spatie `UnauthorizedException` message rendered by `Handler::messageForSpatieUnauthorized` (locale-aware) | None | Valid token + user without `delete-user`; code-verified via middleware registration and Handler branch (no endpoint-specific 403 test exists) | See 403 example |
| 404 | No user row (active or soft-deleted) matches `{id}`: `User::withTrashed()->findOrFail($id)` throws `ModelNotFoundException`, which the controller does not catch (it catches only `MarvelException`), so `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string from the `ModelNotFoundException` branch; not the translated `ERROR.NOT_FOUND`) | None | Nonexistent id (verified by `test_force_delete_fails_for_nonexistent_user` expecting 404 for id `99999`); also any non-numeric `{id}`, since the route has no numeric constraint and the lookup then matches nothing | See 404 example |
| 405 | Any HTTP method other than `DELETE` on `/api/v1/admin-users/delete-forever/{id}` (only `Route::delete` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `PATCH` to the same URI | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 409 | Database constraint failure during `forceDelete` (e.g. a foreign-key violation from dependent rows), rendered by the `QueryException` branch of `Handler`; conditional on database state, not on request input | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Only when the delete violates a database constraint; dependent-row and cascade behavior is otherwise **Unverified in source** | See 409 example |
| 500 | Unexpected failure after routing (any exception not matched above), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-lookup failure (e.g. observer/media-cleanup failure); the controller's `catch (MarvelException)` → `throw new MarvelException(NOT_FOUND)` path (which would render 404 `"Not found"`/`"غير موجود"`) is present in code but effectively unreachable for the lookup path, since `findOrFail` throws `ModelNotFoundException`, not `MarvelException` | See 500 example |

400 example (super-admin target or self-deletion; `en` locale):

```json
{
  "status": 400,
  "message": "User cannot be deleted",
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

403 example (authenticated without `delete-user` permission):

```json
{
  "message": "User does not have the right permissions.",
  "status": false
}
```

404 example (nonexistent or non-matching `{id}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/admin-users/delete-forever/27`):

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

409 example (database constraint failure during `forceDelete`):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

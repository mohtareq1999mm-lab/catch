# Endpoint Name

Admin – Delete User (`DELETE /api/v1/admin-users/delete/{id}`)

# Description

Soft-deletes a user and revokes their API tokens, unless the target is a super admin or the caller themselves.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin-users/delete/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `admin-users/delete/{id}` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\UserController@adminDeleteUsers` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required. `delete-user` via controller constructor `permission:delete-user` middleware (applies to `adminDeleteUsers`, `adminDeleteUsersForever`, and `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read. No `Content-Type` is required. Any body or query string sent is ignored: the method signature takes only the `$id` route parameter and never reads the request object |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |
| Route constraint on `{id}` | None (no `whereNumber`): any value, including non-numeric strings, reaches the controller and resolves via the repository lookup |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the value is passed to `$this->repository->findOrFail($id)`, which forwards to the Eloquent model (Prettus `BaseRepository::__call`) with the `SoftDeletes` scope applied, throwing `ModelNotFoundException` when nothing matches | Id of an existing active (non-trashed) user row, except a super-admin id or the caller's own id (those are rejected with 400, not deleted) | None | None | `27` | Identifies the user to soft-delete |

There are no query parameters, JSON body fields, multipart fields, or file uploads for this endpoint. Because the lookup carries the `SoftDeletes` scope, an already soft-deleted id is not found and yields 404 — unlike the force-delete endpoint, which uses `withTrashed()` (code-verified difference).

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin-users/delete/27",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: user soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(USER_DELETED_SUCCESSFULLY, 200, true)` with no data payload, so the `data` key is absent (`apiResponse` adds `data` only when non-empty). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.USER_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"User deleted successfully"`, `ar` → `"تم حذف المستخدم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Observable effect (code-verified): the target's API tokens are revoked first (`$user->tokens()->delete()`), then the `users` row is soft-deleted (`$user->delete()` sets `deleted_at`), verified by `assertSoftDeleted('users', ['id' => $target->id])`. Attached media is kept: `MediaCleanupObserver::deleting` returns early for models using `SoftDeletes`, so no media cleanup runs here (unlike the force-delete endpoint). An internal audit entry is recorded via `UserObserver::deleted`, which has no externally observable HTTP effect.

Complete success example:

```json
{
  "status": 200,
  "message": "User deleted successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no endpoint-specific validation (no FormRequest, no `$request->validate()` call), so there is no `422` contract and no `errors` map for this endpoint.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Target user has the `super_admin` role (`$user->hasRole('super_admin')`), returned directly via `apiResponse(USER_ADMIN_CANNOT_BE_DELETED, 400, false)` with HTTP 400 | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `ERROR.USER_ADMIN_CANNOT_BE_DELETED`: `en` → `"User cannot be deleted"`, `ar` → `"لا يمكن حذف المستخدم اذي كان أدمن من فضلك تأكد من الادوار"` | None | Caller authenticated with `delete-user` permission; target found and holds the `super_admin` role; verified by `test_cannot_delete_admin_user` expecting 400 and `"User cannot be deleted"` | See 400 example |
| 400 | Caller attempts to delete their own account (`$user->id === auth()->id()`), same response contract as above | `{status: integer, message: string, success: boolean}`, no `data` key | None | Same `ERROR.USER_ADMIN_CANNOT_BE_DELETED` message as above | None | Caller authenticated; `{id}` equals the caller's own user id; verified by `test_cannot_delete_self` expecting 400 and `"User cannot be deleted"` | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success/400 envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_admin_endpoints` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `delete-user` permission (Spatie `permission:delete-user` middleware rejects before the controller runs) | `{message: string, status: boolean}` | None | Spatie `UnauthorizedException` message rendered by `Handler::messageForSpatieUnauthorized` (locale-aware) | None | Valid token + user without `delete-user`; verified by `test_regular_user_cannot_access_admin_endpoints` expecting 403 | See 403 example |
| 404 | No active user row matches `{id}`: the repository lookup forwards to the Eloquent model with the `SoftDeletes` scope applied and throws `ModelNotFoundException`, which the controller does not catch (it catches only `MarvelException`), so `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string from the `ModelNotFoundException` branch; not the translated `ERROR.NOT_FOUND`) | None | Nonexistent id (verified by `test_delete_fails_for_nonexistent_user` expecting 404 for id `99999`); also an already soft-deleted id (excluded by the `SoftDeletes` scope — code-verified difference from the force-delete endpoint) and any non-numeric `{id}`, since the route has no numeric constraint and the lookup then matches nothing | See 404 example |
| 405 | Any HTTP method other than `DELETE` on `/api/v1/admin-users/delete/{id}` (only `Route::delete` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `PATCH` to the same URI | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception not matched above), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-lookup failure (e.g. database failure during token revocation or `delete()`); the controller's `catch (MarvelException)` → `throw new MarvelException(NOT_FOUND)` path (which would render 404 `"Not found"`/`"غير موجود"`) is present in code but effectively unreachable for the lookup path, since the lookup throws `ModelNotFoundException`, not `MarvelException` | See 500 example |

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

404 example (nonexistent, already-trashed, or non-matching `{id}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/admin-users/delete/27`):

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
